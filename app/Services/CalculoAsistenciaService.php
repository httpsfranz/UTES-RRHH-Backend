<?php

namespace App\Services;

use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Motor de calculo de la asistencia diaria: convierte lo que ocurrio (marcaciones, solicitudes aprobadas) frente a lo que
 * se esperaba (turno programado u horario asignado) en una fila de Asistencia.AsistenciaDiaria por vinculo y fecha.
 *
 * Que se espera ese dia (RIT, Art. 16):
 *   1. Los turnos programados (programacion PUBLICADA o CERRADA, turno no anulado). Si el vinculo tiene programacion
 *      publicada que cubre la fecha pero ningun turno ese dia, es su dia libre: no hay obligacion ni fila.
 *   2. Si no tiene programacion, el horario asignado (AsignacionHorario) segun el dia de la semana. En un dia del
 *      calendario no laborable (feriado) este personal no concurre: estado NO_LABORABLE. El personal programado si
 *      concurre en feriados (Art. 16.b: el feriado no reduce la jornada; Art. 19: ingresa el programado).
 *
 * Que paso (en este orden de prioridad):
 *   a. Cobertura aprobada: vacaciones, descanso medico, licencia o papeleta de dia completo.
 *   b. Sin ninguna marca: falta (la guardia no realizada equivale a dos dias, Art. 20 y 22).
 *   c. Falta la marca de entrada o de salida: omision de marcacion = inasistencia injustificada (Art. 22 y 23 c).
 *   d. Entrada: se mide el atraso en minutos contra la escala del Art. 22 (tolerancia, descuento de 10/20/30 minutos y
 *      desde el minuto 31 inasistencia). El SERUMS equivalente y el interno no tienen tolerancia: los minutos que
 *      exceden se devuelven (Art. 22) y se registran completos.
 *   e. Salida antes de la hora sin papeleta que la autorice: inasistencia (Art. 23 b).
 *   f. Una falta con justificacion APROBADA que cubre la fecha queda FALTA_JUST y enlazada.
 *
 * MinutosTardanza guarda los minutos EQUIVALENTES PARA DESCUENTO del Art. 22 (0, 10, 20 o 30), no los minutos de reloj
 * (esos quedan en la observacion). El sobretiempo solo se reconoce con una compensacion horaria autorizada (Art. 17).
 *
 * El calculo es idempotente y solo escribe las filas que el mismo motor genero (AsistenciaDiariaOrigen = CALCULADO):
 * las digitadas o corregidas a mano (MANUAL) se respetan salvo que se pida sobrescribirlas. Un periodo de asistencia CERRADO no se toca.
 */
class CalculoAsistenciaService
{
    /** Cuanto despues de la hora de salida se considera que el dia ya termino y una marca ausente es omision. */
    public const MARGEN_CIERRE_MINUTOS = 180;

    /** Cuanto antes de la hora de entrada se acepta una marca de entrada como del turno. */
    public const MARGEN_ENTRADA_MINUTOS = 240;

    /** Permanencia posterior a la salida (no guardia) a partir de la cual se advierte el sobretiempo no autorizado. */
    public const UMBRAL_AVISO_SOBRETIEMPO = 15;

    /** Condiciones sin tolerancia (RIT, Art. 22). */
    private const SIN_TOLERANCIA = ['SERUMS_EQUIV', 'INTERNO'];

    private const FALTAS = ['FALTA', 'OMISION_MARCA', 'FALTA_GUARDIA'];

    /** @var array<string,array{id:int,es_falta:bool,es_descontable:bool}> */
    private array $estados = [];

    /** @var array<int,array<string,list<array<string,mixed>>>> tabla de tolerancia => tipo => tramos */
    private array $tramos = [];

    /** @var list<array{inicio:string,fin:string}> */
    private array $periodosCerrados = [];

    private bool $refrigerioDescuenta = false;

    /**
     * Calcula la asistencia diaria del rango. Las fechas futuras se ignoran; la de hoy solo se evalua en lo que ya termino.
     *
     * @return array{evaluados:int, creados:int, actualizados:int, sin_cambios:int, pendientes:int, omitidos_manuales:int, omitidos_periodo_cerrado:int, por_estado:array<string,int>}
     */
    public function calcular(string $desde, string $hasta, ?int $vinculoId = null, ?int $eessId = null, bool $sobrescribirManuales = false): array
    {
        $resumen = ['evaluados' => 0, 'creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'pendientes' => 0,
            'omitidos_manuales' => 0, 'omitidos_periodo_cerrado' => 0, 'por_estado' => []];

        $ahora = HoraLocal::ahora();
        $inicio = CarbonImmutable::parse($desde)->startOfDay();
        $fin = min(CarbonImmutable::parse($hasta)->startOfDay(), $ahora->startOfDay());
        if ($fin < $inicio) {
            return $resumen;
        }

        $this->cargarCatalogos();

        $vinculos = DB::table('Personal.VinculoLaboral as vl')
            ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'vl.EessId')
            ->join('Personal.CondicionLaboral as c', 'c.CondicionLaboralId', '=', 'vl.CondicionLaboralId')
            ->where('vl.VinculoLaboralEstado', 1)
            ->whereDate('vl.VinculoLaboralFechaInicio', '<=', $fin->toDateString())
            ->where(fn ($q) => $q->whereNull('vl.VinculoLaboralFechaFin')->orWhereDate('vl.VinculoLaboralFechaFin', '>=', $inicio->toDateString()))
            ->when($vinculoId, fn ($q) => $q->where('vl.VinculoLaboralId', $vinculoId))
            ->when($eessId, fn ($q) => $q->where('vl.EessId', $eessId))
            ->get(['vl.VinculoLaboralId as id', 'vl.VinculoLaboralFechaInicio as inicio', 'vl.VinculoLaboralFechaFin as fin', 'e.MicroredId as microred', 'c.CondicionLaboralCodigo as condicion']);

        foreach ($vinculos->chunk(150) as $lote) {
            DB::transaction(function () use ($lote, $inicio, $fin, $ahora, $sobrescribirManuales, &$resumen) {
                $this->calcularLote($lote, $inicio, $fin, $ahora, $sobrescribirManuales, $resumen);
            });
        }

        return $resumen;
    }

    private function cargarCatalogos(): void
    {
        $this->estados = [];
        foreach (DB::table('Asistencia.EstadoAsistencia')->get() as $e) {
            $this->estados[$e->EstadoAsistenciaCodigo] = [
                'id' => (int) $e->EstadoAsistenciaId,
                'es_falta' => (bool) $e->EstadoAsistenciaEsFalta,
                'es_descontable' => (bool) $e->EstadoAsistenciaEsDescontable,
            ];
        }
        foreach (['ASISTIO', 'TARDANZA', 'SALIDA_ANTIC', 'FALTA', 'FALTA_JUST', 'OMISION_MARCA', 'FALTA_GUARDIA', 'PAPELETA', 'COMISION', 'LICENCIA', 'DESCANSO_MED', 'VACACIONES', 'NO_LABORABLE', 'CAPACITACION'] as $codigo) {
            if (! isset($this->estados[$codigo])) {
                throw new \DomainException("Falta el estado de asistencia {$codigo}: ejecuta el seeder de catálogos (php artisan db:seed --class=CatalogosSeeder).");
            }
        }

        $this->tramos = [];
        foreach (DB::table('Configuracion.TramoTolerancia')->orderBy('TramoToleranciaMinutosDesde')->get() as $t) {
            $this->tramos[(int) $t->TablaToleranciaId][$t->TramoToleranciaTipo][] = [
                'desde' => (int) $t->TramoToleranciaMinutosDesde,
                'hasta' => $t->TramoToleranciaMinutosHasta === null ? null : (int) $t->TramoToleranciaMinutosHasta,
                'descuento' => $t->TramoToleranciaMinutosDescuento === null ? null : (int) $t->TramoToleranciaMinutosDescuento,
                'inasistencia' => (bool) $t->TramoToleranciaEsInasistencia,
            ];
        }

        $this->periodosCerrados = DB::table('Consolidacion.PeriodoAsistencia')->where('PeriodoAsistenciaEstado', 'CERRADO')
            ->get(['PeriodoAsistenciaFechaInicio', 'PeriodoAsistenciaFechaFin'])
            ->map(fn ($p) => ['inicio' => substr((string) $p->PeriodoAsistenciaFechaInicio, 0, 10), 'fin' => substr((string) $p->PeriodoAsistenciaFechaFin, 0, 10)])->all();

        $this->refrigerioDescuenta = DB::table('Configuracion.ParametroSistema')->where('ParametroSistemaCodigo', 'REFRIGERIO_DESCUENTA_JORNADA')->value('ParametroSistemaValor') === '1';
    }

    private function periodoCerrado(string $fecha): bool
    {
        foreach ($this->periodosCerrados as $p) {
            if ($fecha >= $p['inicio'] && $fecha <= $p['fin']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int,object>  $vinculos
     * @param  array<string,mixed>  $resumen
     */
    private function calcularLote(Collection $vinculos, CarbonImmutable $inicio, CarbonImmutable $fin, CarbonImmutable $ahora, bool $sobrescribir, array &$resumen): void
    {
        $ids = $vinculos->pluck('id')->all();
        $d = $inicio->toDateString();
        $h = $fin->toDateString();
        $dia = fn (string $col) => fn ($q) => $q->whereDate($col, '>=', $d)->whereDate($col, '<=', $h);

        $programados = DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'tp.TurnoId')
            ->whereIn('pt.VinculoLaboralId', $ids)
            ->whereIn('pt.ProgramacionTrabajadorEstado', ['PUBLICADA', 'CERRADA'])
            ->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')
            ->where($dia('tp.TurnoProgramadoFecha'))
            ->get(['pt.VinculoLaboralId as vinculo', 'tp.TurnoProgramadoId as id', 'tp.TurnoProgramadoFecha as fecha',
                DB::raw('COALESCE(tp.TurnoProgramadoHoraEntrada, t.TurnoHoraEntrada) as entrada'),
                DB::raw('COALESCE(tp.TurnoProgramadoHoraSalida, t.TurnoHoraSalida) as salida'),
                'tp.TurnoProgramadoEsGuardia as guardia', 't.TurnoEsGuardia as turno_guardia', 't.TablaToleranciaId as tabla',
                't.TurnoToleranciaEntradaMinutos as tol_entrada', 't.TurnoToleranciaSalidaMinutos as tol_salida', 't.TurnoRefrigerioMinutos as refrigerio'])
            ->groupBy(fn ($r) => $r->vinculo.'|'.substr((string) $r->fecha, 0, 10));

        // Programaciones publicadas que cubren cada fecha: quien las tiene y no figura en un dia, descansa.
        $conProgramacion = DB::table('Programacion.ProgramacionTrabajador as pt')
            ->join('Programacion.ProgramacionPeriodo as pp', 'pp.ProgramacionPeriodoId', '=', 'pt.ProgramacionPeriodoId')
            ->whereIn('pt.VinculoLaboralId', $ids)
            ->whereIn('pt.ProgramacionTrabajadorEstado', ['PUBLICADA', 'CERRADA'])
            ->whereDate('pp.ProgramacionPeriodoFechaInicio', '<=', $h)->whereDate('pp.ProgramacionPeriodoFechaFin', '>=', $d)
            ->get(['pt.VinculoLaboralId as vinculo', 'pp.ProgramacionPeriodoFechaInicio as ini', 'pp.ProgramacionPeriodoFechaFin as fin'])
            ->groupBy('vinculo');

        $horarios = DB::table('Personal.AsignacionHorario as ah')
            ->join('Configuracion.HorarioDetalle as hd', 'hd.HorarioId', '=', 'ah.HorarioId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'hd.TurnoId')
            ->whereIn('ah.VinculoLaboralId', $ids)->where('ah.AsignacionHorarioEstado', 1)->where('hd.HorarioDetalleEsDescanso', 0)
            ->whereDate('ah.AsignacionHorarioFechaInicio', '<=', $h)
            ->where(fn ($q) => $q->whereNull('ah.AsignacionHorarioFechaFin')->orWhereDate('ah.AsignacionHorarioFechaFin', '>=', $d))
            ->get(['ah.VinculoLaboralId as vinculo', 'ah.AsignacionHorarioFechaInicio as ini', 'ah.AsignacionHorarioFechaFin as fin', 'hd.HorarioDetalleDia as dia',
                'hd.TurnoId as id', 't.TurnoHoraEntrada as entrada', 't.TurnoHoraSalida as salida', 't.TurnoEsGuardia as turno_guardia', 't.TablaToleranciaId as tabla',
                't.TurnoToleranciaEntradaMinutos as tol_entrada', 't.TurnoToleranciaSalidaMinutos as tol_salida', 't.TurnoRefrigerioMinutos as refrigerio'])
            ->groupBy('vinculo');

        $noLaborables = DB::table('Soporte.CalendarioNoLaborable')->where('CalendarioNoLaborableEstado', 1)->where($dia('CalendarioNoLaborableFecha'))
            ->get(['CalendarioNoLaborableFecha as fecha', 'MicroredId as microred'])
            ->groupBy(fn ($r) => substr((string) $r->fecha, 0, 10));

        $vacaciones = DB::table('Vacaciones.GoceVacacional as g')
            ->join('Vacaciones.RolVacacional as r', 'r.RolVacacionalId', '=', 'g.RolVacacionalId')
            ->join('Vacaciones.PeriodoVacacional as p', 'p.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
            ->whereIn('p.VinculoLaboralId', $ids)->where('g.GoceVacacionalEstado', 'APROBADO')
            ->whereDate('g.GoceVacacionalFechaInicio', '<=', $h)->whereDate('g.GoceVacacionalFechaFin', '>=', $d)
            ->get(['p.VinculoLaboralId as vinculo', 'g.GoceVacacionalFechaInicio as ini', 'g.GoceVacacionalFechaFin as fin'])->groupBy('vinculo');

        $descansos = DB::table('Solicitudes.DescansoMedico')->whereIn('VinculoLaboralId', $ids)->where('DescansoMedicoEstado', 'APROBADO')
            ->whereDate('DescansoMedicoFechaInicio', '<=', $h)->whereDate('DescansoMedicoFechaFin', '>=', $d)
            ->get(['VinculoLaboralId as vinculo', 'DescansoMedicoFechaInicio as ini', 'DescansoMedicoFechaFin as fin'])->groupBy('vinculo');

        $licencias = DB::table('Solicitudes.Licencia')->whereIn('VinculoLaboralId', $ids)->where('LicenciaEstado', 'APROBADO')
            ->whereDate('LicenciaFechaInicio', '<=', $h)->whereDate('LicenciaFechaFin', '>=', $d)
            ->get(['VinculoLaboralId as vinculo', 'LicenciaFechaInicio as ini', 'LicenciaFechaFin as fin'])->groupBy('vinculo');

        $papeletas = DB::table('Solicitudes.Papeleta as p')
            ->join('Solicitudes.TipoPapeleta as tp', 'tp.TipoPapeletaId', '=', 'p.TipoPapeletaId')
            ->whereIn('p.VinculoLaboralId', $ids)->where('p.PapeletaEstado', 'APROBADO')->where($dia('p.PapeletaFecha'))
            ->get(['p.VinculoLaboralId as vinculo', 'p.PapeletaFecha as fecha', 'p.PapeletaHoraSalida as salida', 'p.PapeletaHoraRetorno as retorno',
                'p.PapeletaEsDiaCompleto as dia_completo', 'tp.TipoPapeletaCodigo as tipo'])
            ->groupBy(fn ($r) => $r->vinculo.'|'.substr((string) $r->fecha, 0, 10));

        $justificaciones = DB::table('Asistencia.JustificacionFalta')->whereIn('VinculoLaboralId', $ids)->where('JustificacionFaltaEstado', 'APROBADO')
            ->whereDate('JustificacionFaltaFechaInicio', '<=', $h)->whereDate('JustificacionFaltaFechaFin', '>=', $d)
            ->get(['JustificacionFaltaId as id', 'VinculoLaboralId as vinculo', 'JustificacionFaltaFechaInicio as ini', 'JustificacionFaltaFechaFin as fin'])->groupBy('vinculo');

        $marcaciones = DB::table('Asistencia.Marcacion')->whereIn('VinculoLaboralId', $ids)->where('MarcacionEsValida', 1)
            ->whereIn('MarcacionTipo', ['ENTRADA', 'SALIDA'])
            ->where('MarcacionFechaHora', '>=', $inicio->subDay()->toDateTimeString())->where('MarcacionFechaHora', '<', $fin->addDays(2)->toDateTimeString())
            ->orderBy('MarcacionFechaHora')
            ->get(['VinculoLaboralId as vinculo', 'MarcacionFechaHora as momento', 'MarcacionTipo as tipo'])
            ->map(fn ($m) => (object) ['vinculo' => $m->vinculo, 'momento' => CarbonImmutable::parse($m->momento), 'tipo' => $m->tipo])
            ->groupBy('vinculo');

        $existentes = DB::table('Asistencia.AsistenciaDiaria')->whereIn('VinculoLaboralId', $ids)->where($dia('AsistenciaDiariaFecha'))->get()
            ->keyBy(fn ($r) => $r->VinculoLaboralId.'|'.substr((string) $r->AsistenciaDiariaFecha, 0, 10));

        $compensaciones = DB::table('Compensaciones.CompensacionHoraria as c')
            ->join('Compensaciones.TipoCompensacion as t', 't.TipoCompensacionId', '=', 'c.TipoCompensacionId')
            ->whereIn('c.AsistenciaDiariaId', $existentes->pluck('AsistenciaDiariaId')->all() ?: [0])
            ->where('t.TipoCompensacionCodigo', 'HORA_EXTRA')->whereIn('c.CompensacionHorariaEstado', ['APROBADO', 'CONSUMIDO'])
            ->get(['c.AsistenciaDiariaId as dia', 'c.CompensacionHorariaHorasGeneradas as horas'])->groupBy('dia');

        $ahoraTexto = $ahora->toDateTimeString();

        foreach ($vinculos as $v) {
            for ($fecha = $inicio; $fecha <= $fin; $fecha = $fecha->addDay()) {
                $f = $fecha->toDateString();
                if ($f < substr((string) $v->inicio, 0, 10) || ($v->fin !== null && $f > substr((string) $v->fin, 0, 10))) {
                    continue;
                }

                $esperados = $this->turnosEsperados($v, $f, $programados, $conProgramacion, $horarios);
                if ($esperados === null) {
                    continue;
                }

                $resumen['evaluados']++;
                if ($this->periodoCerrado($f)) {
                    $resumen['omitidos_periodo_cerrado']++;

                    continue;
                }
                $clave = $v->id.'|'.$f;
                $existente = $existentes->get($clave);
                if ($existente && $existente->AsistenciaDiariaOrigen !== 'CALCULADO' && ! $sobrescribir) {
                    $resumen['omitidos_manuales']++;

                    continue;
                }

                $resultado = $this->evaluarDia(
                    $v, $f, $esperados, $ahora,
                    $marcaciones->get($v->id, collect()),
                    $this->cobertura($v->id, $f, $vacaciones, $descansos, $licencias, $papeletas->get($clave, collect())),
                    $papeletas->get($clave, collect()),
                    $this->justificacionQueCubre($justificaciones->get($v->id, collect()), $f),
                    $this->esNoLaborable($noLaborables->get($f, collect()), $v->microred),
                    $existente ? (int) $existente->AsistenciaDiariaId : null,
                    $compensaciones,
                );

                if ($resultado === null) {
                    $resumen['pendientes']++;

                    continue;
                }

                $resultado['VinculoLaboralId'] = $v->id;
                $resultado['AsistenciaDiariaFecha'] = $f;
                $codigo = $resultado['_codigo'];
                unset($resultado['_codigo']);
                $resumen['por_estado'][$codigo] = ($resumen['por_estado'][$codigo] ?? 0) + 1;

                if ($existente === null) {
                    DB::table('Asistencia.AsistenciaDiaria')->insert($resultado + ['AsistenciaDiariaFechaProceso' => $ahoraTexto, 'AsistenciaDiariaOrigen' => 'CALCULADO']);
                    $resumen['creados']++;
                } elseif ($this->igual($existente, $resultado) && $existente->AsistenciaDiariaOrigen === 'CALCULADO') {
                    $resumen['sin_cambios']++;
                } else {
                    DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaId', $existente->AsistenciaDiariaId)
                        ->update($resultado + ['AsistenciaDiariaFechaProceso' => $ahoraTexto, 'AsistenciaDiariaOrigen' => 'CALCULADO']);
                    $resumen['actualizados']++;
                }
            }
        }
    }

    /** @param array<string,mixed> $nuevo */
    private function igual(object $existente, array $nuevo): bool
    {
        foreach ($nuevo as $columna => $valor) {
            $actual = $existente->{$columna} ?? null;
            if (str_contains($columna, 'HoraEntrada') || str_contains($columna, 'HoraSalida')) {
                $actual = $actual === null ? null : substr((string) $actual, 0, 19);
            }
            if ((string) $actual !== (string) $valor) {
                return false;
            }
        }

        return true;
    }

    /**
     * Turnos que el vinculo debe cumplir ese dia, o null si no tiene obligacion (dia libre o sin horario).
     *
     * @return array{turnos:list<array<string,mixed>>, programado:bool}|null
     */
    private function turnosEsperados(object $v, string $fecha, Collection $programados, Collection $conProgramacion, Collection $horarios): ?array
    {
        $delDia = $programados->get($v->id.'|'.$fecha);
        if ($delDia !== null && $delDia->isNotEmpty()) {
            return ['programado' => true, 'turnos' => $delDia->map(fn ($t) => $this->turno($t, $fecha, true))->sortBy('inicio')->values()->all()];
        }

        $programacionCubre = $conProgramacion->get($v->id, collect())
            ->contains(fn ($p) => $fecha >= substr((string) $p->ini, 0, 10) && $fecha <= substr((string) $p->fin, 0, 10));
        if ($programacionCubre) {
            return null;
        }

        $diaSemana = CarbonImmutable::parse($fecha)->dayOfWeekIso;
        $turnos = $horarios->get($v->id, collect())
            ->filter(fn ($h) => (int) $h->dia === $diaSemana && $fecha >= substr((string) $h->ini, 0, 10) && ($h->fin === null || $fecha <= substr((string) $h->fin, 0, 10)))
            ->map(fn ($t) => $this->turno($t, $fecha, false))->sortBy('inicio')->values()->all();

        return $turnos === [] ? null : ['programado' => false, 'turnos' => $turnos];
    }

    /** @return array<string,mixed> */
    private function turno(object $t, string $fecha, bool $programado): array
    {
        $inicio = CarbonImmutable::parse($fecha.' '.substr((string) $t->entrada, 0, 8));
        $salida = CarbonImmutable::parse($fecha.' '.substr((string) $t->salida, 0, 8));
        $fin = $salida <= $inicio ? $salida->addDay() : $salida;

        return [
            'id' => $programado ? (int) $t->id : null,
            'inicio' => $inicio, 'fin' => $fin,
            'guardia' => (bool) ($programado ? ($t->guardia || $t->turno_guardia) : $t->turno_guardia),
            'tabla' => $t->tabla === null ? null : (int) $t->tabla,
            'tol_entrada' => (int) $t->tol_entrada, 'tol_salida' => (int) $t->tol_salida, 'refrigerio' => (int) $t->refrigerio,
        ];
    }

    /** Estado que cubre el dia completo (vacaciones, descanso medico, licencia, papeleta de dia completo) o null. */
    private function cobertura(int $vinculo, string $fecha, Collection $vacaciones, Collection $descansos, Collection $licencias, Collection $papeletas): ?string
    {
        $cubre = fn (Collection $rangos) => $rangos->get($vinculo, collect())
            ->contains(fn ($r) => $fecha >= substr((string) $r->ini, 0, 10) && $fecha <= substr((string) $r->fin, 0, 10));

        if ($cubre($vacaciones)) {
            return 'VACACIONES';
        }
        if ($cubre($descansos)) {
            return 'DESCANSO_MED';
        }
        if ($cubre($licencias)) {
            return 'LICENCIA';
        }
        $completa = $papeletas->firstWhere('dia_completo', 1) ?? $papeletas->first(fn ($p) => (bool) $p->dia_completo);
        if ($completa) {
            return match ($completa->tipo) {
                'COMISION' => 'COMISION',
                'CAPACITACION' => 'CAPACITACION',
                default => 'PAPELETA',
            };
        }

        return null;
    }

    private function justificacionQueCubre(Collection $justificaciones, string $fecha): ?int
    {
        $j = $justificaciones->first(fn ($j) => $fecha >= substr((string) $j->ini, 0, 10) && $fecha <= substr((string) $j->fin, 0, 10));

        return $j ? (int) $j->id : null;
    }

    private function esNoLaborable(Collection $dias, mixed $microred): bool
    {
        return $dias->contains(fn ($d) => $d->microred === null || (int) $d->microred === (int) $microred);
    }

    /**
     * Evalua un dia. Devuelve las columnas de AsistenciaDiaria (mas '_codigo', el codigo del estado) o null si el dia
     * aun no se puede decidir (el turno no ha terminado y falta una marca).
     *
     * @param  array{turnos:list<array<string,mixed>>, programado:bool}  $esperado
     * @return array<string,mixed>|null
     */
    private function evaluarDia(object $v, string $fecha, array $esperado, CarbonImmutable $ahora, Collection $marcas, ?string $cobertura, Collection $papeletas, ?int $justificacionId, bool $noLaborable, ?int $asistenciaId, Collection $compensaciones): ?array
    {
        $turnos = $esperado['turnos'];
        $primero = $turnos[0];
        $inicio = $primero['inicio'];
        $fin = collect($turnos)->max('fin');
        $guardia = collect($turnos)->contains('guardia', true);
        $duracion = (int) collect($turnos)->sum(fn ($t) => $t['inicio']->diffInMinutes($t['fin']));
        $turnoId = $primero['id'];
        $dia = ['TurnoProgramadoId' => $turnoId, 'JustificacionFaltaId' => null, 'AsistenciaDiariaHoraEntrada' => null, 'AsistenciaDiariaHoraSalida' => null,
            'AsistenciaDiariaMinutosTardanza' => 0, 'AsistenciaDiariaMinutosFalta' => 0, 'AsistenciaDiariaMinutosExtra' => 0, 'AsistenciaDiariaMinutosTrabajados' => 0, 'AsistenciaDiariaObservacion' => null];

        // Por referencia: las horas de entrada y salida se asignan a $dia despues de armar esta funcion.
        $resultado = function (string $codigo, array $extra = []) use (&$dia) {
            return ['_codigo' => $codigo, 'EstadoAsistenciaId' => $this->estados[$codigo]['id']] + $extra + $dia;
        };

        // Dia no laborable para quien trabaja por horario (no por programacion).
        if ($noLaborable && ! $esperado['programado']) {
            return $resultado('NO_LABORABLE', ['AsistenciaDiariaObservacion' => 'Día no laborable del calendario (RIT, Art. 19).']);
        }

        // Marcas del turno: la primera entrada desde unas horas antes y la ultima salida hasta unas horas despues.
        $desde = $inicio->subMinutes(self::MARGEN_ENTRADA_MINUTOS);
        $entrada = $marcas->first(fn ($m) => $m->tipo === 'ENTRADA' && $m->momento >= $desde && $m->momento < $fin)?->momento;
        $limiteSalida = $fin->addMinutes(self::MARGEN_CIERRE_MINUTOS);
        $salida = $marcas->last(fn ($m) => $m->tipo === 'SALIDA' && $m->momento > ($entrada ?? $inicio->subMinutes(1)) && $m->momento <= $limiteSalida)?->momento;
        if ($entrada) {
            $dia['AsistenciaDiariaHoraEntrada'] = $entrada->toDateTimeString();
        }
        if ($salida) {
            $dia['AsistenciaDiariaHoraSalida'] = $salida->toDateTimeString();
        }

        if ($cobertura !== null) {
            return $resultado($cobertura, ['AsistenciaDiariaObservacion' => 'Cubierto por una solicitud aprobada ('.strtolower(str_replace('_', ' ', $cobertura)).').']);
        }

        $terminado = $ahora >= $fin->addMinutes(self::MARGEN_CIERRE_MINUTOS);

        // Sin marcas: falta; la guardia no realizada vale dos dias (Art. 20 y 22).
        if (! $entrada && ! $salida) {
            if (! $terminado) {
                return null;
            }
            $codigo = $guardia ? 'FALTA_GUARDIA' : 'FALTA';

            return $this->conJustificacion($resultado($codigo, [
                'AsistenciaDiariaMinutosFalta' => $duracion * ($guardia ? 2 : 1),
                'AsistenciaDiariaObservacion' => $guardia ? 'Guardia no realizada: equivale a dos días de inasistencia (RIT, Art. 20 y 22).' : 'Sin marcación: inasistencia injustificada.',
            ]), $justificacionId);
        }

        // Falta una de las dos marcas: omision de marcacion = inasistencia injustificada (Art. 22 y 23 c).
        if (! $entrada || ! $salida) {
            if (! $terminado) {
                return null;
            }

            return $this->conJustificacion($resultado('OMISION_MARCA', [
                'AsistenciaDiariaMinutosFalta' => $duracion,
                'AsistenciaDiariaObservacion' => 'Omisión de marcación de '.($entrada ? 'salida' : 'entrada').': inasistencia injustificada (RIT, Art. 22 y 23 c).',
            ]), $justificacionId);
        }

        $notas = [];
        $esFalta = false;
        $descuento = 0;
        $tramos = $primero['tabla'] === null ? [] : ($this->tramos[$primero['tabla']] ?? []);
        $sinTolerancia = in_array($v->condicion, self::SIN_TOLERANCIA, true);

        // Entrada: minutos de reloj de atraso (se truncan: 07:35:59 sigue siendo tolerancia).
        $atraso = $entrada > $inicio ? intdiv($inicio->diffInSeconds($entrada), 60) : 0;
        if ($atraso > 0 && $this->papeletaCubreAtraso($papeletas, $fecha, $inicio, $entrada)) {
            $notas[] = "Ingresó {$atraso} min después de la hora, cubierto por papeleta autorizada.";
        } elseif ($atraso > 0 && $sinTolerancia) {
            $descuento = $atraso;
            $notas[] = "Ingresó {$atraso} min tarde; sin tolerancia para su condición: devolución de horas (RIT, Art. 22).";
        } elseif ($atraso > 0) {
            $tramo = $this->tramoPara($tramos['TARDANZA'] ?? [], $atraso, $primero['tol_entrada']);
            if ($tramo === null) {
                $notas[] = "Ingresó {$atraso} min tarde (tolerancia).";
            } elseif ($tramo['inasistencia']) {
                $esFalta = true;
                $notas[] = "Ingresó {$atraso} min tarde: inasistencia injustificada (RIT, Art. 22)".($guardia ? '; falta grave en guardia (Art. 20).' : '.');
            } else {
                $descuento = (int) ($tramo['descuento'] ?? $atraso);
                $notas[] = "Ingresó {$atraso} min tarde: descuento equivalente a {$descuento} min (RIT, Art. 22).";
            }
        }

        // Salida anticipada sin papeleta que la autorice (Art. 23 b).
        $anticipo = $salida < $fin ? intdiv($salida->diffInSeconds($fin), 60) - $primero['tol_salida'] : 0;
        $salidaAnticipada = false;
        if ($anticipo > 0 && ! $this->papeletaCubreSalida($papeletas, $fecha, $salida, $fin)) {
            $tramo = $this->tramoPara($tramos['SALIDA_ANTICIPADA'] ?? [], $anticipo, 0);
            if ($tramo === null || $tramo['inasistencia']) {
                $esFalta = true;
                $notas[] = "Salió {$anticipo} min antes de la hora sin autorización: inasistencia (RIT, Art. 23 b).";
            } else {
                $salidaAnticipada = true;
                $notas[] = "Salió {$anticipo} min antes de la hora sin autorización.";
            }
        } elseif ($anticipo > 0) {
            $notas[] = "Salió {$anticipo} min antes, cubierto por papeleta autorizada.";
        }

        // Minutos trabajados dentro de la jornada programada; el refrigerio se resta solo si el parametro lo pide.
        $ini = $entrada > $inicio ? $entrada : $inicio;
        $term = $salida < $fin ? $salida : $fin;
        $trabajados = $term > $ini ? intdiv($ini->diffInSeconds($term), 60) : 0;
        if ($this->refrigerioDescuenta && $primero['refrigerio'] > 0) {
            $trabajados = max(0, $trabajados - $primero['refrigerio']);
        }

        // Sobretiempo: solo con compensacion horaria autorizada (Art. 17).
        $extra = 0;
        $real = $salida > $fin ? intdiv($fin->diffInSeconds($salida), 60) : 0;
        if ($real > 0 && ! $guardia) {
            $horas = $asistenciaId ? (float) $compensaciones->get($asistenciaId, collect())->sum('horas') : 0.0;
            $extra = $horas > 0 ? min($real, (int) round($horas * 60)) : 0;
            if ($horas <= 0 && $real >= self::UMBRAL_AVISO_SOBRETIEMPO) {
                $notas[] = "Permaneció {$real} min después de la hora sin compensación horaria autorizada (RIT, Art. 17): no se reconoce sobretiempo.";
            }
        }

        $comunes = ['AsistenciaDiariaMinutosTrabajados' => $trabajados, 'AsistenciaDiariaMinutosExtra' => $extra, 'AsistenciaDiariaObservacion' => $notas === [] ? null : implode(' ', $notas)];

        if ($esFalta) {
            return $this->conJustificacion($resultado('FALTA', $comunes + ['AsistenciaDiariaMinutosFalta' => $duracion]), $justificacionId);
        }
        if ($salidaAnticipada) {
            return $resultado('SALIDA_ANTIC', $comunes + ['AsistenciaDiariaMinutosTardanza' => $descuento, 'AsistenciaDiariaMinutosFalta' => $anticipo]);
        }

        return $resultado($descuento > 0 ? 'TARDANZA' : 'ASISTIO', $comunes + ['AsistenciaDiariaMinutosTardanza' => $descuento]);
    }

    /** Una falta con justificacion aprobada que cubre la fecha pasa a FALTA_JUST y queda enlazada (Art. 23). */
    private function conJustificacion(array $dia, ?int $justificacionId): array
    {
        if ($justificacionId === null || ! in_array($dia['_codigo'], self::FALTAS, true)) {
            return $dia;
        }

        return ['_codigo' => 'FALTA_JUST', 'EstadoAsistenciaId' => $this->estados['FALTA_JUST']['id'], 'JustificacionFaltaId' => $justificacionId] + $dia;
    }

    /**
     * Tramo de la escala que contiene los minutos, o null si caen en la tolerancia. Sin escala (turno sin tabla) rige
     * solo la tolerancia propia del turno y lo que la excede se descuenta minuto a minuto.
     *
     * @param  list<array<string,mixed>>  $tramos
     * @return array<string,mixed>|null
     */
    private function tramoPara(array $tramos, int $minutos, int $toleranciaPropia): ?array
    {
        if ($tramos === []) {
            return $minutos > $toleranciaPropia ? ['inasistencia' => false, 'descuento' => $minutos] : null;
        }
        foreach ($tramos as $t) {
            if ($minutos >= $t['desde'] && ($t['hasta'] === null || $minutos <= $t['hasta'])) {
                return ($t['descuento'] ?? 0) === 0 && ! $t['inasistencia'] ? null : $t;
            }
        }

        return null;
    }

    /** La papeleta aprobada que arranca antes de la hora de entrada y retorna cuando el trabajador ya marco justifica el atraso. */
    private function papeletaCubreAtraso(Collection $papeletas, string $fecha, CarbonImmutable $inicio, CarbonImmutable $entrada): bool
    {
        return $papeletas->contains(function ($p) use ($fecha, $inicio, $entrada) {
            if ($p->salida === null || $p->retorno === null) {
                return false;
            }
            $sale = CarbonImmutable::parse($fecha.' '.substr((string) $p->salida, 0, 8));
            $vuelve = CarbonImmutable::parse($fecha.' '.substr((string) $p->retorno, 0, 8));

            return $sale <= $inicio->addMinutes(5) && $vuelve >= $entrada->subMinute();
        });
    }

    /** La papeleta aprobada que sale antes de la salida del trabajador y no tiene retorno (o retorna al cierre) autoriza la salida. */
    private function papeletaCubreSalida(Collection $papeletas, string $fecha, CarbonImmutable $salida, CarbonImmutable $fin): bool
    {
        return $papeletas->contains(function ($p) use ($fecha, $salida, $fin) {
            if ($p->salida === null) {
                return false;
            }
            $sale = CarbonImmutable::parse($fecha.' '.substr((string) $p->salida, 0, 8));
            $vuelve = $p->retorno === null ? null : CarbonImmutable::parse($fecha.' '.substr((string) $p->retorno, 0, 8));

            return $sale <= $salida->addMinute() && ($vuelve === null || $vuelve >= $fin->subMinute());
        });
    }
}
