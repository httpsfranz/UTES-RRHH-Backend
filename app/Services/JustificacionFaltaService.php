<?php

namespace App\Services;

use App\Models\Asistencia\AsistenciaDiaria;
use App\Models\Asistencia\EstadoAsistencia;
use App\Models\Asistencia\JustificacionFalta;
use App\Models\Consolidacion\PeriodoAsistencia;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de la justificacion de faltas. Aprobar toca dos tablas (JustificacionFalta y AsistenciaDiaria): si una falla,
 * ninguna debe quedar aplicada, por eso va en una transaccion. La FALTA no tiene tabla propia: es una fila de
 * AsistenciaDiaria con un estado EsFalta = 1, y se "justifica" cambiando su estado a FALTA_JUST y enlazandola.
 */
class JustificacionFaltaService
{
    public function __construct(private readonly NotificacionService $notificaciones) {}

    /** @return array{justificacion: JustificacionFalta, asistencias_actualizadas: int} */
    public function aprobar(JustificacionFalta $justificacion, int $usuarioId, ?string $observacion = null): array
    {
        return DB::transaction(function () use ($justificacion, $usuarioId, $observacion) {
            $this->exigirPendiente($justificacion, 'aprobar');

            $justificacion->update([
                'JustificacionFaltaEstado' => 'APROBADO',
                'UsuarioResolucionId' => $usuarioId,
                'JustificacionFaltaFechaResolucion' => now(),
                'JustificacionFaltaObservacion' => $observacion ?: $justificacion->JustificacionFaltaObservacion,
            ]);

            $injustificada = EstadoAsistencia::query()->where('EstadoAsistenciaCodigo', 'FALTA')->firstOrFail();
            $justificada = EstadoAsistencia::query()->where('EstadoAsistenciaCodigo', 'FALTA_JUST')->firstOrFail();

            // Solo las faltas injustificadas de esos dias: lo demas (asistio, descanso, licencia...) no se toca.
            $actualizadas = $this->faltasDelRango($justificacion)
                ->where('EstadoAsistenciaId', $injustificada->EstadoAsistenciaId)
                ->whereNull('JustificacionFaltaId')
                ->update([
                    'EstadoAsistenciaId' => $justificada->EstadoAsistenciaId,
                    'JustificacionFaltaId' => $justificacion->JustificacionFaltaId,
                ]);

            $this->notificar($justificacion, 'JUSTIFICACION_APROBADA', 'Justificación aprobada', 'Tu justificación de faltas fue aprobada.');

            return ['justificacion' => $justificacion->fresh(), 'asistencias_actualizadas' => $actualizadas];
        });
    }

    public function rechazar(JustificacionFalta $justificacion, int $usuarioId, string $motivo): JustificacionFalta
    {
        return DB::transaction(function () use ($justificacion, $usuarioId, $motivo) {
            $this->exigirPendiente($justificacion, 'rechazar');

            $justificacion->update([
                'JustificacionFaltaEstado' => 'RECHAZADO',
                'UsuarioResolucionId' => $usuarioId,
                'JustificacionFaltaFechaResolucion' => now(),
                'JustificacionFaltaMotivoRechazo' => $motivo,
            ]);
            $this->notificar($justificacion, 'JUSTIFICACION_RECHAZADA', 'Justificación rechazada', "Tu justificación de faltas fue rechazada: {$motivo}");

            return $justificacion->fresh();
        });
    }

    /**
     * Anular una justificacion APROBADA devuelve esas faltas a "injustificada". Si algun dia esta en un periodo de
     * asistencia cerrado, no se puede: la asistencia ya se consolido. Anular es idempotente.
     */
    public function anular(JustificacionFalta $justificacion): int
    {
        return DB::transaction(function () use ($justificacion) {
            if ($justificacion->JustificacionFaltaEstado === 'ANULADO') {
                return 0;
            }

            $devueltas = 0;
            if ($justificacion->JustificacionFaltaEstado === 'APROBADO') {
                $enlazadas = AsistenciaDiaria::query()->where('JustificacionFaltaId', $justificacion->JustificacionFaltaId);
                foreach ((clone $enlazadas)->pluck('AsistenciaDiariaFecha') as $fecha) {
                    if (PeriodoAsistencia::query()->where('PeriodoAsistenciaEstado', 'CERRADO')
                        ->whereDate('PeriodoAsistenciaFechaInicio', '<=', $fecha)->whereDate('PeriodoAsistenciaFechaFin', '>=', $fecha)->exists()) {
                        throw new DomainException('No se puede anular: hay faltas justificadas en un período de asistencia cerrado.');
                    }
                }
                $injustificada = EstadoAsistencia::query()->where('EstadoAsistenciaCodigo', 'FALTA')->firstOrFail();
                $devueltas = $enlazadas->update(['EstadoAsistenciaId' => $injustificada->EstadoAsistenciaId, 'JustificacionFaltaId' => null]);
            }

            $justificacion->update(['JustificacionFaltaEstado' => 'ANULADO']);

            return $devueltas;
        });
    }

    private function exigirPendiente(JustificacionFalta $justificacion, string $accion): void
    {
        if ($justificacion->JustificacionFaltaEstado !== 'PENDIENTE') {
            throw new DomainException("Solo se pueden {$accion} justificaciones pendientes.");
        }
        // El concepto puede exigir documento: no se aprueba sin el sustento adjunto.
        if ($accion === 'aprobar' && $justificacion->concepto?->ConceptoJustificacionRequiereDocumento && $justificacion->DocumentoSustentoId === null) {
            throw new DomainException('No se puede aprobar: el concepto exige el documento de sustento.');
        }
    }

    private function faltasDelRango(JustificacionFalta $justificacion)
    {
        $consulta = AsistenciaDiaria::query()
            ->where('VinculoLaboralId', $justificacion->VinculoLaboralId)
            ->whereDate('AsistenciaDiariaFecha', '>=', $justificacion->JustificacionFaltaFechaInicio->toDateString())
            ->whereDate('AsistenciaDiariaFecha', '<=', $justificacion->JustificacionFaltaFechaFin->toDateString());

        // Un periodo cerrado no se toca.
        $cerrados = PeriodoAsistencia::query()->where('PeriodoAsistenciaEstado', 'CERRADO')->get(['PeriodoAsistenciaFechaInicio', 'PeriodoAsistenciaFechaFin']);
        foreach ($cerrados as $periodo) {
            $consulta->where(fn ($q) => $q
                ->whereDate('AsistenciaDiariaFecha', '<', $periodo->PeriodoAsistenciaFechaInicio->toDateString())
                ->orWhereDate('AsistenciaDiariaFecha', '>', $periodo->PeriodoAsistenciaFechaFin->toDateString()));
        }

        return $consulta;
    }

    private function notificar(JustificacionFalta $justificacion, string $tipo, string $titulo, string $mensaje): void
    {
        $this->notificaciones->notificar($justificacion->UsuarioRegistroId, $tipo, $titulo, $mensaje, '/asistencia/justificacion-faltas');
    }
}
