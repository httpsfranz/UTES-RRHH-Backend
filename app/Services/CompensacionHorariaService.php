<?php

namespace App\Services;

use App\Models\Compensaciones\CompensacionHoraria;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una compensacion horaria: PENDIENTE -> APROBADO -> CONSUMIDO (o VENCIDO / ANULADO).
 * RIT, Art. 17: no procede la compensacion por sobretiempo cuando no fue previamente autorizada por el jefe inmediato.
 */
class CompensacionHorariaService
{
    /** RIT, Art. 17: el trabajo despues de la jornada a compensar debe ser de una hora diaria como minimo. */
    public const MINUTOS_MINIMOS = 60;

    public function __construct(private CalendarioAsistenciaService $calendario, private CalculoAsistenciaService $calculo) {}

    /**
     * Minutos trabajados fuera de la jornada un dia, calculados de las marcaciones (no se digitan):
     *   - con turno ese dia: desde el fin del turno hasta la marca de salida;
     *   - sin turno (descanso, feriado, dia no laborable): todo el tiempo entre la entrada y la salida (Art. 18 y 19).
     *
     * @return array{minutos:int, motivo:string, asistencia_id:int|null, salida:string|null, fin_turno:string|null}
     */
    public function fueraDeJornada(int $vinculoId, string $fecha): array
    {
        $dia = CarbonImmutable::parse($fecha)->startOfDay();
        $turnos = $this->calendario->turnosEsperados([$vinculoId], $dia, $dia)->get("{$vinculoId}|{$fecha}", []);
        $asistenciaId = DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $vinculoId)->whereDate('AsistenciaDiariaFecha', $fecha)->value('AsistenciaDiariaId');
        $marcas = DB::table('Asistencia.Marcacion')->where('VinculoLaboralId', $vinculoId)->where('MarcacionEsValida', 1)->whereIn('MarcacionTipo', ['ENTRADA', 'SALIDA'])
            ->where('MarcacionFechaHora', '>=', $dia->toDateTimeString())->where('MarcacionFechaHora', '<', $dia->addDay()->addHours(12)->toDateTimeString())
            ->orderBy('MarcacionFechaHora')->get(['MarcacionTipo', 'MarcacionFechaHora'])
            ->map(fn ($m) => ['tipo' => $m->MarcacionTipo, 'momento' => CarbonImmutable::parse($m->MarcacionFechaHora)]);
        $vacio = ['minutos' => 0, 'asistencia_id' => $asistenciaId ? (int) $asistenciaId : null, 'salida' => null, 'fin_turno' => null];

        if ($turnos !== []) {
            $fin = collect($turnos)->map(function ($t) use ($fecha) {
                $inicio = CarbonImmutable::parse("{$fecha} {$t['entrada']}");
                $salida = CarbonImmutable::parse("{$fecha} {$t['salida']}");

                return $salida <= $inicio ? $salida->addDay() : $salida;
            })->max();
            $salida = $marcas->last(fn ($m) => $m['tipo'] === 'SALIDA' && $m['momento'] > $fin->subHours(12) && $m['momento'] <= $fin->addHours(12))['momento'] ?? null;
            if ($salida === null) {
                return $vacio + ['motivo' => 'Sin marca de salida.'];
            }
            $minutos = $salida > $fin ? intdiv($fin->diffInSeconds($salida), 60) : 0;

            return ['minutos' => $minutos, 'motivo' => "Salió a las {$salida->format('H:i')}; su turno terminaba a las {$fin->format('H:i')}.",
                'salida' => $salida->format('H:i'), 'fin_turno' => $fin->format('H:i')] + $vacio;
        }

        $delDia = $marcas->filter(fn ($m) => $m['momento']->toDateString() === $fecha);
        $entrada = $delDia->first(fn ($m) => $m['tipo'] === 'ENTRADA')['momento'] ?? null;
        $salida = $entrada ? ($marcas->last(fn ($m) => $m['tipo'] === 'SALIDA' && $m['momento'] > $entrada)['momento'] ?? null) : null;
        if ($entrada === null || $salida === null) {
            return $vacio + ['motivo' => 'Sin turno ese día y sin marcas de entrada y salida.'];
        }

        return ['minutos' => intdiv($entrada->diffInSeconds($salida), 60), 'motivo' => "Trabajó en un día sin turno (descanso o no laborable), de {$entrada->format('H:i')} a {$salida->format('H:i')}.",
            'salida' => $salida->format('H:i'), 'fin_turno' => null] + $vacio;
    }

    /** Horas compensables de unos minutos: menos de una hora no se compensa (Art. 17). */
    public static function horas(int $minutos): float
    {
        return $minutos >= self::MINUTOS_MINIMOS ? round($minutos / 60, 2) : 0.0;
    }

    /**
     * Una compensacion autorizada ANTES del trabajo (con fecha de trabajo) toma sus horas de la asistencia en cuanto ese dia
     * ya ocurrio. Solo cambia mientras esta PENDIENTE: aprobada, sus horas quedan fijas.
     */
    public function recalcular(CompensacionHoraria $compensacion): CompensacionHoraria
    {
        $fecha = $compensacion->CompensacionHorariaFechaTrabajo?->toDateString();
        if ($compensacion->CompensacionHorariaEstado !== 'PENDIENTE' || $fecha === null || $fecha > HoraLocal::hoy()->toDateString()) {
            return $compensacion;
        }
        $calculo = $this->fueraDeJornada($compensacion->VinculoLaboralId, $fecha);
        if ($calculo['asistencia_id'] === null) {
            // El dia aun no tiene asistencia calculada: se calcula ahora para enlazarla.
            $this->calculo->calcular($fecha, $fecha, $compensacion->VinculoLaboralId);
            $calculo = $this->fueraDeJornada($compensacion->VinculoLaboralId, $fecha);
        }
        $cambios = ['CompensacionHorariaHorasGeneradas' => self::horas($calculo['minutos']), 'AsistenciaDiariaId' => $calculo['asistencia_id']];
        if ((float) $compensacion->CompensacionHorariaHorasGeneradas !== $cambios['CompensacionHorariaHorasGeneradas'] || $compensacion->AsistenciaDiariaId !== $cambios['AsistenciaDiariaId']) {
            $compensacion->update($cambios);
        }

        return $compensacion;
    }

    /**
     * Dias con trabajo fuera de la jornada de al menos una hora (potencialmente compensables) en un rango, y si ya tienen
     * una autorizacion previa. Sin autorizacion no proceden (Art. 17), pero se muestran para que el jefe lo sepa.
     *
     * @param  list<int>|null  $eessIds  null = toda la red
     * @return list<array<string,mixed>>
     */
    public function candidatas(?array $eessIds, string $desde, string $hasta): array
    {
        $primero = CarbonImmutable::parse($desde)->startOfDay();
        $ultimo = min(CarbonImmutable::parse($hasta)->startOfDay(), HoraLocal::hoy());
        $vinculos = DB::table('Personal.VinculoLaboral as vl')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'vl.TrabajadorId')
            ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'vl.EessId')
            ->where('vl.VinculoLaboralEstado', 1)->when($eessIds !== null, fn ($q) => $q->whereIn('vl.EessId', $eessIds))
            ->whereExists(fn ($q) => $q->from('Asistencia.Marcacion as m')->whereColumn('m.VinculoLaboralId', 'vl.VinculoLaboralId')->where('m.MarcacionTipo', 'SALIDA')
                ->where('m.MarcacionFechaHora', '>=', $primero->toDateTimeString())->where('m.MarcacionFechaHora', '<', $ultimo->addDays(2)->toDateTimeString()))
            ->get(['vl.VinculoLaboralId as id', 't.TrabajadorNombreCompleto as nombre', 't.TrabajadorNumeroDocumento as dni', 'e.EessNombre as eess'])->keyBy('id');
        if ($vinculos->isEmpty() || $ultimo < $primero) {
            return [];
        }
        $autorizadas = CompensacionHoraria::query()->whereIn('VinculoLaboralId', $vinculos->keys()->all())->where('CompensacionHorariaEstado', '<>', 'ANULADO')
            ->whereNotNull('CompensacionHorariaFechaTrabajo')->whereDate('CompensacionHorariaFechaTrabajo', '>=', $primero->toDateString())->whereDate('CompensacionHorariaFechaTrabajo', '<=', $ultimo->toDateString())
            ->get(['CompensacionHorariaId', 'VinculoLaboralId', 'CompensacionHorariaFechaTrabajo', 'CompensacionHorariaEstado'])
            ->keyBy(fn ($c) => $c->VinculoLaboralId.'|'.$c->CompensacionHorariaFechaTrabajo->toDateString());

        $filas = [];
        foreach ($vinculos as $id => $v) {
            for ($d = $primero; $d <= $ultimo; $d = $d->addDay()) {
                $calculo = $this->fueraDeJornada((int) $id, $d->toDateString());
                if ($calculo['minutos'] < self::MINUTOS_MINIMOS) {
                    continue;
                }
                $autorizacion = $autorizadas->get($id.'|'.$d->toDateString());
                $filas[] = [
                    'vinculo_id' => (int) $id, 'trabajador' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $v->nombre))), 'dni' => $v->dni, 'eess' => $v->eess,
                    'fecha' => $d->toDateString(), 'minutos' => $calculo['minutos'], 'horas' => self::horas($calculo['minutos']), 'motivo' => $calculo['motivo'],
                    'compensacion_id' => $autorizacion?->CompensacionHorariaId, 'estado' => $autorizacion?->CompensacionHorariaEstado,
                    'procede' => $autorizacion !== null,
                ];
            }
        }

        return $filas;
    }

    /** Aprobar exige que el trabajo haya sido autorizado previamente; quien aprueba queda como quien autoriza. */
    public function aprobar(CompensacionHoraria $compensacion, int $usuarioId, ?string $nota = null): CompensacionHoraria
    {
        return DB::transaction(function () use ($compensacion, $usuarioId, $nota) {
            if ($compensacion->CompensacionHorariaEstado !== 'PENDIENTE') {
                throw new DomainException('Solo se pueden aprobar compensaciones pendientes.');
            }
            if (! $compensacion->CompensacionHorariaAutorizadoPreviamente) {
                throw new DomainException('No procede la compensación: el trabajo no fue autorizado previamente por el jefe inmediato (RIT, Art. 17).');
            }
            $this->recalcular($compensacion);
            if ($compensacion->CompensacionHorariaFechaTrabajo !== null && (float) $compensacion->CompensacionHorariaHorasGeneradas < 1) {
                throw new DomainException('Aún no hay horas que validar: según sus marcaciones no trabajó una hora o más fuera de la jornada ese día (RIT, Art. 17).');
            }

            $cambios = ['CompensacionHorariaEstado' => 'APROBADO', 'CompensacionHorariaAutorizadoPor' => $usuarioId];
            if ($nota) {
                $actual = (string) $compensacion->CompensacionHorariaObservacion;
                $cambios['CompensacionHorariaObservacion'] = mb_substr(trim(($actual === '' ? '' : $actual."\n")."Aprobación: {$nota}"), 0, 500);
            }
            $compensacion->update($cambios);

            // El sobretiempo validado pasa a la asistencia del dia (MinutosExtra) sin pulsar "Calcular".
            if ($compensacion->CompensacionHorariaFechaTrabajo !== null) {
                $fecha = $compensacion->CompensacionHorariaFechaTrabajo->toDateString();
                $this->calculo->calcular($fecha, $fecha, $compensacion->VinculoLaboralId);
            }

            return $compensacion->fresh();
        });
    }

    /** Registra horas devueltas; al completar las generadas, la compensacion queda CONSUMIDA. */
    public function devolver(CompensacionHoraria $compensacion, float $horas): CompensacionHoraria
    {
        return DB::transaction(function () use ($compensacion, $horas) {
            if ($compensacion->CompensacionHorariaEstado !== 'APROBADO') {
                throw new DomainException('Solo se devuelven horas de una compensación aprobada.');
            }
            $pendientes = round($compensacion->CompensacionHorariaHorasGeneradas - $compensacion->CompensacionHorariaHorasDevueltas, 2);
            if ($horas > $pendientes) {
                throw new DomainException("Solo quedan {$pendientes} horas por devolver.");
            }

            $devueltas = round($compensacion->CompensacionHorariaHorasDevueltas + $horas, 2);
            $compensacion->update([
                'CompensacionHorariaHorasDevueltas' => $devueltas,
                'CompensacionHorariaEstado' => $devueltas >= (float) $compensacion->CompensacionHorariaHorasGeneradas ? 'CONSUMIDO' : 'APROBADO',
            ]);

            return $compensacion->fresh();
        });
    }

    /** Anular es idempotente; una compensacion con horas ya devueltas se conserva como constancia. */
    public function anular(CompensacionHoraria $compensacion): CompensacionHoraria
    {
        if ($compensacion->CompensacionHorariaEstado === 'ANULADO') {
            return $compensacion;
        }
        if ($compensacion->CompensacionHorariaHorasDevueltas > 0) {
            throw new DomainException('No se puede anular: ya se devolvieron horas de esta compensación.');
        }
        $compensacion->update(['CompensacionHorariaEstado' => 'ANULADO']);

        return $compensacion->fresh();
    }
}
