<?php

namespace App\Services;

use App\Models\Asistencia\AjusteMarcacion;
use App\Models\Disciplina\SupervisionInopinada;
use App\Models\Programacion\CambioTurno;
use App\Models\Programacion\CargaProgramacion;
use App\Models\Programacion\InformeGuardiaComunitaria;
use App\Models\Seguridad\Usuario;
use App\Models\Solicitudes\ConstatacionDomiciliaria;
use App\Models\Solicitudes\DescansoMedico;
use App\Models\Solicitudes\Licencia;
use App\Models\Solicitudes\Papeleta;
use App\Models\Vacaciones\GoceVacacional;
use App\Support\AccesoUsuario;
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
 *
 * Licencias y papeletas pasan por dos pasos (RIT, Art. 16, 30 y 56): el jefe da su visto bueno y RRHH, con alcance sobre
 * toda la Red, las oficializa. Sin jefe disponible RRHH puede autorizar directamente (Art. 57) y queda constancia.
 */
class SolicitudService
{
    /** @var array<class-string<Model>,array<string,string|null>> */
    private const CONFIG = [
        Papeleta::class => [
            'estado' => 'PapeletaEstado', 'usuario' => 'UsuarioAutorizacionId', 'fecha' => 'PapeletaFechaResolucion',
            'nota' => 'PapeletaObservacion', 'registrante' => 'UsuarioRegistroId', 'enlace' => '/solicitudes/registro?pestana=papeletas', 'nombre' => 'papeleta',
        ],
        Licencia::class => [
            'estado' => 'LicenciaEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => 'UsuarioRegistroId', 'enlace' => '/solicitudes/registro?pestana=licencias', 'nombre' => 'licencia',
        ],
        DescansoMedico::class => [
            'estado' => 'DescansoMedicoEstado', 'usuario' => null, 'fecha' => null,
            'nota' => 'DescansoMedicoObservacion', 'registrante' => null, 'enlace' => '/solicitudes/registro?pestana=descansos', 'nombre' => 'descanso médico',
        ],
        InformeGuardiaComunitaria::class => [
            'estado' => 'InformeGuardiaComunitariaEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => null, 'enlace' => '/programacion/gestion?pestana=guardias', 'nombre' => 'informe de guardia comunitaria',
        ],
        ConstatacionDomiciliaria::class => [
            'estado' => 'ConstatacionDomiciliariaEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => 'UsuarioRegistroId', 'enlace' => '/solicitudes/registro?pestana=constataciones', 'nombre' => 'constatación domiciliaria',
        ],
        SupervisionInopinada::class => [
            'estado' => 'SupervisionInopinadaEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => null, 'enlace' => '/disciplina/gestion?pestana=supervisiones', 'nombre' => 'supervisión inopinada',
        ],
        CargaProgramacion::class => [
            'estado' => 'CargaProgramacionEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => null, 'enlace' => '/programacion/gestion?pestana=programaciones', 'nombre' => 'carga de programación',
        ],
        CambioTurno::class => [
            'estado' => 'CambioTurnoEstado', 'usuario' => 'UsuarioAprobacionId', 'fecha' => 'CambioTurnoFechaResolucion',
            'nota' => 'CambioTurnoObservacion', 'registrante' => 'UsuarioRegistroId', 'enlace' => '/programacion/gestion?pestana=cambios', 'nombre' => 'solicitud de cambio de turno',
        ],
        AjusteMarcacion::class => [
            'estado' => 'AjusteMarcacionEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => 'UsuarioId', 'enlace' => '/solicitudes/registro?pestana=ajustes', 'nombre' => 'solicitud de ajuste de marcación',
        ],
        GoceVacacional::class => [
            'estado' => 'GoceVacacionalEstado', 'usuario' => null, 'fecha' => null,
            'nota' => null, 'registrante' => null, 'enlace' => '/vacaciones/gestion', 'nombre' => 'solicitud de goce vacacional',
        ],
    ];

    /** Columnas del visto bueno del jefe. */
    private const VISTO_BUENO = [
        Papeleta::class => ['PapeletaVistoBuenoPor', 'PapeletaFechaVistoBueno'],
        Licencia::class => ['LicenciaVistoBuenoPor', 'LicenciaFechaVistoBueno'],
    ];

    public function __construct(private readonly NotificacionService $notificaciones) {}

    public function aprobar(Model $solicitud, int $usuarioId, ?string $nota = null): Model
    {
        return DB::transaction(function () use ($solicitud, $usuarioId, $nota) {
            $c = $this->config($solicitud);
            $this->exigirPendiente($solicitud, $c, 'aprobar');
            $this->exigirParaAprobar($solicitud);

            if (isset(self::VISTO_BUENO[$solicitud::class])) {
                [$vistoPor, $vistoFecha] = self::VISTO_BUENO[$solicitud::class];
                $usuario = Usuario::query()->find($usuarioId);
                $esDeLaRed = $usuario !== null && AccesoUsuario::de($usuario)->eessIds === null;
                if (! $esDeLaRed) {
                    if ($solicitud->getAttribute($vistoPor) !== null) {
                        throw new DomainException('Ya tiene el visto bueno del jefe: falta que Recursos Humanos la oficialice.');
                    }
                    $solicitud->update([$vistoPor => $usuarioId, $vistoFecha => now()]);
                    $this->avisar($solicitud, $c, 'APROBADA', "Tu {$c['nombre']} tiene el visto bueno del jefe y pasó a Recursos Humanos.");

                    return $solicitud->fresh();
                }
                if ($solicitud->getAttribute($vistoPor) === null) {
                    $nota = trim(($nota ?? '').' Autorizada por RRHH sin visto bueno previo del jefe (RIT, Art. 57).');
                }
            }
            if ($solicitud instanceof Licencia) {
                $this->descontarDeVacaciones($solicitud);
            }

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
        if ($solicitud instanceof Licencia) {
            $dias = $solicitud->LicenciaFechaInicio->diffInDays($solicitud->LicenciaFechaFin) + 1;
            if ($dias >= self::DIAS_CON_ENTREGA_DE_CARGO && ! self::tieneEntregaDeCargo((int) $solicitud->VinculoLaboralId, 'LICENCIA', $solicitud->LicenciaFechaInicio->toDateString())) {
                throw new DomainException('Una licencia de 30 días o más exige registrar antes la entrega de cargo (RIT, Art. 56).');
            }
        }
        // El descanso medico nace de un CITT o certificado: sin ninguno no hay sustento.
        if ($solicitud instanceof DescansoMedico && blank($solicitud->DescansoMedicoNumeroCitt) && $solicitud->DocumentoSustentoId === null) {
            throw new DomainException('No se puede aprobar: el descanso médico no tiene número de CITT ni documento de sustento.');
        }
    }

    /** Ausencias de esta duracion exigen entrega de cargo (RIT, Art. 56 y 70 g). */
    public const DIAS_CON_ENTREGA_DE_CARGO = 30;

    public static function tieneEntregaDeCargo(int $vinculoId, string $motivo, string $hasta): bool
    {
        return DB::table('Personal.EntregaCargo')->where('VinculoLaboralId', $vinculoId)->where('EntregaCargoMotivo', $motivo)->where('EntregaCargoEstado', 'CONFORME')
            ->whereDate('EntregaCargoFecha', '<=', $hasta)->whereDate('EntregaCargoFecha', '>=', date('Y-m-d', strtotime($hasta.' -60 days')))->exists();
    }

    /** Licencias a cuenta del periodo vacacional (RIT, Art. 33, 38 y 48): sus dias salen del periodo abierto mas antiguo. */
    private function descontarDeVacaciones(Licencia $licencia): void
    {
        if (! $licencia->tipo?->TipoLicenciaACuentaVacaciones) {
            return;
        }
        $dias = $licencia->LicenciaFechaInicio->diffInDays($licencia->LicenciaFechaFin) + 1;
        $periodo = DB::table('Vacaciones.PeriodoVacacional')->where('VinculoLaboralId', $licencia->VinculoLaboralId)->where('PeriodoVacacionalEstado', 'ABIERTO')
            ->where('PeriodoVacacionalDiasDisponibles', '>=', $dias)->orderBy('PeriodoVacacionalAnio')->first();
        if ($periodo === null) {
            throw new DomainException("No se puede aprobar: el trabajador no tiene {$dias} días disponibles en un período vacacional abierto (RIT, Art. 48).");
        }
        DB::table('Vacaciones.PeriodoVacacional')->where('PeriodoVacacionalId', $periodo->PeriodoVacacionalId)->update(['PeriodoVacacionalDiasDisponibles' => (float) $periodo->PeriodoVacacionalDiasDisponibles - $dias]);
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
