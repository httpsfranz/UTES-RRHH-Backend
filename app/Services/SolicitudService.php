<?php

namespace App\Services;

use App\Models\Asistencia\AjusteMarcacion;
use App\Models\Disciplina\SupervisionInopinada;
use App\Models\Programacion\CambioTurno;
use App\Models\Programacion\CargaProgramacion;
use App\Models\Programacion\InformeGuardiaComunitaria;
use App\Models\Solicitudes\ConstatacionDomiciliaria;
use App\Models\Solicitudes\DescansoMedico;
use App\Models\Solicitudes\Licencia;
use App\Models\Solicitudes\Papeleta;
use App\Models\Vacaciones\GoceVacacional;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de las solicitudes con aprobacion (papeletas, licencias, descansos medicos, informes de guardia) y
 * anulacion de los registros con estado ANULADO (constataciones, supervisiones, cargas de programacion).
 * Una solicitud nace PENDIENTE y se resuelve UNA vez: aprobar o rechazar solo desde PENDIENTE; anular es definitivo e
 * idempotente. Al resolverla se avisa a quien la registro (Soporte.Notificacion), en la misma transaccion.
 *
 * Cada modelo declara sus columnas: estado, quien resolvio, cuando, donde queda la nota/motivo, quien la registro.
 */
class SolicitudService
{
    /** @var array<class-string<Model>,array<string,string|null>> */
    private const CONFIG = [
        Papeleta::class => [
            'estado' => 'PapeletaEstado', 'usuario' => 'UsuarioAutorizacionId', 'fecha' => 'PapeletaFechaResolucion',
            'nota' => 'PapeletaObservacion', 'registrante' => 'UsuarioRegistroId', 'enlace' => '/solicitudes/papeletas', 'nombre' => 'papeleta',
        ],
        Licencia::class => [
            'estado' => 'LicenciaEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => 'UsuarioRegistroId', 'enlace' => '/solicitudes/licencias', 'nombre' => 'licencia',
        ],
        DescansoMedico::class => [
            'estado' => 'DescansoMedicoEstado', 'usuario' => null, 'fecha' => null,
            'nota' => 'DescansoMedicoObservacion', 'registrante' => null, 'enlace' => '/solicitudes/descansos-medicos', 'nombre' => 'descanso médico',
        ],
        InformeGuardiaComunitaria::class => [
            'estado' => 'InformeGuardiaComunitariaEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => null, 'enlace' => '/programacion/guardia-comunitaria', 'nombre' => 'informe de guardia comunitaria',
        ],
        ConstatacionDomiciliaria::class => [
            'estado' => 'ConstatacionDomiciliariaEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => 'UsuarioRegistroId', 'enlace' => '/solicitudes/constatacion-domiciliaria', 'nombre' => 'constatación domiciliaria',
        ],
        SupervisionInopinada::class => [
            'estado' => 'SupervisionInopinadaEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => null, 'enlace' => '/disciplina/supervisiones', 'nombre' => 'supervisión inopinada',
        ],
        CargaProgramacion::class => [
            'estado' => 'CargaProgramacionEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => null, 'enlace' => '/programacion/carga', 'nombre' => 'carga de programación',
        ],
        CambioTurno::class => [
            'estado' => 'CambioTurnoEstado', 'usuario' => 'UsuarioAprobacionId', 'fecha' => 'CambioTurnoFechaResolucion',
            'nota' => 'CambioTurnoObservacion', 'registrante' => 'UsuarioRegistroId', 'enlace' => '/programacion/cambios-turno', 'nombre' => 'solicitud de cambio de turno',
        ],
        AjusteMarcacion::class => [
            'estado' => 'AjusteMarcacionEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => 'UsuarioId', 'enlace' => '/asistencia/ajustes', 'nombre' => 'solicitud de ajuste de marcación',
        ],
        GoceVacacional::class => [
            'estado' => 'GoceVacacionalEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => null, 'enlace' => '/vacaciones/goce', 'nombre' => 'solicitud de goce vacacional',
        ],
    ];

    public function __construct(private readonly NotificacionService $notificaciones) {}

    public function aprobar(Model $solicitud, int $usuarioId, ?string $nota = null): Model
    {
        return DB::transaction(function () use ($solicitud, $usuarioId, $nota) {
            $c = $this->config($solicitud);
            $this->exigirPendiente($solicitud, $c, 'aprobar');
            $this->exigirParaAprobar($solicitud);

            $solicitud->update($this->cambios($c, 'APROBADO', $usuarioId, $solicitud, $nota ? "Aprobación: {$nota}" : null));
            $this->avisar($solicitud, $c, 'APROBADA', "Tu {$c['nombre']} fue aprobada.".($nota ? " {$nota}" : ''));

            return $solicitud->fresh();
        });
    }

    public function rechazar(Model $solicitud, int $usuarioId, string $motivo): Model
    {
        return DB::transaction(function () use ($solicitud, $usuarioId, $motivo) {
            $c = $this->config($solicitud);
            $this->exigirPendiente($solicitud, $c, 'rechazar');

            $solicitud->update($this->cambios($c, 'RECHAZADO', $usuarioId, $solicitud, "Rechazo: {$motivo}"));
            $this->avisar($solicitud, $c, 'RECHAZADA', "Tu {$c['nombre']} fue rechazada: {$motivo}");

            return $solicitud->fresh();
        });
    }

    /** Anular es definitivo e idempotente: el registro queda como constancia. */
    public function anular(Model $registro): Model
    {
        $c = $this->config($registro);
        if ($registro->getAttribute($c['estado']) !== 'ANULADO') {
            $registro->update([$c['estado'] => 'ANULADO']);
        }

        return $registro->fresh();
    }

    /**
     * @param  array<string,string|null>  $c
     * @return array<string,mixed>
     */
    private function cambios(array $c, string $estado, int $usuarioId, Model $solicitud, ?string $nota): array
    {
        $cambios = [$c['estado'] => $estado];
        if ($c['usuario']) {
            $cambios[$c['usuario']] = $usuarioId;
        }
        if ($c['fecha']) {
            $cambios[$c['fecha']] = now();
        }
        if ($c['nota'] && $nota) {
            $actual = (string) $solicitud->getAttribute($c['nota']);
            $cambios[$c['nota']] = mb_substr(trim($actual === '' ? $nota : "{$actual}\n{$nota}"), 0, 1000);
        }

        return $cambios;
    }

    /** @return array<string,string|null> */
    private function config(Model $modelo): array
    {
        return self::CONFIG[$modelo::class] ?? throw new DomainException('Este registro no admite cambios de estado.');
    }

    /** @param  array<string,string|null>  $c */
    private function exigirPendiente(Model $solicitud, array $c, string $accion): void
    {
        if ($solicitud->getAttribute($c['estado']) !== 'PENDIENTE') {
            throw new DomainException("Solo se pueden {$accion} solicitudes pendientes.");
        }
    }

    /** Requisitos propios de cada tipo de solicitud para poder aprobarla. */
    private function exigirParaAprobar(Model $solicitud): void
    {
        if ($solicitud instanceof Papeleta && $solicitud->tipo?->TipoPapeletaRequiereSustento && $solicitud->DocumentoSustentoId === null) {
            throw new DomainException("No se puede aprobar: el tipo \"{$solicitud->tipo->TipoPapeletaNombre}\" exige el documento de sustento.");
        }
        // El descanso medico nace de un CITT o certificado: sin ninguno no hay sustento.
        if ($solicitud instanceof DescansoMedico && blank($solicitud->DescansoMedicoNumeroCitt) && $solicitud->DocumentoSustentoId === null) {
            throw new DomainException('No se puede aprobar: el descanso médico no tiene número de CITT ni documento de sustento.');
        }
    }

    /** @param  array<string,string|null>  $c */
    private function avisar(Model $solicitud, array $c, string $resultado, string $mensaje): void
    {
        if ($c['registrante']) {
            $this->notificaciones->notificar(
                $solicitud->getAttribute($c['registrante']),
                strtoupper(str_replace(' ', '_', $c['nombre'])).'_'.$resultado,
                ucfirst($c['nombre']).' '.strtolower($resultado === 'APROBADA' ? 'aprobada' : 'rechazada'),
                $mensaje,
                $c['enlace'],
            );
        }
    }
}
