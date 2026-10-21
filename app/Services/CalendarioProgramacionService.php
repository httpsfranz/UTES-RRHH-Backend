<?php

namespace App\Services;

use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calendarios mensuales de planificacion de un establecimiento, con la misma forma que el de asistencia (trabajadores en
 * filas, dias en columnas, celdas con categoria/linea1/linea2) para dibujarse con el mismo componente:
 *
 *   - programacion(): turnos programados (Programacion.TurnoProgramado) de cada trabajador. Marca los borradores, las guardias
 *     y los CONFLICTOS: un turno programado un dia en que el trabajador tiene una ausencia aprobada.
 *   - ausencias(): licencias, descansos medicos, papeletas y vacaciones APROBADOS (y el rol vacacional aun por gozar).
 *
 * Solo lee: no crea ni modifica registros. Los feriados salen de Soporte.CalendarioNoLaborable (dias[].no_laborable).
 */
class CalendarioProgramacionService
{
    public const CATEGORIAS_PROGRAMACION = [
        'turno' => ['PUB', 'Turno programado y publicado'],
        'guardia' => ['G', 'Guardia programada'],
        'borrador' => ['BOR', 'Turno en borrador (aún no publicado)'],
        'conflicto' => ['!', 'Conflicto: turno programado en un día con ausencia aprobada'],
    ];

    public const CATEGORIAS_AUSENCIA = [
        'descanso_medico' => ['DM', 'Descanso médico'],
        'licencia' => ['LIC', 'Licencia'],
        'vacaciones' => ['VAC', 'Vacaciones aprobadas'],
        'vacaciones_prog' => ['VP', 'Vacaciones programadas en el rol (sin goce aprobado)'],
        'papeleta' => ['PAP', 'Papeleta autorizada'],
    ];

    public function __construct(private readonly CalendarioAsistenciaService $base) {}

    /** @return array<string,mixed> */
    public function programacion(int $eessId, int $anio, int $mes, ?string $buscar = null, ?array $soloVinculos = null): array
    {
        [$envoltura, $trabajadores, $primero, $ultimo] = $this->preparar($eessId, $anio, $mes, $buscar, $soloVinculos, 'PROGRAMACIÓN');
        $ids = $trabajadores->pluck('vinculo_id')->all();
        $ausencias = $ids === [] ? collect() : $this->ausenciasPorDia($ids, $primero, $ultimo);
        $turnos = $ids === [] ? collect() : $this->turnosProgramados($ids, $primero, $ultimo);

        $usados = [];
        $filas = $trabajadores->values()->map(function (array $t, int $i) use ($envoltura, $turnos, $ausencias, &$usados) {
            $celdas = [];
            foreach ($envoltura['periodo']['dias'] as $dia) {
                $clave = $t['vinculo_id'].'|'.$dia['fecha'];
                $deldia = $turnos->get($clave, collect());
                foreach ($deldia as $x) {
                    $usados[$x->codigo] = ['codigo' => $x->codigo, 'nombre' => $x->nombre, 'entrada' => substr((string) $x->entrada, 0, 5), 'salida' => substr((string) $x->salida, 0, 5)];
                }
                $celdas[$dia['fecha']] = $this->celdaProgramacion($deldia, $ausencias->get($clave, []));
            }

            return ['n' => $i + 1] + $t + ['celdas' => $celdas];
        })->all();

        return $envoltura + [
            'trabajadores' => $filas,
            'leyenda' => $this->leyenda(self::CATEGORIAS_PROGRAMACION) + ['turnos' => collect($usados)->sortKeys()->values()->all(), 'turnos_sin_color' => false],
            'generado_en' => HoraLocal::ahora()->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    public function ausencias(int $eessId, int $anio, int $mes, ?string $buscar = null, ?array $soloVinculos = null): array
    {
        [$envoltura, $trabajadores, $primero, $ultimo] = $this->preparar($eessId, $anio, $mes, $buscar, $soloVinculos, 'AUSENCIAS Y VACACIONES');
        $ids = $trabajadores->pluck('vinculo_id')->all();
        $ausencias = $ids === [] ? collect() : $this->ausenciasPorDia($ids, $primero, $ultimo);

        $filas = $trabajadores->values()->map(function (array $t, int $i) use ($envoltura, $ausencias) {
            $celdas = [];
            foreach ($envoltura['periodo']['dias'] as $dia) {
                $celdas[$dia['fecha']] = $this->celdaAusencia($ausencias->get($t['vinculo_id'].'|'.$dia['fecha'], []));
            }

            return ['n' => $i + 1] + $t + ['celdas' => $celdas];
        })->all();

        return $envoltura + [
            'trabajadores' => $filas,
            'leyenda' => $this->leyenda(self::CATEGORIAS_AUSENCIA) + ['turnos' => [], 'turnos_sin_color' => false],
            'generado_en' => HoraLocal::ahora()->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array{0:array<string,mixed>,1:Collection<int,array<string,mixed>>,2:CarbonImmutable,3:CarbonImmutable} */
    private function preparar(int $eessId, int $anio, int $mes, ?string $buscar, ?array $soloVinculos, string $titulo = ''): array
    {
        $primero = CarbonImmutable::create($anio, $mes, 1)->startOfDay();
        $ultimo = $primero->endOfMonth()->startOfDay();
        $eess = DB::table('Organizacion.EstablecimientoSalud as e')
            ->join('Organizacion.Microred as m', 'm.MicroredId', '=', 'e.MicroredId')
            ->where('e.EessId', $eessId)->first(['e.EessId', 'e.EessCodigo', 'e.EessNombre', 'e.MicroredId', 'm.MicroredNombre']);
        abort_if($eess === null, 404, 'El establecimiento no existe.');

        $envoltura = [
            'titulo' => $titulo,
            'entidad' => DB::table('Configuracion.ParametroSistema')->where('ParametroSistemaCodigo', 'ENTIDAD_NOMBRE')->value('ParametroSistemaValor') ?? 'Red de Salud Trujillo',
            'eess' => ['id' => (int) $eess->EessId, 'codigo' => $eess->EessCodigo, 'nombre' => $eess->EessNombre, 'microred' => $eess->MicroredNombre],
            'periodo' => ['anio' => $anio, 'mes' => $mes, 'nombre_mes' => CalendarioAsistenciaService::MESES[$mes], 'etiqueta' => CalendarioAsistenciaService::MESES[$mes].' '.$anio, 'dias' => $this->base->dias($primero, $ultimo, (int) $eess->MicroredId)],
        ];

        return [$envoltura, $this->base->trabajadores($eessId, $primero, $ultimo, $buscar, $soloVinculos), $primero, $ultimo];
    }

    /**
     * @param  array<string,array{0:string,1:string}>  $categorias
     * @return array<string,mixed>
     */
    private function leyenda(array $categorias): array
    {
        return [
            'categorias' => collect($categorias)->map(fn ($c, $clave) => ['clave' => $clave, 'nombre' => $c[1]])->values()->all(),
            'estados' => collect($categorias)->map(fn ($c, $clave) => ['codigo' => strtoupper($clave), 'abreviatura' => $c[0], 'nombre' => $c[1], 'categoria' => $clave])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int,object>  $turnos
     * @param  list<array<string,string|null>>  $ausencias
     * @return array<string,mixed>
     */
    private function celdaProgramacion(Collection $turnos, array $ausencias): array
    {
        if ($turnos->isEmpty()) {
            return ['vacia' => true];
        }

        $codigos = $turnos->pluck('codigo')->unique()->implode('+');
        $borrador = $turnos->contains(fn ($t) => $t->pt_estado === 'BORRADOR');
        $guardia = $turnos->contains(fn ($t) => (bool) $t->es_guardia);
        $categoria = $ausencias !== [] ? 'conflicto' : ($borrador ? 'borrador' : ($guardia ? 'guardia' : 'turno'));
        $linea2 = match ($categoria) {
            'conflicto' => (string) $ausencias[0]['abreviatura'],
            'borrador' => 'BOR',
            'guardia' => 'G',
            default => '',
        };
        $estado = $turnos->pluck('estado')->unique()->map(fn ($e) => ucfirst(strtolower((string) $e)))->implode(', ');

        return [
            'vacia' => false, 'asistencia_id' => null, 'turno' => $codigos, 'estado_codigo' => strtoupper($categoria),
            'estado_nombre' => self::CATEGORIAS_PROGRAMACION[$categoria][1]." ({$estado})",
            'abreviatura' => self::CATEGORIAS_PROGRAMACION[$categoria][0], 'categoria' => $categoria, 'linea1' => $codigos, 'linea2' => $linea2,
            'minutos_tardanza' => 0, 'minutos_falta' => 0, 'minutos_extra' => 0, 'minutos_trabajados' => 0,
            'hora_entrada' => substr((string) $turnos->first()->entrada, 0, 5), 'hora_salida' => substr((string) $turnos->last()->salida, 0, 5),
            'observacion' => $ausencias === [] ? null : 'Ausencia aprobada: '.collect($ausencias)->map(fn ($a) => $a['nombre'].($a['detalle'] ? " ({$a['detalle']})" : ''))->implode('; '),
            'origen' => null,
        ];
    }

    /**
     * @param  list<array<string,string|null>>  $ausencias  ya ordenadas por prioridad
     * @return array<string,mixed>
     */
    private function celdaAusencia(array $ausencias): array
    {
        if ($ausencias === []) {
            return ['vacia' => true];
        }

        $principal = $ausencias[0];
        $otras = count($ausencias) - 1;

        return [
            'vacia' => false, 'asistencia_id' => null, 'turno' => null, 'estado_codigo' => strtoupper((string) $principal['categoria']),
            'estado_nombre' => collect($ausencias)->pluck('nombre')->unique()->implode(' + '),
            'abreviatura' => $principal['abreviatura'], 'categoria' => $principal['categoria'], 'linea1' => $principal['abreviatura'], 'linea2' => $otras > 0 ? '+'.$otras : '',
            'minutos_tardanza' => 0, 'minutos_falta' => 0, 'minutos_extra' => 0, 'minutos_trabajados' => 0,
            'hora_entrada' => $principal['desde'], 'hora_salida' => $principal['hasta'],
            'observacion' => collect($ausencias)->map(fn ($a) => $a['detalle'])->filter()->implode('; ') ?: null, 'origen' => null,
        ];
    }

    /**
     * Turnos programados por "vinculo|fecha". Se excluyen los anulados y las programaciones anuladas.
     *
     * @param  list<int>  $ids
     * @return Collection<string,Collection<int,object>>
     */
    private function turnosProgramados(array $ids, CarbonImmutable $primero, CarbonImmutable $ultimo): Collection
    {
        return DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'tp.TurnoId')
            ->whereIn('pt.VinculoLaboralId', $ids)->whereIn('pt.ProgramacionTrabajadorEstado', ['BORRADOR', 'PUBLICADA', 'CERRADA'])
            ->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')
            ->whereDate('tp.TurnoProgramadoFecha', '>=', $primero->toDateString())->whereDate('tp.TurnoProgramadoFecha', '<=', $ultimo->toDateString())
            ->orderBy('t.TurnoHoraEntrada')
            ->get(['pt.VinculoLaboralId as vinculo', 'tp.TurnoProgramadoFecha as fecha', 't.TurnoCodigo as codigo', 't.TurnoNombre as nombre', 'tp.TurnoProgramadoEsGuardia as es_guardia',
                'tp.TurnoProgramadoEstado as estado', 'pt.ProgramacionTrabajadorEstado as pt_estado',
                DB::raw('COALESCE(tp.TurnoProgramadoHoraEntrada, t.TurnoHoraEntrada) as entrada'), DB::raw('COALESCE(tp.TurnoProgramadoHoraSalida, t.TurnoHoraSalida) as salida')])
            ->groupBy(fn ($r) => $r->vinculo.'|'.substr((string) $r->fecha, 0, 10));
    }

    /**
     * Ausencias aprobadas por "vinculo|fecha", ordenadas por prioridad (descanso medico, licencia, vacaciones, rol, papeleta).
     *
     * @param  list<int>  $ids
     * @return Collection<string,list<array<string,string|null>>>
     */
    public function ausenciasPorDia(array $ids, CarbonImmutable $primero, CarbonImmutable $ultimo): Collection
    {
        $d = $primero->toDateString();
        $h = $ultimo->toDateString();
        $hora = fn ($v) => $v === null ? null : (strlen((string) $v) > 8 ? substr((string) $v, 11, 5) : substr((string) $v, 0, 5));
        $rangos = [];

        foreach (DB::table('Solicitudes.DescansoMedico')->whereIn('VinculoLaboralId', $ids)->where('DescansoMedicoEstado', 'APROBADO')
            ->whereDate('DescansoMedicoFechaInicio', '<=', $h)->whereDate('DescansoMedicoFechaFin', '>=', $d)->get() as $r) {
            $rangos[] = ['vinculo' => $r->VinculoLaboralId, 'ini' => $r->DescansoMedicoFechaInicio, 'fin' => $r->DescansoMedicoFechaFin, 'categoria' => 'descanso_medico', 'nombre' => 'Descanso médico',
                'detalle' => $r->DescansoMedicoNumeroCitt ? "CITT {$r->DescansoMedicoNumeroCitt}" : null, 'desde' => null, 'hasta' => null];
        }

        $licencias = DB::table('Solicitudes.Licencia as l')->join('Solicitudes.TipoLicencia as tl', 'tl.TipoLicenciaId', '=', 'l.TipoLicenciaId')
            ->whereIn('l.VinculoLaboralId', $ids)->where('l.LicenciaEstado', 'APROBADO')
            ->whereDate('l.LicenciaFechaInicio', '<=', $h)->whereDate('l.LicenciaFechaFin', '>=', $d)
            ->get(['l.VinculoLaboralId', 'l.LicenciaFechaInicio', 'l.LicenciaFechaFin', 'tl.TipoLicenciaNombre']);
        foreach ($licencias as $r) {
            $rangos[] = ['vinculo' => $r->VinculoLaboralId, 'ini' => $r->LicenciaFechaInicio, 'fin' => $r->LicenciaFechaFin, 'categoria' => 'licencia', 'nombre' => 'Licencia', 'detalle' => $r->TipoLicenciaNombre, 'desde' => null, 'hasta' => null];
        }

        $goces = DB::table('Vacaciones.GoceVacacional as g')->join('Vacaciones.RolVacacional as r', 'r.RolVacacionalId', '=', 'g.RolVacacionalId')
            ->join('Vacaciones.PeriodoVacacional as p', 'p.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
            ->whereIn('p.VinculoLaboralId', $ids)->where('g.GoceVacacionalEstado', 'APROBADO')
            ->whereDate('g.GoceVacacionalFechaInicio', '<=', $h)->whereDate('g.GoceVacacionalFechaFin', '>=', $d)
            ->get(['p.VinculoLaboralId', 'g.GoceVacacionalFechaInicio', 'g.GoceVacacionalFechaFin', 'g.GoceVacacionalDias']);
        foreach ($goces as $r) {
            $rangos[] = ['vinculo' => $r->VinculoLaboralId, 'ini' => $r->GoceVacacionalFechaInicio, 'fin' => $r->GoceVacacionalFechaFin, 'categoria' => 'vacaciones', 'nombre' => 'Vacaciones', 'detalle' => "{$r->GoceVacacionalDias} días", 'desde' => null, 'hasta' => null];
        }

        $roles = DB::table('Vacaciones.RolVacacional as r')->join('Vacaciones.PeriodoVacacional as p', 'p.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
            ->whereIn('p.VinculoLaboralId', $ids)->whereIn('r.RolVacacionalEstado', ['PROGRAMADO', 'REPROGRAMADO'])
            ->whereDate('r.RolVacacionalFechaProgramada', '<=', $h)->whereDate('r.RolVacacionalFechaFinProgramada', '>=', $d)
            ->get(['p.VinculoLaboralId', 'r.RolVacacionalFechaProgramada', 'r.RolVacacionalFechaFinProgramada', 'r.RolVacacionalDias']);
        foreach ($roles as $r) {
            $rangos[] = ['vinculo' => $r->VinculoLaboralId, 'ini' => $r->RolVacacionalFechaProgramada, 'fin' => $r->RolVacacionalFechaFinProgramada, 'categoria' => 'vacaciones_prog', 'nombre' => 'Vacaciones programadas',
                'detalle' => "{$r->RolVacacionalDias} días, sin goce aprobado", 'desde' => null, 'hasta' => null];
        }

        $papeletas = DB::table('Solicitudes.Papeleta as p')->join('Solicitudes.TipoPapeleta as tp', 'tp.TipoPapeletaId', '=', 'p.TipoPapeletaId')
            ->whereIn('p.VinculoLaboralId', $ids)->where('p.PapeletaEstado', 'APROBADO')
            ->whereDate('p.PapeletaFecha', '>=', $d)->whereDate('p.PapeletaFecha', '<=', $h)
            ->get(['p.VinculoLaboralId', 'p.PapeletaFecha', 'p.PapeletaEsDiaCompleto', 'p.PapeletaHoraSalida', 'p.PapeletaHoraRetorno', 'tp.TipoPapeletaNombre']);
        foreach ($papeletas as $r) {
            $completo = (bool) $r->PapeletaEsDiaCompleto;
            $rangos[] = ['vinculo' => $r->VinculoLaboralId, 'ini' => $r->PapeletaFecha, 'fin' => $r->PapeletaFecha, 'categoria' => 'papeleta', 'nombre' => 'Papeleta',
                'detalle' => $r->TipoPapeletaNombre.($completo ? ' (día completo)' : ''), 'desde' => $completo ? null : $hora($r->PapeletaHoraSalida), 'hasta' => $completo ? null : $hora($r->PapeletaHoraRetorno)];
        }

        $prioridad = array_flip(array_keys(self::CATEGORIAS_AUSENCIA));
        $porDia = [];
        foreach ($rangos as $r) {
            $inicio = max(CarbonImmutable::parse(substr((string) $r['ini'], 0, 10)), $primero);
            $fin = min(CarbonImmutable::parse(substr((string) $r['fin'], 0, 10)), $ultimo);
            for ($f = $inicio; $f <= $fin; $f = $f->addDay()) {
                $porDia[$r['vinculo'].'|'.$f->toDateString()][] = [
                    'categoria' => $r['categoria'], 'abreviatura' => self::CATEGORIAS_AUSENCIA[$r['categoria']][0], 'nombre' => $r['nombre'],
                    'detalle' => $r['detalle'], 'desde' => $r['desde'], 'hasta' => $r['hasta'],
                ];
            }
        }

        return collect($porDia)->map(fn (array $lista) => collect($lista)->sortBy(fn ($a) => $prioridad[$a['categoria']])->values()->all());
    }
}
