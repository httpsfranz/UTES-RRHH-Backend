<?php

namespace App\Services;

use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calendario mensual de asistencia de un establecimiento: una fila por trabajador y una columna por dia, como el formato
 * de "Control de Asistencia" de la Red. Es la UNICA fuente de datos de la pantalla (GET /asistencia-diaria/calendario) y
 * del PDF (GET /asistencia-diaria/calendario/pdf): ambos renderizan exactamente este arreglo.
 *
 * Cada celda sale de Asistencia.AsistenciaDiaria (estado, minutos, horas) y del turno esperado ese dia (Turno.TurnoCodigo
 * del turno programado o, sin programacion, del horario asignado). No se inventan estados: la categoria de color se
 * deduce de las banderas del catalogo EstadoAsistencia (EsFalta, EsDescontable, EsLaborable) y las abreviaturas son solo
 * la forma corta de los codigos existentes para que quepan en una columna de 7 mm; la leyenda las explica.
 *
 * Texto de la celda (linea1 / linea2), como en el formato de referencia:
 *   - linea1 = codigo del turno (M, T, N...) o, si no hay turno, la abreviatura del estado;
 *   - linea2 = los minutos de tardanza descontables o, en cualquier otro estado distinto de ASISTIO, su abreviatura.
 */
class CalendarioAsistenciaService
{
    public const DIAS_SEMANA = [1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Ju', 5 => 'Vi', 6 => 'Sa', 7 => 'Do'];

    public const MESES = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

    /** Forma corta de cada codigo de EstadoAsistencia (los codigos largos no caben en la columna de un dia). */
    public const ABREVIATURAS = [
        'ASISTIO' => 'AS', 'TARDANZA' => 'TAR', 'SALIDA_ANTIC' => 'SAL', 'FALTA' => 'FAL', 'FALTA_JUST' => 'FJ', 'OMISION_MARCA' => 'OM',
        'FALTA_GUARDIA' => 'FG', 'PAPELETA' => 'PAP', 'COMISION' => 'COM', 'LICENCIA' => 'LIC', 'DESCANSO_MED' => 'DM', 'VACACIONES' => 'VAC',
        'DESCANSO' => 'DES', 'NO_LABORABLE' => 'NL', 'CAPACITACION' => 'CAP',
    ];

    /** Estados que cubren el dia por una solicitud aprobada (el catalogo no los distingue con una bandera). */
    public const COBERTURAS = ['PAPELETA', 'COMISION', 'LICENCIA', 'DESCANSO_MED', 'VACACIONES', 'CAPACITACION'];

    public const CATEGORIAS = [
        'normal' => 'Asistió',
        'tardanza' => 'Tardanza (con descuento)',
        'salida' => 'Salida anticipada (con descuento)',
        'falta' => 'Inasistencia injustificada',
        'justificada' => 'Falta justificada',
        'cobertura' => 'Cubierto por licencia, vacaciones, papeleta o descanso médico',
        'descanso' => 'Descanso o día no laborable',
        'programado' => 'Turno programado sin registro de asistencia',
    ];

    /**
     * @return array<string,mixed>
     */
    public function armar(int $eessId, int $anio, int $mes, ?string $buscar = null, ?array $soloVinculos = null): array
    {
        $primero = CarbonImmutable::create($anio, $mes, 1)->startOfDay();
        $ultimo = $primero->endOfMonth()->startOfDay();
        $eess = DB::table('Organizacion.EstablecimientoSalud as e')
            ->join('Organizacion.Microred as m', 'm.MicroredId', '=', 'e.MicroredId')
            ->where('e.EessId', $eessId)->first(['e.EessId', 'e.EessCodigo', 'e.EessNombre', 'e.MicroredId', 'm.MicroredNombre']);
        abort_if($eess === null, 404, 'El establecimiento no existe.');

        $dias = $this->dias($primero, $ultimo, (int) $eess->MicroredId);
        $trabajadores = $this->trabajadores($eessId, $primero, $ultimo, $buscar, $soloVinculos);
        $ids = $trabajadores->pluck('vinculo_id')->all();

        $registros = $ids === [] ? collect() : $this->registros($ids, $primero, $ultimo);
        $esperados = $ids === [] ? collect() : $this->turnosEsperados($ids, $primero, $ultimo);
        $catalogo = DB::table('Asistencia.EstadoAsistencia')->orderBy('EstadoAsistenciaId')->get()->keyBy('EstadoAsistenciaCodigo');

        $turnosUsados = [];
        $filas = $trabajadores->values()->map(function (array $t, int $i) use ($dias, $registros, $esperados, $catalogo, &$turnosUsados) {
            $celdas = [];
            foreach ($dias as $dia) {
                $clave = $t['vinculo_id'].'|'.$dia['fecha'];
                $turnos = $esperados->get($clave, []);
                foreach ($turnos as $turno) {
                    $turnosUsados[$turno['codigo']] = $turno;
                }
                $celdas[$dia['fecha']] = $this->celda($registros->get($clave), $turnos, $catalogo);
            }

            return ['n' => $i + 1] + $t + ['celdas' => $celdas];
        })->all();

        return [
            'titulo' => 'ASISTENCIA',
            'entidad' => DB::table('Configuracion.ParametroSistema')->where('ParametroSistemaCodigo', 'ENTIDAD_NOMBRE')->value('ParametroSistemaValor') ?? 'Red de Salud Trujillo',
            'eess' => ['id' => (int) $eess->EessId, 'codigo' => $eess->EessCodigo, 'nombre' => $eess->EessNombre, 'microred' => $eess->MicroredNombre],
            'periodo' => ['anio' => $anio, 'mes' => $mes, 'nombre_mes' => self::MESES[$mes], 'etiqueta' => self::MESES[$mes].' '.$anio, 'dias' => $dias],
            'trabajadores' => $filas,
            'leyenda' => [
                'categorias' => collect(self::CATEGORIAS)->map(fn ($nombre, $clave) => ['clave' => $clave, 'nombre' => $nombre])->values()->all(),
                'estados' => $catalogo->map(fn ($e) => ['codigo' => $e->EstadoAsistenciaCodigo, 'abreviatura' => $this->abreviatura($e->EstadoAsistenciaCodigo), 'nombre' => $e->EstadoAsistenciaNombre, 'categoria' => $this->categoria($e)])->values()->all(),
                'turnos' => collect($turnosUsados)->sortKeys()->values()->all(),
            ],
            'generado_en' => HoraLocal::ahora()->format('Y-m-d H:i:s'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function dias(CarbonImmutable $primero, CarbonImmutable $ultimo, int $microredId): array
    {
        $noLaborables = DB::table('Soporte.CalendarioNoLaborable')
            ->whereDate('CalendarioNoLaborableFecha', '>=', $primero->toDateString())->whereDate('CalendarioNoLaborableFecha', '<=', $ultimo->toDateString())
            ->where(fn ($q) => $q->whereNull('MicroredId')->orWhere('MicroredId', $microredId))
            ->get()->keyBy(fn ($d) => substr((string) $d->CalendarioNoLaborableFecha, 0, 10));

        $dias = [];
        for ($d = $primero; $d <= $ultimo; $d = $d->addDay()) {
            $fecha = $d->toDateString();
            $especial = $noLaborables->get($fecha);
            $dias[] = [
                'dia' => $d->day, 'fecha' => $fecha, 'dia_semana' => self::DIAS_SEMANA[$d->dayOfWeekIso],
                'es_sabado' => $d->dayOfWeekIso === 6, 'es_domingo' => $d->dayOfWeekIso === 7,
                'no_laborable' => $especial ? ['tipo' => $especial->CalendarioNoLaborableTipo, 'descripcion' => $especial->CalendarioNoLaborableDescripcion] : null,
            ];
        }

        return $dias;
    }

    /** @return Collection<int,array<string,mixed>> */
    /** @param  list<int>|null  $soloVinculos  si se indica, solo esos vinculos (el trabajador ve unicamente su fila) */
    public function trabajadores(int $eessId, CarbonImmutable $primero, CarbonImmutable $ultimo, ?string $buscar, ?array $soloVinculos = null): Collection
    {
        return DB::table('Personal.VinculoLaboral as vl')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'vl.TrabajadorId')
            ->join('Personal.Cargo as c', 'c.CargoId', '=', 'vl.CargoId')
            ->join('Personal.CondicionLaboral as cl', 'cl.CondicionLaboralId', '=', 'vl.CondicionLaboralId')
            ->where('vl.EessId', $eessId)
            ->when($soloVinculos !== null, fn ($q) => $q->whereIn('vl.VinculoLaboralId', $soloVinculos))
            ->whereDate('vl.VinculoLaboralFechaInicio', '<=', $ultimo->toDateString())
            ->where(fn ($q) => $q->whereNull('vl.VinculoLaboralFechaFin')->orWhereDate('vl.VinculoLaboralFechaFin', '>=', $primero->toDateString()))
            ->when($buscar, fn ($q) => $q->where(fn ($s) => $s->where('t.TrabajadorNombreCompleto', 'like', "%{$buscar}%")->orWhere('t.TrabajadorNumeroDocumento', 'like', "%{$buscar}%")))
            ->orderBy('t.TrabajadorNombreCompleto')->orderBy('vl.VinculoLaboralId')
            ->get(['vl.VinculoLaboralId as vinculo_id', 't.TrabajadorId as trabajador_id', 't.TrabajadorNumeroDocumento as dni', 't.TrabajadorNombreCompleto as nombre', 'c.CargoNombre as cargo', 'cl.CondicionLaboralNombre as condicion'])
            ->map(fn ($f) => [
                'vinculo_id' => (int) $f->vinculo_id, 'trabajador_id' => (int) $f->trabajador_id, 'dni' => $f->dni,
                'nombre' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $f->nombre))), 'cargo' => $f->cargo, 'condicion' => $f->condicion,
            ]);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<string,object> asistencia diaria por "vinculo|fecha"
     */
    private function registros(array $ids, CarbonImmutable $primero, CarbonImmutable $ultimo): Collection
    {
        return DB::table('Asistencia.AsistenciaDiaria')->whereIn('VinculoLaboralId', $ids)
            ->whereDate('AsistenciaDiariaFecha', '>=', $primero->toDateString())->whereDate('AsistenciaDiariaFecha', '<=', $ultimo->toDateString())
            ->get()->keyBy(fn ($r) => $r->VinculoLaboralId.'|'.substr((string) $r->AsistenciaDiariaFecha, 0, 10));
    }

    /**
     * Turnos que cada vinculo debe cumplir cada dia ("vinculo|fecha" => lista de turnos), con la misma regla del motor de
     * calculo: la programacion publicada manda y, donde no hay, el horario asignado.
     *
     * @param  list<int>  $ids
     * @return Collection<string,list<array<string,string>>>
     */
    private function turnosEsperados(array $ids, CarbonImmutable $primero, CarbonImmutable $ultimo): Collection
    {
        $d = $primero->toDateString();
        $h = $ultimo->toDateString();
        $turno = fn (object $t) => ['codigo' => $t->codigo, 'nombre' => $t->nombre, 'entrada' => substr((string) $t->entrada, 0, 5), 'salida' => substr((string) $t->salida, 0, 5)];

        $programados = DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'tp.TurnoId')
            ->whereIn('pt.VinculoLaboralId', $ids)->whereIn('pt.ProgramacionTrabajadorEstado', ['PUBLICADA', 'CERRADA'])
            ->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')
            ->whereDate('tp.TurnoProgramadoFecha', '>=', $d)->whereDate('tp.TurnoProgramadoFecha', '<=', $h)
            ->orderBy('t.TurnoHoraEntrada')
            ->get(['pt.VinculoLaboralId as vinculo', 'tp.TurnoProgramadoFecha as fecha', 't.TurnoCodigo as codigo', 't.TurnoNombre as nombre',
                DB::raw('COALESCE(tp.TurnoProgramadoHoraEntrada, t.TurnoHoraEntrada) as entrada'), DB::raw('COALESCE(tp.TurnoProgramadoHoraSalida, t.TurnoHoraSalida) as salida')]);

        $conProgramacion = DB::table('Programacion.ProgramacionTrabajador as pt')
            ->join('Programacion.ProgramacionPeriodo as pp', 'pp.ProgramacionPeriodoId', '=', 'pt.ProgramacionPeriodoId')
            ->whereIn('pt.VinculoLaboralId', $ids)->whereIn('pt.ProgramacionTrabajadorEstado', ['PUBLICADA', 'CERRADA'])
            ->whereDate('pp.ProgramacionPeriodoFechaInicio', '<=', $h)->whereDate('pp.ProgramacionPeriodoFechaFin', '>=', $d)
            ->get(['pt.VinculoLaboralId as vinculo', 'pp.ProgramacionPeriodoFechaInicio as ini', 'pp.ProgramacionPeriodoFechaFin as fin'])->groupBy('vinculo');

        $horarios = DB::table('Personal.AsignacionHorario as ah')
            ->join('Configuracion.HorarioDetalle as hd', 'hd.HorarioId', '=', 'ah.HorarioId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'hd.TurnoId')
            ->whereIn('ah.VinculoLaboralId', $ids)->where('ah.AsignacionHorarioEstado', 1)->where('hd.HorarioDetalleEsDescanso', 0)
            ->whereDate('ah.AsignacionHorarioFechaInicio', '<=', $h)
            ->where(fn ($q) => $q->whereNull('ah.AsignacionHorarioFechaFin')->orWhereDate('ah.AsignacionHorarioFechaFin', '>=', $d))
            ->orderBy('t.TurnoHoraEntrada')
            ->get(['ah.VinculoLaboralId as vinculo', 'ah.AsignacionHorarioFechaInicio as ini', 'ah.AsignacionHorarioFechaFin as fin', 'hd.HorarioDetalleDia as dia',
                't.TurnoCodigo as codigo', 't.TurnoNombre as nombre', 't.TurnoHoraEntrada as entrada', 't.TurnoHoraSalida as salida'])->groupBy('vinculo');

        $porProgramacion = $programados->groupBy(fn ($t) => $t->vinculo.'|'.substr((string) $t->fecha, 0, 10))->map(fn ($g) => $g->map($turno)->all());

        $esperados = collect();
        foreach ($ids as $vinculo) {
            for ($f = $primero; $f <= $ultimo; $f = $f->addDay()) {
                $fecha = $f->toDateString();
                $clave = "{$vinculo}|{$fecha}";
                if ($porProgramacion->has($clave)) {
                    $esperados->put($clave, $porProgramacion->get($clave));

                    continue;
                }
                $cubre = $conProgramacion->get($vinculo, collect())->contains(fn ($p) => $fecha >= substr((string) $p->ini, 0, 10) && $fecha <= substr((string) $p->fin, 0, 10));
                if ($cubre) {
                    continue;
                }
                $delHorario = $horarios->get($vinculo, collect())
                    ->filter(fn ($h) => (int) $h->dia === $f->dayOfWeekIso && $fecha >= substr((string) $h->ini, 0, 10) && ($h->fin === null || $fecha <= substr((string) $h->fin, 0, 10)))
                    ->map($turno)->values()->all();
                if ($delHorario !== []) {
                    $esperados->put($clave, $delHorario);
                }
            }
        }

        return $esperados;
    }

    private function abreviatura(string $codigo): string
    {
        return self::ABREVIATURAS[$codigo] ?? strtoupper(substr($codigo, 0, 3));
    }

    /** Categoria de color de un estado, deducida de las banderas del catalogo (no es un catalogo nuevo). */
    private function categoria(object $estado): string
    {
        $codigo = $estado->EstadoAsistenciaCodigo;
        if ($codigo === 'SALIDA_ANTIC') {
            return 'salida';
        }
        if (in_array($codigo, self::COBERTURAS, true)) {
            return 'cobertura';
        }
        if ($estado->EstadoAsistenciaEsFalta) {
            return $estado->EstadoAsistenciaEsDescontable ? 'falta' : 'justificada';
        }
        if (! $estado->EstadoAsistenciaEsLaborable) {
            return 'descanso';
        }

        return $estado->EstadoAsistenciaEsDescontable ? 'tardanza' : 'normal';
    }

    /**
     * @param  list<array<string,string>>  $turnos
     * @param  Collection<string,object>  $catalogo
     * @return array<string,mixed>
     */
    private function celda(?object $registro, array $turnos, Collection $catalogo): array
    {
        $turno = $turnos === [] ? null : implode('+', array_values(array_unique(array_column($turnos, 'codigo'))));
        if ($registro === null) {
            return $turno === null ? ['vacia' => true] : [
                'vacia' => false, 'asistencia_id' => null, 'turno' => $turno, 'estado_codigo' => null, 'estado_nombre' => null, 'abreviatura' => null,
                'categoria' => 'programado', 'linea1' => $turno, 'linea2' => '', 'minutos_tardanza' => 0, 'minutos_falta' => 0, 'minutos_extra' => 0, 'minutos_trabajados' => 0,
                'hora_entrada' => null, 'hora_salida' => null, 'observacion' => null, 'origen' => null,
            ];
        }

        $estado = $catalogo->firstWhere('EstadoAsistenciaId', $registro->EstadoAsistenciaId);
        $codigo = $estado->EstadoAsistenciaCodigo;
        $abreviatura = $this->abreviatura($codigo);
        $tardanza = (int) $registro->AsistenciaDiariaMinutosTardanza;
        $linea2 = $tardanza > 0 ? (string) $tardanza : ($turno !== null && $codigo !== 'ASISTIO' ? $abreviatura : '');

        return [
            'vacia' => false, 'asistencia_id' => (int) $registro->AsistenciaDiariaId, 'turno' => $turno, 'estado_codigo' => $codigo, 'estado_nombre' => $estado->EstadoAsistenciaNombre,
            'abreviatura' => $abreviatura, 'categoria' => $this->categoria($estado), 'linea1' => $turno ?? $abreviatura, 'linea2' => $linea2,
            'minutos_tardanza' => $tardanza, 'minutos_falta' => (int) $registro->AsistenciaDiariaMinutosFalta, 'minutos_extra' => (int) $registro->AsistenciaDiariaMinutosExtra,
            'minutos_trabajados' => (int) $registro->AsistenciaDiariaMinutosTrabajados,
            'hora_entrada' => $registro->AsistenciaDiariaHoraEntrada ? substr((string) $registro->AsistenciaDiariaHoraEntrada, 11, 5) : null,
            'hora_salida' => $registro->AsistenciaDiariaHoraSalida ? substr((string) $registro->AsistenciaDiariaHoraSalida, 11, 5) : null,
            'observacion' => $registro->AsistenciaDiariaObservacion, 'origen' => $registro->AsistenciaDiariaOrigen,
        ];
    }
}
