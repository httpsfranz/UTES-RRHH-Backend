<?php

namespace App\Services\Dashboard;

use App\Models\Seguridad\Usuario;
use App\Services\AutomatizacionService;
use App\Support\AccesoUsuario;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Panel de inicio. Esta aislado a proposito: nada del resto del
 * sistema depende de el, solo lo invoca DashboardController. Para cambiarlo basta con reescribir esta clase y, en el
 * frontend, la carpeta src/dashboard.
 *
 * El panel depende del ROL (RIT: la Unidad Funcional de RRHH ve toda la Red; el jefe de microred y el responsable del
 * establecimiento, su ambito; el programador, la planificacion de turnos; el trabajador, solo lo suyo; porteria, el dia):
 *
 *   perfil   ADMIN/RRHH_RED -> red | JEFE_MICRORED -> microred | RESP_EESS -> eess | PROGRAMADOR -> programacion
 *            PORTERIA -> porteria | TRABAJADOR (o cualquiera con vinculo propio) -> personal
 *
 * Responde con una lista de WIDGETS que el frontend dibuja segun su tipo, sin conocer el perfil:
 *   { id, tipo: kpis|dona|barras|lineas|lista, titulo, subtitulo?, ancho: 1|2, ...datos }
 * Todo se calcula con consultas agregadas (sin traer filas) y limitado al alcance de quien consulta.
 */
class DashboardService
{
    /** Orden de prioridad de los perfiles y roles que los dan. */
    private const PERFILES = [
        'red' => ['ADMIN', 'RRHH_RED'],
        'microred' => ['JEFE_MICRORED'],
        'eess' => ['RESP_EESS'],
        'programacion' => ['PROGRAMADOR'],
        'porteria' => ['PORTERIA'],
        'personal' => ['TRABAJADOR'],
    ];

    private const NOMBRES_PERFIL = [
        'red' => 'Toda la Red',
        'microred' => 'Mi microred',
        'eess' => 'Mi establecimiento',
        'programacion' => 'Programación de turnos',
        'porteria' => 'Portería',
        'personal' => 'Mi asistencia',
    ];

    private const ESTADOS_EFECTIVOS = "'ASISTIO','TARDANZA','SALIDA_ANTIC','COMISION','CAPACITACION'";

    private const ESTADOS_AUTORIZADOS = "'PAPELETA','LICENCIA','DESCANSO_MED','VACACIONES'";

    private const TONO_ESTADO = [
        'ASISTIO' => 'verde', 'TARDANZA' => 'ambar', 'SALIDA_ANTIC' => 'naranja', 'FALTA' => 'rojo', 'FALTA_JUST' => 'azul', 'OMISION_MARCA' => 'rojo',
        'FALTA_GUARDIA' => 'rojo', 'PAPELETA' => 'turquesa', 'COMISION' => 'indigo', 'LICENCIA' => 'indigo', 'DESCANSO_MED' => 'azul', 'VACACIONES' => 'turquesa', 'CAPACITACION' => 'indigo',
    ];

    private AccesoUsuario $acceso;

    private Usuario $usuario;

    private string $perfil;

    /** Agrupacion de la tendencia: dia | semana | mes. */
    private string $agrupar = 'dia';

    private CarbonImmutable $hoy;

    private CarbonImmutable $desde;

    private CarbonImmutable $hasta;

    private CarbonImmutable $finDeMes;

    /** @var list<int>|null establecimientos del filtro efectivo (null = toda la Red) */
    private ?array $eess = null;

    /** @var list<int> vinculos propios (perfil personal) */
    private array $propios = [];

    public function __construct(private readonly AutomatizacionService $automatizacion) {}

    /**
     * @param  array{perfil?:?string,anio?:?int,mes?:?int,microred_id?:?int,eess_id?:?int}  $filtros
     * @return array<string,mixed>
     */
    public function armar(Usuario $usuario, array $filtros = []): array
    {
        $this->usuario = $usuario;
        $this->acceso = AccesoUsuario::de($usuario);
        $this->hoy = HoraLocal::hoy();
        $this->propios = DB::table('Personal.VinculoLaboral')->where('TrabajadorId', $usuario->TrabajadorId)->pluck('VinculoLaboralId')->map(fn ($id) => (int) $id)->all();

        $perfiles = $this->perfilesDisponibles();
        $this->perfil = in_array($filtros['perfil'] ?? null, $perfiles, true) ? $filtros['perfil'] : $perfiles[0];

        $anio = (int) ($filtros['anio'] ?? $this->hoy->year);
        $this->agrupar = in_array($filtros['agrupar'] ?? null, ['dia', 'semana', 'mes'], true) ? $filtros['agrupar'] : 'dia';
        $mes = (int) ($filtros['mes'] ?? $this->hoy->month);
        $mes = ($mes >= 1 && $mes <= 12) ? $mes : $this->hoy->month;
        $this->desde = CarbonImmutable::create($anio, $mes, 1)->startOfDay();
        $this->finDeMes = $this->desde->endOfMonth()->startOfDay();
        $this->hasta = $this->finDeMes->lt($this->hoy) || $this->desde->gt($this->hoy) ? $this->finDeMes : $this->hoy;

        $this->eess = $this->establecimientosDelFiltro($filtros['microred_id'] ?? null, $filtros['eess_id'] ?? null);

        return [
            'fecha' => $this->hoy->toDateString(),
            'perfil' => $this->perfil,
            'agrupar' => $this->agrupar,
            'perfiles' => array_map(fn ($p) => ['clave' => $p, 'nombre' => self::NOMBRES_PERFIL[$p]], $perfiles),
            'periodo' => ['anio' => $anio, 'mes' => $mes, 'desde' => $this->desde->toDateString(), 'hasta' => $this->hasta->toDateString()],
            'filtros' => [
                'microred_id' => $filtros['microred_id'] ?? null,
                'eess_id' => $filtros['eess_id'] ?? null,
                'microredes' => $this->perfilEsDeGestion() ? $this->microredesVisibles() : [],
                'establecimientos' => $this->perfilEsDeGestion() ? $this->establecimientosVisibles() : [],
            ],
            'alcance' => $this->etiquetaDeAlcance(),
            'widgets' => [...match ($this->perfil) {
                'personal' => $this->widgetsPersonales(),
                'porteria' => $this->widgetsDePorteria(),
                'programacion' => $this->widgetsDeProgramacion(),
                default => $this->widgetsDeGestion(),
            }, $this->avisos()],
        ];
    }

    // ------------------------------------------------------------------ perfiles y alcance

    /** @return list<string> */
    private function perfilesDisponibles(): array
    {
        $disponibles = [];
        foreach (self::PERFILES as $perfil => $roles) {
            if (array_intersect($roles, $this->acceso->roles) !== []) {
                $disponibles[] = $perfil;
            }
        }
        // Quien tiene un vinculo propio (por ejemplo un jefe que tambien marca asistencia) ve ademas "Mi asistencia".
        if (! in_array('personal', $disponibles, true) && $this->propios !== []) {
            $disponibles[] = 'personal';
        }

        return $disponibles === [] ? ['personal'] : $disponibles;
    }

    private function perfilEsDeGestion(): bool
    {
        return in_array($this->perfil, ['red', 'microred', 'eess', 'programacion'], true);
    }

    private function etiquetaDeAlcance(): string
    {
        if ($this->perfil === 'personal') {
            return 'Tu información';
        }
        if ($this->eess === null) {
            return 'Toda la Red';
        }

        return count($this->eess) === 1
            ? (string) DB::table('Organizacion.EstablecimientoSalud')->where('EessId', $this->eess[0])->value('EessNombre')
            : count($this->eess).' establecimientos';
    }

    /** @return list<int>|null */
    private function establecimientosDelFiltro(?int $microredId, ?int $eessId): ?array
    {
        $permitidos = $this->acceso->eessIds; // null = todos
        $elegidos = $permitidos;
        if ($microredId) {
            $deLaMicrored = DB::table('Organizacion.EstablecimientoSalud')->where('MicroredId', $microredId)->pluck('EessId')->map(fn ($id) => (int) $id)->all();
            $elegidos = $permitidos === null ? $deLaMicrored : array_values(array_intersect($permitidos, $deLaMicrored));
        }
        if ($eessId) {
            $elegidos = ($elegidos === null || in_array($eessId, $elegidos, true)) ? [$eessId] : [];
        }

        return $elegidos;
    }

    /** @return list<array{id:int,nombre:string}> */
    private function microredesVisibles(): array
    {
        $q = DB::table('Organizacion.Microred as m')->where('m.MicroredEstado', 1)->orderBy('m.MicroredNombre');
        if ($this->acceso->eessIds !== null) {
            $q->whereIn('m.MicroredId', DB::table('Organizacion.EstablecimientoSalud')->whereIn('EessId', $this->acceso->eessIds)->select('MicroredId'));
        }

        return $q->get(['m.MicroredId as id', 'm.MicroredNombre as nombre'])->map(fn ($m) => ['id' => (int) $m->id, 'nombre' => $m->nombre])->all();
    }

    /** @return list<array{id:int,nombre:string,microred_id:int}> */
    private function establecimientosVisibles(): array
    {
        $q = DB::table('Organizacion.EstablecimientoSalud as e')->where('e.EessEstado', 1)->orderBy('e.EessNombre');
        if ($this->acceso->eessIds !== null) {
            $q->whereIn('e.EessId', $this->acceso->eessIds);
        }

        return $q->get(['e.EessId as id', 'e.EessNombre as nombre', 'e.MicroredId as microred_id'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'nombre' => $e->nombre, 'microred_id' => (int) $e->microred_id])->all();
    }

    /** Une el vinculo laboral (alias vl) y limita la consulta al alcance del perfil y del filtro. */
    private function conVinculo(Builder $q, string $columna): Builder
    {
        $q->join('Personal.VinculoLaboral as vl', 'vl.VinculoLaboralId', '=', $columna);
        if ($this->perfil === 'personal') {
            return $q->whereIn('vl.VinculoLaboralId', $this->propios === [] ? [0] : $this->propios);
        }

        return $this->eess === null ? $q : $q->whereIn('vl.EessId', $this->eess === [] ? [0] : $this->eess);
    }

    private function vigentes(Builder $q, string $alias = 'vl'): Builder
    {
        return $q->where("$alias.VinculoLaboralEstado", 1)
            ->whereDate("$alias.VinculoLaboralFechaInicio", '<=', $this->hoy->toDateString())
            ->where(fn ($w) => $w->whereNull("$alias.VinculoLaboralFechaFin")->orWhereDate("$alias.VinculoLaboralFechaFin", '>=', $this->hoy->toDateString()));
    }

    // ------------------------------------------------------------------ composicion por perfil

    /** @return list<array<string,mixed>> */
    private function widgetsDeGestion(): array
    {
        $mes = $this->resumenDelMes();
        $pendientes = $this->tienePermisoDeSolicitudes() ? $this->pendientes() : null;
        $porEstablecimiento = $this->eess === null || count($this->eess) > 1;

        $widgets = [
            $this->kpis('resumen', 'Resumen', array_values(array_filter([
                $this->kpi('Trabajadores activos', $this->contarVigentes(), 'turquesa', 'users', 'Con vínculo laboral vigente hoy'),
                $this->kpi('Marcaciones hoy', $this->contarMarcacionesDe($this->hoy), 'verde', 'clock'),
                $pendientes === null ? null : $this->kpi('Solicitudes por resolver', array_sum($pendientes), 'naranja', 'file', 'Papeletas, licencias, descansos, justificaciones…'),
                $this->kpi('Cumplimiento de asistencia', $this->porcentaje($mes['efectivo'], $mes['efectivo'] + $mes['faltas_inj'] + $mes['faltas_just']), 'verde', 'check', 'Días asistidos sobre días exigibles del período', '%'),
                $this->kpi('Tardanzas', $mes['tardanzas'], 'ambar', 'alarm', $mes['minutos_tardanza'].' min descontables en total'),
                $this->kpi('Faltas injustificadas', $mes['faltas_inj'], 'rojo', 'userx', 'Descuento por planilla (RIT, Art. 22)'),
                $this->kpi('Con licencia, descanso o vacaciones hoy', $this->ausentesHoy(), 'azul', 'plane'),
            ])), 2),
            $this->tendenciaDiaria(),
            $this->distribucionDeEstados(),
        ];
        if ($porEstablecimiento) {
            $widgets[] = $this->asistenciaPorEstablecimiento();
        }
        if ($pendientes !== null) {
            $widgets[] = $this->graficoDePendientes($pendientes);
        }
        $widgets[] = $this->alertasDeFaltas();
        $widgets[] = $this->estadoDeLaProgramacion();
        if ($this->perfil === 'red' || $this->perfil === 'microred') {
            $widgets[] = $this->trabajadoresPorCondicion();
        }
        $widgets[] = $this->sinProgramacion();
        $widgets[] = $this->contratosPorVencer();
        $widgets[] = $this->colegiaturasPorVencer();
        if ($this->acceso->tiene('SEGURIDAD_AUDITORIA')) {
            $widgets[] = $this->accesosAlSistema();
            $widgets[] = $this->actividadReciente();
        }

        return $widgets;
    }

    /** @return list<array<string,mixed>> */
    private function widgetsDeProgramacion(): array
    {
        $programados = $this->turnosProgramadosDelMes();

        return [
            $this->kpis('resumen', 'Programación del mes', [
                $this->kpi('Turnos programados', $programados, 'turquesa', 'calendar', 'En el mes seleccionado'),
                $this->kpi('Trabajadores sin programación', $this->contarSinProgramacion(), 'rojo', 'userx', 'Con vínculo vigente y ningún turno en el mes'),
                $this->kpi('Cambios de turno por resolver', $this->contarPendientesDe('Programacion.CambioTurno', 'CambioTurnoEstado', 'VinculoLaboralSolicitanteId'), 'naranja', 'file'),
                $this->kpi('Vacaciones en el rol del mes', $this->vacacionesDelMes(), 'azul', 'plane', 'Descansos programados que caen en el mes'),
            ], 2),
            $this->coberturaDiaria(),
            $this->estadoDeLaProgramacion(),
            $this->sinProgramacion(),
            $this->contratosPorVencer(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function widgetsPersonales(): array
    {
        $mes = $this->resumenDelMes();
        $saldo = (float) $this->conVinculo(DB::table('Vacaciones.PeriodoVacacional as pv')->where('pv.PeriodoVacacionalEstado', 'ABIERTO'), 'pv.VinculoLaboralId')->sum('pv.PeriodoVacacionalDiasDisponibles');
        $horas = $this->conVinculo(DB::table('Compensaciones.CompensacionHoraria as ch')->whereIn('ch.CompensacionHorariaEstado', ['APROBADO', 'PENDIENTE']), 'ch.VinculoLaboralId')
            ->selectRaw('SUM(ch.CompensacionHorariaHorasGeneradas - ISNULL(ch.CompensacionHorariaHorasDevueltas, 0)) AS horas')->value('horas');

        return [
            $this->kpis('resumen', 'Mi mes', [
                $this->kpi('Días asistidos', $mes['efectivo'], 'verde', 'check', 'Del '.$this->desde->format('d/m').' al '.$this->hasta->format('d/m')),
                $this->kpi('Tardanzas', $mes['tardanzas'], 'ambar', 'alarm', $mes['minutos_tardanza'].' min en total'),
                $this->kpi('Faltas', $mes['faltas_inj'] + $mes['faltas_just'], 'rojo', 'userx', $mes['faltas_just'].' justificadas'),
                $this->kpi('Vacaciones disponibles', round($saldo, 1), 'azul', 'plane', 'Días del récord vacacional abierto'),
                $this->kpi('Horas por compensar', round((float) $horas, 1), 'indigo', 'clock', 'A favor o por devolver (RIT, Art. 17)'),
            ], 2),
            $this->distribucionDeEstados(),
            $this->tendenciaDiaria('Mi asistencia'),
            $this->proximosTurnos(),
            $this->misSolicitudes(),
            $this->misMarcaciones(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function widgetsDePorteria(): array
    {
        $porHora = $this->conVinculo(DB::table('Asistencia.Marcacion as m')->whereDate('m.MarcacionFechaHora', $this->hoy->toDateString()), 'm.VinculoLaboralId')
            ->selectRaw('DATEPART(hour, m.MarcacionFechaHora) AS hora, COUNT(*) AS total')->groupByRaw('DATEPART(hour, m.MarcacionFechaHora)')->pluck('total', 'hora');
        $horas = range(5, 22);

        return [
            $this->kpis('resumen', 'Hoy', [
                $this->kpi('Marcaciones de hoy', $this->contarMarcacionesDe($this->hoy), 'verde', 'clock'),
                $this->kpi('Personas que marcaron', (int) $this->conVinculo(DB::table('Asistencia.Marcacion as m')->whereDate('m.MarcacionFechaHora', $this->hoy->toDateString()), 'm.VinculoLaboralId')->distinct()->count('m.VinculoLaboralId'), 'turquesa', 'users'),
                $this->kpi('Ocurrencias de hoy', (int) $this->conOcurrencias()->whereDate('o.OcurrenciaPorteriaFechaHora', $this->hoy->toDateString())->count(), 'ambar', 'alarm', 'Registradas en portería'),
            ], 2),
            $this->grafico('barras', 'por_hora', 'Marcaciones por hora (hoy)', [
                'categorias' => array_map(fn ($h) => sprintf('%02d h', $h), $horas),
                'series' => [['nombre' => 'Marcaciones', 'tono' => 'turquesa', 'valores' => array_map(fn ($h) => (int) ($porHora[$h] ?? 0), $horas)]],
            ], 2),
            $this->lista('ultimas', 'Últimas marcaciones', ['Hora', 'Trabajador', 'Tipo'], $this->conVinculo(DB::table('Asistencia.Marcacion as m'), 'm.VinculoLaboralId')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'vl.TrabajadorId')
                ->whereDate('m.MarcacionFechaHora', $this->hoy->toDateString())->orderByDesc('m.MarcacionFechaHora')->limit(10)
                ->get(['m.MarcacionFechaHora as fecha', 't.TrabajadorNombreCompleto as nombre', 'm.MarcacionTipo as tipo'])
                ->map(fn ($r) => [substr((string) $r->fecha, 11, 5), $this->nombre($r->nombre), $this->legible($r->tipo)])->all(), 'Aún no hay marcaciones hoy.', '/asistencia/marcaciones'),
            $this->lista('ocurrencias', 'Ocurrencias de hoy', ['Hora', 'Tipo', 'Detalle'], $this->conOcurrencias()->whereDate('o.OcurrenciaPorteriaFechaHora', $this->hoy->toDateString())->orderByDesc('o.OcurrenciaPorteriaFechaHora')->limit(8)
                ->get(['o.OcurrenciaPorteriaFechaHora as fecha', 'o.OcurrenciaPorteriaTipo as tipo', 'o.OcurrenciaPorteriaDescripcion as detalle'])
                ->map(fn ($r) => [substr((string) $r->fecha, 11, 5), $this->legible($r->tipo), (string) $r->detalle])->all(), 'Sin ocurrencias hoy.', '/solicitudes/ocurrencias-porteria'),
        ];
    }

    private function conOcurrencias(): Builder
    {
        $q = DB::table('Solicitudes.OcurrenciaPorteria as o');

        return $this->eess === null ? $q : $q->whereIn('o.EessId', $this->eess === [] ? [0] : $this->eess);
    }

    // ------------------------------------------------------------------ consultas de asistencia

    /** @return array{efectivo:int,faltas_inj:int,faltas_just:int,autorizadas:int,tardanzas:int,minutos_tardanza:int} */
    private function resumenDelMes(): array
    {
        $fila = $this->asistenciaDelPeriodo()
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaCodigo IN ('.self::ESTADOS_EFECTIVOS.') THEN 1 ELSE 0 END) AS efectivo')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 1 AND ea.EstadoAsistenciaEsDescontable = 1 THEN 1 ELSE 0 END) AS faltas_inj')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 1 AND ea.EstadoAsistenciaEsDescontable = 0 THEN 1 ELSE 0 END) AS faltas_just')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaCodigo IN ('.self::ESTADOS_AUTORIZADOS.') THEN 1 ELSE 0 END) AS autorizadas')
            ->selectRaw('SUM(CASE WHEN ad.AsistenciaDiariaMinutosTardanza > 0 THEN 1 ELSE 0 END) AS tardanzas')
            ->selectRaw('SUM(ISNULL(ad.AsistenciaDiariaMinutosTardanza, 0)) AS minutos')
            ->first();

        return [
            'efectivo' => (int) ($fila->efectivo ?? 0), 'faltas_inj' => (int) ($fila->faltas_inj ?? 0), 'faltas_just' => (int) ($fila->faltas_just ?? 0),
            'autorizadas' => (int) ($fila->autorizadas ?? 0), 'tardanzas' => (int) ($fila->tardanzas ?? 0), 'minutos_tardanza' => (int) ($fila->minutos ?? 0),
        ];
    }

    private function asistenciaDelPeriodo(?CarbonImmutable $desde = null, ?CarbonImmutable $hasta = null): Builder
    {
        $desde ??= $this->desde;
        $hasta ??= $this->hasta;

        return $this->conVinculo(DB::table('Asistencia.AsistenciaDiaria as ad')
            ->join('Asistencia.EstadoAsistencia as ea', 'ea.EstadoAsistenciaId', '=', 'ad.EstadoAsistenciaId')
            ->whereDate('ad.AsistenciaDiariaFecha', '>=', $desde->toDateString())
            ->whereDate('ad.AsistenciaDiariaFecha', '<=', $hasta->toDateString()), 'ad.VinculoLaboralId');
    }

    /** @return array<string,mixed> */
    private function tendenciaDiaria(string $titulo = 'Asistencia'): array
    {
        // Por mes se compara el año completo hasta hoy; por semana o por dia, el mes elegido.
        $desde = $this->agrupar === 'mes' ? $this->desde->startOfYear() : $this->desde;
        $hasta = $this->agrupar === 'mes' ? ($this->desde->endOfYear()->startOfDay()->lt($this->hoy) ? $this->desde->endOfYear()->startOfDay() : $this->hoy) : $this->hasta;

        $filas = $this->asistenciaDelPeriodo($desde, $hasta)
            ->selectRaw('ad.AsistenciaDiariaFecha AS fecha')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaCodigo IN ('.self::ESTADOS_EFECTIVOS.') THEN 1 ELSE 0 END) AS efectivo')
            ->selectRaw('SUM(CASE WHEN ad.AsistenciaDiariaMinutosTardanza > 0 THEN 1 ELSE 0 END) AS tardanzas')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 1 AND ea.EstadoAsistenciaEsDescontable = 1 THEN 1 ELSE 0 END) AS faltas')
            ->groupBy('ad.AsistenciaDiariaFecha')->orderBy('ad.AsistenciaDiariaFecha')->get()
            ->keyBy(fn ($f) => substr((string) $f->fecha, 0, 10));

        // Cada tramo: [etiqueta corta, titulo del tooltip, primer dia, ultimo dia]
        $tramos = [];
        if ($this->agrupar === 'mes') {
            for ($m = $desde; $m->lte($hasta); $m = $m->addMonth()) {
                $tramos[] = [rtrim(ucfirst($m->locale('es')->isoFormat('MMM')), '.'), ucfirst($m->locale('es')->isoFormat('MMMM YYYY')), $m, $m->endOfMonth()->startOfDay()];
            }
        } elseif ($this->agrupar === 'semana') {
            for ($i = $desde, $n = 1; $i->lte($hasta); $n++) {
                $fin = $i->endOfWeek()->startOfDay()->lt($hasta) ? $i->endOfWeek()->startOfDay() : $hasta;
                $tramos[] = ["S{$n}", "Semana {$n}: ".$i->format('d/m').' al '.$fin->format('d/m'), $i, $fin];
                $i = $fin->addDay();
            }
        } else {
            for ($d = $desde; $d->lte($hasta); $d = $d->addDay()) {
                $tramos[] = [$d->format('d'), 'Día '.$d->format('d/m'), $d, $d];
            }
        }

        $sumar = function (string $campo) use ($tramos, $filas): array {
            return array_map(function ($tramo) use ($campo, $filas) {
                $total = 0;
                for ($d = $tramo[2]; $d->lte($tramo[3]); $d = $d->addDay()) {
                    $total += (int) ($filas[$d->toDateString()]->{$campo} ?? 0);
                }

                return $total;
            }, $tramos);
        };

        $etiqueta = ['dia' => 'día a día', 'semana' => 'por semana', 'mes' => 'por mes'][$this->agrupar];

        return $this->grafico('lineas', 'tendencia', "{$titulo} {$etiqueta}", [
            'categorias' => array_column($tramos, 0),
            'titulos' => array_column($tramos, 1),
            'agrupaciones' => ['dia' => 'Día', 'semana' => 'Semana', 'mes' => 'Mes'],
            'agrupacion' => $this->agrupar,
            'series' => [
                ['nombre' => 'Asistieron', 'tono' => 'verde', 'valores' => $sumar('efectivo')],
                ['nombre' => 'Tardanzas', 'tono' => 'ambar', 'valores' => $sumar('tardanzas')],
                ['nombre' => 'Faltas injustificadas', 'tono' => 'rojo', 'valores' => $sumar('faltas')],
            ],
        ], 2, 'Del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y'));
    }

    /** @return array<string,mixed> */
    private function distribucionDeEstados(): array
    {
        $filas = $this->asistenciaDelPeriodo()->where('ea.EstadoAsistenciaEsLaborable', 1)
            ->selectRaw('ea.EstadoAsistenciaCodigo AS codigo, ea.EstadoAsistenciaNombre AS nombre, COUNT(*) AS total')
            ->groupBy('ea.EstadoAsistenciaCodigo', 'ea.EstadoAsistenciaNombre')->orderByDesc('total')->get();

        return $this->grafico('dona', 'estados', 'Estado de la asistencia', [
            'segmentos' => $filas->map(fn ($f) => ['etiqueta' => $f->nombre, 'valor' => (int) $f->total, 'tono' => self::TONO_ESTADO[$f->codigo] ?? 'gris'])->all(),
        ], 1, 'Días laborables del período');
    }

    /** @return array<string,mixed> */
    private function asistenciaPorEstablecimiento(): array
    {
        $filas = $this->asistenciaDelPeriodo()->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'vl.EessId')
            ->selectRaw('e.EessNombre AS nombre')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaCodigo IN ('.self::ESTADOS_EFECTIVOS.') THEN 1 ELSE 0 END) AS efectivo')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 1 THEN 1 ELSE 0 END) AS faltas')
            ->selectRaw('SUM(CASE WHEN ad.AsistenciaDiariaMinutosTardanza > 0 THEN 1 ELSE 0 END) AS tardanzas')
            ->groupBy('e.EessId', 'e.EessNombre')->orderByDesc('faltas')->limit(12)->get();

        return $this->grafico('barras', 'por_eess', 'Asistencia por establecimiento', [
            'categorias' => $filas->map(fn ($f) => $f->nombre)->all(),
            'horizontal' => true,
            'apiladas' => true,
            'normalizado' => true,
            'series' => [
                ['nombre' => 'Asistieron', 'tono' => 'verde', 'valores' => $filas->map(fn ($f) => (int) $f->efectivo)->all()],
                ['nombre' => 'Tardanzas', 'tono' => 'ambar', 'valores' => $filas->map(fn ($f) => (int) $f->tardanzas)->all()],
                ['nombre' => 'Faltas', 'tono' => 'rojo', 'valores' => $filas->map(fn ($f) => (int) $f->faltas)->all()],
            ],
        ], 2, 'Los 12 con más faltas en el período');
    }

    // ------------------------------------------------------------------ solicitudes pendientes

    private function tienePermisoDeSolicitudes(): bool
    {
        return $this->acceso->tiene('SOLICITUDES_VER');
    }

    /** @return array<string,int> */
    private function pendientes(): array
    {
        return [
            'Papeletas' => $this->contarPendientesDe('Solicitudes.Papeleta', 'PapeletaEstado', 'VinculoLaboralId'),
            'Licencias' => $this->contarPendientesDe('Solicitudes.Licencia', 'LicenciaEstado', 'VinculoLaboralId'),
            'Descansos médicos' => $this->contarPendientesDe('Solicitudes.DescansoMedico', 'DescansoMedicoEstado', 'VinculoLaboralId'),
            'Justificaciones' => $this->contarPendientesDe('Asistencia.JustificacionFalta', 'JustificacionFaltaEstado', 'VinculoLaboralId'),
            'Cambios de turno' => $this->contarPendientesDe('Programacion.CambioTurno', 'CambioTurnoEstado', 'VinculoLaboralSolicitanteId'),
            'Vacaciones' => (int) $this->conVinculo(DB::table('Vacaciones.GoceVacacional as s')
                ->join('Vacaciones.RolVacacional as r', 'r.RolVacacionalId', '=', 's.RolVacacionalId')
                ->join('Vacaciones.PeriodoVacacional as p', 'p.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
                ->where('s.GoceVacacionalEstado', 'PENDIENTE'), 'p.VinculoLaboralId')->count(),
        ];
    }

    private function contarPendientesDe(string $tabla, string $columnaEstado, string $columnaVinculo): int
    {
        return (int) $this->conVinculo(DB::table("$tabla as s")->where("s.$columnaEstado", 'PENDIENTE'), "s.$columnaVinculo")->count();
    }

    /** @param  array<string,int>  $pendientes */
    private function graficoDePendientes(array $pendientes): array
    {
        return $this->grafico('barras', 'pendientes', 'Solicitudes por resolver', [
            'categorias' => array_keys($pendientes),
            'horizontal' => true,
            'series' => [['nombre' => 'Pendientes', 'tono' => 'naranja', 'valores' => array_values($pendientes)]],
        ], 1, 'Esperan la resolución del jefe o de RRHH (RIT, Art. 16)');
    }

    // ------------------------------------------------------------------ personal, programacion y alertas

    private function contarVigentes(): int
    {
        return (int) $this->vigentes($this->conVinculo(DB::table('Personal.VinculoLaboral as v0'), 'v0.VinculoLaboralId'))->distinct()->count('vl.TrabajadorId');
    }

    private function contarMarcacionesDe(CarbonImmutable $dia): int
    {
        return (int) $this->conVinculo(DB::table('Asistencia.Marcacion as m')->whereDate('m.MarcacionFechaHora', $dia->toDateString()), 'm.VinculoLaboralId')->count();
    }

    private function ausentesHoy(): int
    {
        $hoy = $this->hoy->toDateString();
        $licencias = $this->conVinculo(DB::table('Solicitudes.Licencia as s')->where('s.LicenciaEstado', 'APROBADO')->whereDate('s.LicenciaFechaInicio', '<=', $hoy)->whereDate('s.LicenciaFechaFin', '>=', $hoy), 's.VinculoLaboralId')->count();
        $descansos = $this->conVinculo(DB::table('Solicitudes.DescansoMedico as s')->where('s.DescansoMedicoEstado', 'APROBADO')->whereDate('s.DescansoMedicoFechaInicio', '<=', $hoy)->whereDate('s.DescansoMedicoFechaFin', '>=', $hoy), 's.VinculoLaboralId')->count();
        $vacaciones = $this->conVinculo(DB::table('Vacaciones.GoceVacacional as g')
            ->join('Vacaciones.RolVacacional as r', 'r.RolVacacionalId', '=', 'g.RolVacacionalId')
            ->join('Vacaciones.PeriodoVacacional as p', 'p.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
            ->where('g.GoceVacacionalEstado', 'APROBADO')->whereDate('g.GoceVacacionalFechaInicio', '<=', $hoy)->whereDate('g.GoceVacacionalFechaFin', '>=', $hoy), 'p.VinculoLaboralId')->count();

        return (int) ($licencias + $descansos + $vacaciones);
    }

    private function vacacionesDelMes(): int
    {
        return (int) $this->conVinculo(DB::table('Vacaciones.RolVacacional as r')
            ->join('Vacaciones.PeriodoVacacional as p', 'p.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
            ->where('r.RolVacacionalEstado', '<>', 'ANULADO')
            ->whereDate('r.RolVacacionalFechaProgramada', '<=', $this->finDeMes->toDateString())
            ->whereDate('r.RolVacacionalFechaFinProgramada', '>=', $this->desde->toDateString()), 'p.VinculoLaboralId')->count();
    }

    /** Periodos de programacion que tocan el mes, por estado. */
    private function estadoDeLaProgramacion(): array
    {
        $q = DB::table('Programacion.ProgramacionPeriodo as pp')->where('pp.ProgramacionPeriodoEstado', '<>', 'ANULADA')
            ->whereDate('pp.ProgramacionPeriodoFechaInicio', '<=', $this->finDeMes->toDateString())
            ->whereDate('pp.ProgramacionPeriodoFechaFin', '>=', $this->desde->toDateString());
        if ($this->eess !== null) {
            $q->whereIn('pp.EessId', $this->eess === [] ? [0] : $this->eess);
        }
        $tonos = ['BORRADOR' => 'ambar', 'PUBLICADA' => 'verde', 'CERRADA' => 'gris'];
        $filas = $q->selectRaw('pp.ProgramacionPeriodoEstado AS estado, COUNT(*) AS total')->groupBy('pp.ProgramacionPeriodoEstado')->get();

        return $this->grafico('dona', 'programacion', 'Programaciones del mes', [
            'segmentos' => $filas->map(fn ($f) => ['etiqueta' => ucfirst(strtolower($f->estado)), 'valor' => (int) $f->total, 'tono' => $tonos[$f->estado] ?? 'gris'])->all(),
        ], 1, 'Periodos de programación por estado (publicar antes del inicio: RIT, Art. 16)');
    }

    private function turnosProgramadosDelMes(): int
    {
        return (int) $this->turnosDelMes()->count();
    }

    private function turnosDelMes(): Builder
    {
        return $this->conVinculo(DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')->where('pt.ProgramacionTrabajadorEstado', '<>', 'ANULADA')
            ->whereDate('tp.TurnoProgramadoFecha', '>=', $this->desde->toDateString())->whereDate('tp.TurnoProgramadoFecha', '<=', $this->finDeMes->toDateString()), 'pt.VinculoLaboralId');
    }

    /** @return array<string,mixed> */
    private function coberturaDiaria(): array
    {
        $filas = $this->turnosDelMes()->selectRaw('tp.TurnoProgramadoFecha AS fecha, COUNT(*) AS total, SUM(CASE WHEN tp.TurnoProgramadoEsGuardia = 1 THEN 1 ELSE 0 END) AS guardias')
            ->groupBy('tp.TurnoProgramadoFecha')->get()->keyBy(fn ($f) => substr((string) $f->fecha, 0, 10));
        $dias = [];
        for ($d = $this->desde; $d->lte($this->finDeMes); $d = $d->addDay()) {
            $dias[] = $d->toDateString();
        }

        return $this->grafico('barras', 'cobertura', 'Personal programado por día', [
            'categorias' => array_map(fn ($d) => substr($d, 8, 2), $dias),
            'apiladas' => true,
            'series' => [
                ['nombre' => 'Turnos regulares', 'tono' => 'turquesa', 'valores' => array_map(fn ($d) => (int) (($filas[$d]->total ?? 0) - ($filas[$d]->guardias ?? 0)), $dias)],
                ['nombre' => 'Guardias', 'tono' => 'indigo', 'valores' => array_map(fn ($d) => (int) ($filas[$d]->guardias ?? 0), $dias)],
            ],
        ], 2, 'Cuántas personas tienen turno cada día: sirve para ver huecos de cobertura');
    }

    private function sinProgramacionQuery(): Builder
    {
        $q = $this->vigentes($this->conVinculo(DB::table('Personal.VinculoLaboral as v0'), 'v0.VinculoLaboralId'));

        return $q->whereNotExists(fn ($s) => $s->from('Programacion.ProgramacionTrabajador as pt')
            ->join('Programacion.TurnoProgramado as tp', 'tp.ProgramacionTrabajadorId', '=', 'pt.ProgramacionTrabajadorId')
            ->whereColumn('pt.VinculoLaboralId', 'vl.VinculoLaboralId')->where('pt.ProgramacionTrabajadorEstado', '<>', 'ANULADA')->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')
            ->whereDate('tp.TurnoProgramadoFecha', '>=', $this->desde->toDateString())->whereDate('tp.TurnoProgramadoFecha', '<=', $this->finDeMes->toDateString())->select(DB::raw('1')));
    }

    private function contarSinProgramacion(): int
    {
        return (int) $this->sinProgramacionQuery()->count();
    }

    /** @return array<string,mixed> */
    private function sinProgramacion(): array
    {
        $filas = $this->sinProgramacionQuery()->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'vl.TrabajadorId')->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'vl.EessId')
            ->orderBy('t.TrabajadorNombreCompleto')->limit(8)->get(['t.TrabajadorNombreCompleto as nombre', 'e.EessNombre as eess']);

        return $this->lista('sin_programacion', 'Sin turnos en el mes', ['Trabajador', 'Establecimiento'], $filas->map(fn ($f) => [$this->nombre($f->nombre), $f->eess])->all(),
            'Todo el personal vigente tiene turnos programados.', '/programacion/programaciones-trabajador', $this->contarSinProgramacion());
    }

    /** @return array<string,mixed> */
    private function alertasDeFaltas(): array
    {
        $casos = $this->automatizacion->faltasReiteradas($this->eess);

        return $this->lista('alertas_faltas', 'Faltas reiteradas (RIT, Art. 23)', ['Trabajador', 'Establecimiento', 'Situación'], array_map(fn ($c) => [$c['nombre'], $c['eess'], $c['motivo']], array_slice($casos, 0, 8)),
            'Nadie supera los límites de faltas injustificadas.', '/disciplina/expedientes-pad', count($casos), 2);
    }

    /** @return array<string,mixed> */
    private function trabajadoresPorCondicion(): array
    {
        $filas = $this->vigentes($this->conVinculo(DB::table('Personal.VinculoLaboral as v0'), 'v0.VinculoLaboralId'))
            ->join('Personal.CondicionLaboral as c', 'c.CondicionLaboralId', '=', 'vl.CondicionLaboralId')
            ->selectRaw('c.CondicionLaboralNombre AS nombre, COUNT(*) AS total')->groupBy('c.CondicionLaboralNombre')->orderByDesc('total')->get();
        $tonos = ['turquesa', 'azul', 'indigo', 'verde', 'ambar', 'naranja', 'gris'];

        return $this->grafico('dona', 'condicion', 'Personal por condición laboral', [
            'segmentos' => $filas->values()->map(fn ($f, $i) => ['etiqueta' => $f->nombre, 'valor' => (int) $f->total, 'tono' => $tonos[$i % count($tonos)]])->all(),
        ], 1, 'Vínculos vigentes hoy');
    }

    /** @return array<string,mixed> */
    private function contratosPorVencer(): array
    {
        $limite = $this->hoy->addDays(30)->toDateString();
        $q = $this->conVinculo(DB::table('Personal.VinculoLaboral as v0')->where('vl.VinculoLaboralEstado', 1)
            ->whereDate('vl.VinculoLaboralFechaFin', '>=', $this->hoy->toDateString())->whereDate('vl.VinculoLaboralFechaFin', '<=', $limite), 'v0.VinculoLaboralId');
        $total = (clone $q)->count();
        $filas = $q->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'vl.TrabajadorId')->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'vl.EessId')
            ->orderBy('vl.VinculoLaboralFechaFin')->limit(8)->get(['t.TrabajadorNombreCompleto as nombre', 'e.EessNombre as eess', 'vl.VinculoLaboralFechaFin as fin']);

        return $this->lista('contratos', 'Contratos por vencer (30 días)', ['Trabajador', 'Establecimiento', 'Vence'], $filas->map(fn ($f) => [$this->nombre($f->nombre), $f->eess, $this->fecha($f->fin)])->all(),
            'No hay contratos por vencer en los próximos 30 días.', '/personal/vinculos-laborales', $total);
    }

    /** @return array<string,mixed> */
    private function colegiaturasPorVencer(): array
    {
        $limite = $this->hoy->addDays(60)->toDateString();
        $q = $this->vigentes($this->conVinculo(DB::table('Personal.Colegiatura as c')->join('Personal.VinculoLaboral as v0', 'v0.TrabajadorId', '=', 'c.TrabajadorId'), 'v0.VinculoLaboralId'))
            ->where('c.ColegiaturaEstado', 1)->whereDate('c.ColegiaturaFechaVencimiento', '>=', $this->hoy->toDateString())->whereDate('c.ColegiaturaFechaVencimiento', '<=', $limite);
        $total = (clone $q)->distinct()->count('c.ColegiaturaId');
        $filas = $q->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'c.TrabajadorId')->join('Personal.ColegiaturaTipo as ct', 'ct.ColegiaturaTipoId', '=', 'c.ColegiaturaTipoId')
            ->orderBy('c.ColegiaturaFechaVencimiento')->limit(8)->distinct()->get(['t.TrabajadorNombreCompleto as nombre', 'ct.ColegiaturaTipoNombre as colegio', 'c.ColegiaturaFechaVencimiento as vence']);

        return $this->lista('colegiaturas', 'Colegiaturas por vencer (60 días)', ['Trabajador', 'Colegio', 'Vence'], $filas->map(fn ($f) => [$this->nombre($f->nombre), $f->colegio, $this->fecha($f->vence)])->all(),
            'No hay colegiaturas por vencer.', '/personal/colegiaturas', $total);
    }

    // ------------------------------------------------------------------ seguridad (solo con SEGURIDAD_AUDITORIA)

    /** @return array<string,mixed> */
    private function accesosAlSistema(): array
    {
        $desde = $this->hoy->subDays(6);
        $filas = DB::table('Seguridad.SesionAcceso')->whereDate('SesionAccesoFechaInicio', '>=', $desde->toDateString())
            ->selectRaw("CAST(SesionAccesoFechaInicio AS date) AS dia, SUM(CASE WHEN SesionAccesoResultado = 'EXITOSO' THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN SesionAccesoResultado <> 'EXITOSO' THEN 1 ELSE 0 END) AS fallidos")
            ->groupByRaw('CAST(SesionAccesoFechaInicio AS date)')->get()->keyBy(fn ($f) => substr((string) $f->dia, 0, 10));
        $dias = [];
        for ($d = $desde; $d->lte($this->hoy); $d = $d->addDay()) {
            $dias[] = $d->toDateString();
        }

        return $this->grafico('barras', 'accesos', 'Accesos al sistema (7 días)', [
            'categorias' => array_map(fn ($d) => CarbonImmutable::parse($d)->format('d/m'), $dias),
            'series' => [
                ['nombre' => 'Exitosos', 'tono' => 'verde', 'valores' => array_map(fn ($d) => (int) ($filas[$d]->ok ?? 0), $dias)],
                ['nombre' => 'Fallidos', 'tono' => 'rojo', 'valores' => array_map(fn ($d) => (int) ($filas[$d]->fallidos ?? 0), $dias)],
            ],
        ], 1, 'Intentos de ingreso por día');
    }

    /** @return array<string,mixed> */
    private function actividadReciente(): array
    {
        $filas = DB::table('Seguridad.Auditoria as a')->leftJoin('Seguridad.Usuario as u', 'u.UsuarioId', '=', 'a.UsuarioId')->orderByDesc('a.AuditoriaFechaHora')->limit(8)
            ->get(['a.AuditoriaFechaHora as fecha', 'u.UsuarioNombre as usuario', 'a.AuditoriaOperacion as operacion', 'a.AuditoriaTabla as tabla']);

        return $this->lista('actividad', 'Actividad reciente', ['Fecha', 'Usuario', 'Acción'], $filas->map(fn ($f) => [substr((string) $f->fecha, 0, 16), (string) $f->usuario, $f->operacion.' · '.$f->tabla])->all(),
            'Aún no hay actividad registrada.', '/seguridad/auditoria');
    }

    // ------------------------------------------------------------------ perfil personal

    /** @return array<string,mixed> */
    private function proximosTurnos(): array
    {
        $filas = $this->conVinculo(DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Configuracion.Turno as tu', 'tu.TurnoId', '=', 'tp.TurnoId')
            ->where('tp.TurnoProgramadoEstado', 'PROGRAMADO')->where('pt.ProgramacionTrabajadorEstado', '<>', 'ANULADA')
            ->whereDate('tp.TurnoProgramadoFecha', '>=', $this->hoy->toDateString())->whereDate('tp.TurnoProgramadoFecha', '<=', $this->hoy->addDays(14)->toDateString()), 'pt.VinculoLaboralId')
            ->orderBy('tp.TurnoProgramadoFecha')->limit(8)->get(['tp.TurnoProgramadoFecha as fecha', 'tu.TurnoNombre as turno', 'tu.TurnoHoraEntrada as entrada', 'tu.TurnoHoraSalida as salida']);

        return $this->lista('proximos_turnos', 'Mis próximos turnos', ['Día', 'Turno', 'Horario'],
            $filas->map(fn ($f) => [CarbonImmutable::parse($f->fecha)->locale('es')->isoFormat('ddd D MMM'), $f->turno, substr((string) $f->entrada, 0, 5).' – '.substr((string) $f->salida, 0, 5)])->all(),
            'No tienes turnos programados en los próximos 14 días.', '/programacion/calendario');
    }

    /** @return array<string,mixed> */
    private function misSolicitudes(): array
    {
        $ids = $this->propios === [] ? [0] : $this->propios;
        $papeletas = DB::table('Solicitudes.Papeleta')->whereIn('VinculoLaboralId', $ids)->get(['PapeletaFecha as fecha', 'PapeletaEstado as estado'])->map(fn ($r) => ['Papeleta', $r->fecha, $r->estado]);
        $licencias = DB::table('Solicitudes.Licencia')->whereIn('VinculoLaboralId', $ids)->get(['LicenciaFechaInicio as fecha', 'LicenciaEstado as estado'])->map(fn ($r) => ['Licencia', $r->fecha, $r->estado]);
        $justificaciones = DB::table('Asistencia.JustificacionFalta')->whereIn('VinculoLaboralId', $ids)->get(['JustificacionFaltaFechaInicio as fecha', 'JustificacionFaltaEstado as estado'])->map(fn ($r) => ['Justificación', $r->fecha, $r->estado]);
        $filas = $papeletas->concat($licencias)->concat($justificaciones)->sortByDesc(fn ($r) => (string) $r[1])->take(6)
            ->map(fn ($r) => [$r[0], $this->fecha($r[1]), ucfirst(strtolower((string) $r[2]))])->values()->all();

        return $this->lista('mis_solicitudes', 'Mis solicitudes', ['Trámite', 'Fecha', 'Estado'], $filas, 'No has registrado solicitudes.', '/solicitudes/papeletas');
    }

    /** @return array<string,mixed> */
    private function misMarcaciones(): array
    {
        $filas = $this->conVinculo(DB::table('Asistencia.Marcacion as m'), 'm.VinculoLaboralId')->orderByDesc('m.MarcacionFechaHora')->limit(8)->get(['m.MarcacionFechaHora as fecha', 'm.MarcacionTipo as tipo', 'm.MarcacionOrigen as origen']);

        return $this->lista('mis_marcaciones', 'Mis últimas marcaciones', ['Fecha y hora', 'Tipo', 'Origen'], $filas->map(fn ($f) => [substr((string) $f->fecha, 0, 16), $this->legible($f->tipo), $this->legible((string) $f->origen)])->all(),
            'Aún no tienes marcaciones.', '/asistencia/marcaciones');
    }

    /** @return array<string,mixed> */
    private function avisos(): array
    {
        $filas = DB::table('Soporte.Notificacion')->where('UsuarioId', $this->usuario->UsuarioId)->orderByDesc('NotificacionFecha')->limit(6)
            ->get(['NotificacionTitulo as titulo', 'NotificacionFecha as fecha', 'NotificacionLeida as leida']);
        $sinLeer = (int) DB::table('Soporte.Notificacion')->where('UsuarioId', $this->usuario->UsuarioId)->where('NotificacionLeida', 0)->count();

        return $this->lista('avisos', 'Mis avisos', ['Aviso', 'Fecha', 'Estado'], $filas->map(fn ($f) => [(string) $f->titulo, substr((string) $f->fecha, 0, 10), $f->leida ? 'Leído' : 'Nuevo'])->all(),
            'No tienes avisos.', '/soporte/notificaciones', $sinLeer, 2);
    }

    // ------------------------------------------------------------------ construccion de widgets y utilidades

    /** @param  list<array<string,mixed>>  $items */
    private function kpis(string $id, string $titulo, array $items, int $ancho = 1): array
    {
        return ['id' => $id, 'tipo' => 'kpis', 'titulo' => $titulo, 'ancho' => $ancho, 'items' => $items];
    }

    /** @return array<string,mixed> */
    private function kpi(string $etiqueta, int|float $valor, string $tono, string $icono, ?string $detalle = null, string $unidad = ''): array
    {
        return ['etiqueta' => $etiqueta, 'valor' => $valor, 'tono' => $tono, 'icono' => $icono, 'detalle' => $detalle, 'unidad' => $unidad];
    }

    /** @param  array<string,mixed>  $datos */
    private function grafico(string $tipo, string $id, string $titulo, array $datos, int $ancho = 1, ?string $subtitulo = null): array
    {
        return ['id' => $id, 'tipo' => $tipo, 'titulo' => $titulo, 'subtitulo' => $subtitulo, 'ancho' => $ancho] + $datos;
    }

    /**
     * @param  list<string>  $columnas
     * @param  list<list<string>>  $filas
     */
    private function lista(string $id, string $titulo, array $columnas, array $filas, string $vacio, ?string $enlace = null, ?int $total = null, int $ancho = 1): array
    {
        return ['id' => $id, 'tipo' => 'lista', 'titulo' => $titulo, 'ancho' => $ancho, 'columnas' => $columnas, 'filas' => $filas, 'vacio' => $vacio, 'enlace' => $enlace, 'total' => $total ?? count($filas)];
    }

    private function porcentaje(int $parte, int $total): float
    {
        return $total === 0 ? 0.0 : round($parte * 100 / $total, 1);
    }

    private function nombre(?string $nombre): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $nombre)));
    }

    private function fecha(?string $fecha): string
    {
        return $fecha === null ? '—' : CarbonImmutable::parse(substr($fecha, 0, 10))->format('d/m/Y');
    }

    private function legible(?string $valor): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', (string) $valor)));
    }
}
