<?php

namespace App\Services;

use App\Models\Configuracion\Turno;
use App\Models\Personal\VinculoLaboral;
use App\Models\Programacion\ProgramacionPeriodo;
use App\Models\Programacion\ProgramacionTrabajador;
use App\Models\Programacion\TurnoProgramado;
use App\Models\Seguridad\Usuario;
use App\Support\HoraLocal;
use App\Support\PeriodosDeAsistencia;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Programacion mensual de un establecimiento como GRILLA trabajador x dia (asi la arma hoy la UTES en su hoja): se elige
 * un turno y se "pinta" en las celdas. La programacion del periodo y la de cada trabajador se crean solas con el primer
 * turno, de modo que programar un mes es: abrir el mes, pintar (o copiar el mes anterior) y publicar.
 *
 * Usa las mismas reglas que el alta de un turno suelto (TurnoProgramadoRequest): fecha dentro del periodo, vinculo
 * vigente, periodo de asistencia abierto, guardia solo para D.L. 276 y SERUMS, sin superposiciones ni 24 horas
 * continuas (RIT, Art. 16 y 20). Una programacion publicada ya no se edita aqui: se modifica por cambio de turno.
 */
class ProgramacionMensualService
{
    public function __construct(
        private readonly CalendarioAsistenciaService $base,
        private readonly CalendarioProgramacionService $planificacion,
        private readonly TurnoProgramadoService $turnos,
    ) {}

    /** @return array<string,mixed> */
    public function grilla(int $eessId, int $anio, int $mes): array
    {
        [$primero, $ultimo] = $this->rango($anio, $mes);
        $eess = DB::table('Organizacion.EstablecimientoSalud as e')->join('Organizacion.Microred as m', 'm.MicroredId', '=', 'e.MicroredId')
            ->where('e.EessId', $eessId)->first(['e.EessId', 'e.EessNombre', 'e.MicroredId', 'm.MicroredNombre']);
        abort_if($eess === null, 404, 'El establecimiento no existe.');

        $programacion = $this->programacion($eessId, $anio, $mes);
        $otra = $programacion ? null : $this->otraProgramacion($eessId, $primero, $ultimo);
        $trabajadores = $this->base->trabajadores($eessId, $primero, $ultimo, null);
        $ids = $trabajadores->pluck('vinculo_id')->all();
        $asignados = $this->turnosDelMes($ids, $primero, $ultimo);
        $ausencias = $ids === [] ? collect() : $this->planificacion->ausenciasPorDia($ids, $primero, $ultimo);
        $dias = $this->base->dias($primero, $ultimo, (int) $eess->MicroredId);
        $guardia = $this->habilitadosParaGuardia($ids);

        $cobertura = [];
        $filas = $trabajadores->values()->map(function (array $t) use ($dias, $asignados, $ausencias, $guardia, &$cobertura) {
            $celdas = [];
            $minutos = 0;
            foreach ($dias as $dia) {
                $clave = $t['vinculo_id'].'|'.$dia['fecha'];
                $delDia = $asignados->get($clave, collect());
                foreach ($delDia as $x) {
                    $minutos += TurnoProgramado::minutosEntre((string) $x->entrada, (string) $x->salida);
                    $cobertura[$dia['fecha']][$x->codigo] = ($cobertura[$dia['fecha']][$x->codigo] ?? 0) + 1;
                }
                $ausencia = $ausencias->get($clave, [])[0] ?? null;
                $celdas[$dia['fecha']] = [
                    'turnos' => $delDia->map(fn ($x) => ['id' => (int) $x->id, 'turno_id' => (int) $x->turno_id, 'codigo' => $x->codigo])->values()->all(),
                    'ausencia' => $ausencia ? ['abreviatura' => $ausencia['abreviatura'], 'nombre' => $ausencia['nombre'].($ausencia['detalle'] ? " ({$ausencia['detalle']})" : '')] : null,
                ];
            }

            return [
                'vinculo_id' => $t['vinculo_id'], 'nombre' => $t['nombre'], 'dni' => $t['dni'], 'cargo' => $t['cargo'], 'condicion' => $t['condicion'],
                'puede_guardia' => in_array($t['vinculo_id'], $guardia, true), 'horas' => round($minutos / 60, 1), 'celdas' => $celdas,
            ];
        })->all();

        return [
            'eess' => ['id' => (int) $eess->EessId, 'nombre' => $eess->EessNombre, 'microred' => $eess->MicroredNombre],
            'periodo' => ['anio' => $anio, 'mes' => $mes, 'etiqueta' => CalendarioAsistenciaService::MESES[$mes].' '.$anio, 'dias' => $dias],
            'programacion' => $programacion ? [
                'id' => (int) $programacion->ProgramacionPeriodoId, 'codigo' => $programacion->ProgramacionPeriodoCodigo, 'estado' => $programacion->ProgramacionPeriodoEstado,
                'fecha_publicacion' => $programacion->ProgramacionPeriodoFechaPublicacion?->format('Y-m-d H:i'),
            ] : null,
            // Por que no se puede pintar (null = editable): publicada o cerrada, o el mes se programa por quincenas o semanas.
            'bloqueo' => $programacion ? ProgramacionPeriodoService::motivoDeBloqueo($programacion) : ($otra ? "Este mes ya tiene una programación {$otra}: adminístrala desde Programación por período." : null),
            'turnos' => Turno::query()->where('TurnoEstado', 1)->orderBy('TurnoHoraEntrada')->get()->map(fn (Turno $t) => [
                'id' => (int) $t->TurnoId, 'codigo' => $t->TurnoCodigo, 'nombre' => $t->TurnoNombre, 'es_guardia' => (bool) $t->TurnoEsGuardia,
                'entrada' => substr((string) $t->TurnoHoraEntrada, 0, 5), 'salida' => substr((string) $t->TurnoHoraSalida, 0, 5),
                'horas' => round(TurnoProgramado::minutosEntre((string) $t->TurnoHoraEntrada, (string) $t->TurnoHoraSalida) / 60, 1),
            ])->all(),
            'trabajadores' => $filas,
            'cobertura' => $cobertura,
        ];
    }

    /**
     * Deja en la celda (trabajador, dia) exactamente los turnos indicados: retira los que sobran y agrega los que faltan.
     * Todo o nada: si un turno no se puede programar, la celda queda como estaba y se informa el motivo.
     *
     * @param  list<int>  $turnoIds  [] = dia libre
     */
    public function asignar(int $eessId, int $vinculoId, string $fecha, array $turnoIds, Usuario $usuario): void
    {
        $vinculo = VinculoLaboral::query()->findOrFail($vinculoId);
        if ((int) $vinculo->EessId !== $eessId) {
            throw new DomainException('El trabajador pertenece a otro establecimiento: solo se programa al personal del establecimiento.');
        }
        $dia = CarbonImmutable::parse($fecha);

        DB::transaction(function () use ($eessId, $vinculo, $dia, $turnoIds, $usuario) {
            $programacion = $this->programacionEditable($eessId, $dia->year, $dia->month, $usuario);
            $pt = ProgramacionTrabajador::query()->firstOrCreate(
                ['ProgramacionPeriodoId' => $programacion->ProgramacionPeriodoId, 'VinculoLaboralId' => $vinculo->VinculoLaboralId],
                ['ProgramacionTrabajadorEstado' => 'BORRADOR', 'ProgramacionTrabajadorHorasProgramadas' => 0],
            );
            $actuales = $pt->turnos()->whereDate('TurnoProgramadoFecha', $dia->toDateString())->get();
            $actuales->reject(fn (TurnoProgramado $t) => in_array((int) $t->TurnoId, $turnoIds, true))->each->delete();

            foreach (array_unique($turnoIds) as $turnoId) {
                if ($actuales->contains(fn (TurnoProgramado $t) => (int) $t->TurnoId === $turnoId)) {
                    continue;
                }
                $turno = Turno::query()->where('TurnoEstado', 1)->find($turnoId) ?? throw new DomainException('El turno elegido no existe o está desactivado.');
                if ($motivo = $this->motivoParaProgramar($vinculo, $programacion, $turno, $dia->toDateString())) {
                    throw new DomainException($motivo);
                }
                TurnoProgramado::query()->create([
                    'ProgramacionTrabajadorId' => $pt->ProgramacionTrabajadorId, 'TurnoId' => $turno->TurnoId, 'TurnoProgramadoFecha' => $dia->toDateString(),
                    'TurnoProgramadoEsGuardia' => (bool) $turno->TurnoEsGuardia,
                ]);
            }

            // Sin turnos en todo el mes, el trabajador sale de la programacion (no queda una fila vacia).
            if (! $pt->turnos()->exists()) {
                $pt->delete();
            }
        });
    }

    /**
     * Copia la programacion del mes anterior en las celdas vacias, conservando el dia de la semana: cada dia toma los
     * turnos del mismo dia de la semana 4 (o 5) semanas antes, siempre dentro del mes anterior. Lo que ya no se puede
     * programar (vinculo terminado, ausencia, conflicto) se omite y se informa.
     *
     * @return array{creados:int,omitidos:list<string>}
     */
    public function copiarMesAnterior(int $eessId, int $anio, int $mes, Usuario $usuario): array
    {
        [$primero, $ultimo] = $this->rango($anio, $mes);
        $anterior = $primero->subMonth();
        $trabajadores = $this->base->trabajadores($eessId, $primero, $ultimo, null)->pluck('vinculo_id')->all();
        $origen = $this->turnosDelMes($trabajadores, $anterior->startOfMonth(), $anterior->endOfMonth()->startOfDay());
        if ($origen->isEmpty()) {
            throw new DomainException('El mes anterior no tiene turnos programados para este establecimiento.');
        }
        $destino = $this->turnosDelMes($trabajadores, $primero, $ultimo);
        $ausencias = $this->planificacion->ausenciasPorDia($trabajadores, $primero, $ultimo);
        $creados = 0;
        $omitidos = [];
        $nombre = fn (object $turno) => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $turno->nombre_trabajador)));

        for ($dia = $primero; $dia <= $ultimo; $dia = $dia->addDay()) {
            $fuente = $dia->subDays(28)->month === $anterior->month ? $dia->subDays(28) : $dia->subDays(35);
            foreach ($trabajadores as $vinculo) {
                $clave = $vinculo.'|'.$dia->toDateString();
                $copiar = $origen->get($vinculo.'|'.$fuente->toDateString(), collect());
                if ($copiar->isEmpty() || $destino->has($clave)) {
                    continue;
                }
                // Un dia con licencia, vacaciones o descanso medico aprobados no se programa.
                if ($ausencia = $ausencias->get($clave, [])[0] ?? null) {
                    $omitidos[] = $nombre($copiar->first()).' el '.$dia->format('d/m').': '.mb_strtolower($ausencia['nombre']).'.';

                    continue;
                }
                try {
                    $this->asignar($eessId, $vinculo, $dia->toDateString(), $copiar->pluck('turno_id')->map(fn ($id) => (int) $id)->all(), $usuario);
                    $creados += $copiar->count();
                } catch (DomainException $e) {
                    if ($this->programacion($eessId, $anio, $mes)?->ProgramacionPeriodoEstado !== 'BORRADOR') {
                        throw $e;
                    }
                    $omitidos[] = $nombre($copiar->first()).' el '.$dia->format('d/m').': '.$e->getMessage();
                }
            }
        }

        return ['creados' => $creados, 'omitidos' => $omitidos];
    }

    /** Motivo por el que el turno no se puede programar ese dia (null = se puede). Mismas reglas que TurnoProgramadoRequest. */
    public function motivoParaProgramar(VinculoLaboral $vinculo, ProgramacionPeriodo $periodo, Turno $turno, string $fecha): ?string
    {
        $etiqueta = CarbonImmutable::parse($fecha)->format('d/m/Y');
        if ($fecha < $periodo->ProgramacionPeriodoFechaInicio->toDateString() || $fecha > $periodo->ProgramacionPeriodoFechaFin->toDateString()) {
            return "El {$etiqueta} está fuera del período de la programación.";
        }
        if ($fecha < $vinculo->VinculoLaboralFechaInicio->toDateString()) {
            return "El vínculo laboral inició el {$vinculo->VinculoLaboralFechaInicio->format('d/m/Y')}: no se programa antes.";
        }
        if ($vinculo->VinculoLaboralFechaFin && $fecha > $vinculo->VinculoLaboralFechaFin->toDateString()) {
            return "El vínculo laboral terminó el {$vinculo->VinculoLaboralFechaFin->format('d/m/Y')}: no se programa después.";
        }
        if (PeriodosDeAsistencia::cerradoEn($fecha)) {
            return "El período de asistencia del {$etiqueta} ya está cerrado.";
        }
        if ($turno->TurnoEsGuardia && ($motivo = $this->turnos->motivoSiNoPuedeHacerGuardia($vinculo))) {
            return $motivo;
        }

        return $this->turnos->conflictoDeAgenda($vinculo->TrabajadorId, $fecha, substr((string) $turno->TurnoHoraEntrada, 0, 8), substr((string) $turno->TurnoHoraSalida, 0, 8));
    }

    /** La programacion mensual vigente (no anulada) del establecimiento en ese mes. */
    public function programacion(int $eessId, int $anio, int $mes): ?ProgramacionPeriodo
    {
        return ProgramacionPeriodo::query()
            ->where('EessId', $eessId)->where('ProgramacionPeriodoAnio', $anio)->where('ProgramacionPeriodoMes', $mes)
            ->where('TipoPeriodoProgramacionId', $this->tipoMensual())->where('ProgramacionPeriodoEstado', '<>', 'ANULADA')
            ->first();
    }

    /** La programacion mensual en borrador del mes; si aun no existe, se crea. */
    private function programacionEditable(int $eessId, int $anio, int $mes, Usuario $usuario): ProgramacionPeriodo
    {
        $programacion = $this->programacion($eessId, $anio, $mes);
        if ($programacion) {
            ProgramacionPeriodoService::exigirBorrador($programacion);

            return $programacion;
        }
        [$primero, $ultimo] = $this->rango($anio, $mes);
        if ($otra = $this->otraProgramacion($eessId, $primero, $ultimo)) {
            throw new DomainException("Este mes ya tiene una programación {$otra}: adminístrala desde Programación por período.");
        }

        return ProgramacionPeriodo::query()->create([
            'EessId' => $eessId, 'TipoPeriodoProgramacionId' => $this->tipoMensual(), 'UsuarioRegistroId' => $usuario->UsuarioId,
            'ProgramacionPeriodoAnio' => $anio, 'ProgramacionPeriodoMes' => $mes,
            'ProgramacionPeriodoFechaInicio' => $primero->toDateString(), 'ProgramacionPeriodoFechaFin' => $ultimo->toDateString(),
            'ProgramacionPeriodoFechaRegistro' => HoraLocal::ahora(), 'ProgramacionPeriodoEstado' => 'BORRADOR',
        ]);
    }

    /** Tipo (quincenal, semanal...) de otra programacion no anulada que se superpone con el mes, o null. */
    private function otraProgramacion(int $eessId, CarbonImmutable $primero, CarbonImmutable $ultimo): ?string
    {
        $nombre = DB::table('Programacion.ProgramacionPeriodo as p')
            ->join('Programacion.TipoPeriodoProgramacion as t', 't.TipoPeriodoProgramacionId', '=', 'p.TipoPeriodoProgramacionId')
            ->where('p.EessId', $eessId)->where('p.ProgramacionPeriodoEstado', '<>', 'ANULADA')->where('p.TipoPeriodoProgramacionId', '<>', $this->tipoMensual())
            ->whereDate('p.ProgramacionPeriodoFechaInicio', '<=', $ultimo->toDateString())->whereDate('p.ProgramacionPeriodoFechaFin', '>=', $primero->toDateString())
            ->value('t.TipoPeriodoProgramacionCodigo');

        return $nombre === null ? null : mb_strtolower($nombre);
    }

    private function tipoMensual(): int
    {
        return (int) DB::table('Programacion.TipoPeriodoProgramacion')->where('TipoPeriodoProgramacionCodigo', 'MENSUAL')->value('TipoPeriodoProgramacionId');
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function rango(int $anio, int $mes): array
    {
        $primero = CarbonImmutable::create($anio, $mes, 1)->startOfDay();

        return [$primero, $primero->endOfMonth()->startOfDay()];
    }

    /**
     * Turnos (no anulados) de las programaciones no anuladas, por "vinculo|fecha".
     *
     * @param  list<int>  $ids
     * @return Collection<string,Collection<int,object>>
     */
    private function turnosDelMes(array $ids, CarbonImmutable $primero, CarbonImmutable $ultimo): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'tp.TurnoId')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'pt.VinculoLaboralId')
            ->join('Personal.Trabajador as tr', 'tr.TrabajadorId', '=', 'v.TrabajadorId')
            ->whereIn('pt.VinculoLaboralId', $ids)->whereIn('pt.ProgramacionTrabajadorEstado', ['BORRADOR', 'PUBLICADA', 'CERRADA'])
            ->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')
            ->whereDate('tp.TurnoProgramadoFecha', '>=', $primero->toDateString())->whereDate('tp.TurnoProgramadoFecha', '<=', $ultimo->toDateString())
            ->orderBy('t.TurnoHoraEntrada')
            ->get(['tp.TurnoProgramadoId as id', 'tp.TurnoId as turno_id', 'pt.VinculoLaboralId as vinculo', 'tp.TurnoProgramadoFecha as fecha', 't.TurnoCodigo as codigo',
                'tr.TrabajadorNombreCompleto as nombre_trabajador',
                DB::raw('COALESCE(tp.TurnoProgramadoHoraEntrada, t.TurnoHoraEntrada) as entrada'), DB::raw('COALESCE(tp.TurnoProgramadoHoraSalida, t.TurnoHoraSalida) as salida')])
            ->groupBy(fn ($r) => $r->vinculo.'|'.substr((string) $r->fecha, 0, 10));
    }

    /**
     * Vinculos que pueden hacer guardia (D.L. 276 o SERUMS, RIT Art. 20).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function habilitadosParaGuardia(array $ids): array
    {
        return VinculoLaboral::query()->whereIn('VinculoLaboralId', $ids)->with(['regimenLaboral', 'condicionLaboral'])->get()
            ->filter(fn (VinculoLaboral $v) => $this->turnos->motivoSiNoPuedeHacerGuardia($v) === null)
            ->map(fn (VinculoLaboral $v) => (int) $v->VinculoLaboralId)->values()->all();
    }
}
