<?php

namespace App\Services;

use App\Models\Configuracion\Turno;
use App\Models\Personal\VinculoLaboral;
use App\Models\Programacion\CambioTurno;
use App\Models\Programacion\ProgramacionTrabajador;
use App\Models\Programacion\TurnoProgramado;
use App\Support\PeriodosDeAsistencia;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cambio de turno sobre una programacion publicada (RIT, Art. 16 y 20): es la unica via para modificarla una vez
 * remitida. Nace PENDIENTE; la jefatura lo aprueba (y entonces se APLICA a la programacion en la misma transaccion) o
 * lo rechaza. Segun el tipo:
 *   REPROGRAMACION  el turno pasa a otro Turno (no lleva reemplazante).
 *   ANULACION       el turno queda sin efecto (no lleva reemplazante).
 *   REEMPLAZO       otro trabajador cubre el turno.
 *   PERMUTA         dos trabajadores intercambian sus turnos.
 * Un cambio aprobado ya modifico la programacion, por eso no se anula: se corrige con un cambio nuevo.
 */
class CambioTurnoService
{
    public const REPROGRAMACION = 'REPROGRAMACION';

    public const ANULACION = 'ANULACION';

    public const REEMPLAZO = 'REEMPLAZO';

    public const PERMUTA = 'PERMUTA';

    public const TIPOS = [self::REPROGRAMACION, self::ANULACION, self::REEMPLAZO, self::PERMUTA];

    /** Cada servidor acepta como maximo cuatro cambios de turno por mes; la guardia cuenta como dos (RIT, Art. 20). */
    public const MAXIMO_POR_MES = 4;

    public function __construct(
        private readonly SolicitudService $solicitudes,
        private readonly TurnoProgramadoService $agenda,
    ) {}

    public function aprobar(CambioTurno $cambio, int $usuarioId, ?string $nota = null): CambioTurno
    {
        return DB::transaction(function () use ($cambio, $usuarioId, $nota) {
            if ($cambio->CambioTurnoEstado !== 'PENDIENTE') {
                throw new DomainException('Solo se pueden aprobar solicitudes pendientes.');
            }
            $this->aplicar($cambio);

            return $this->solicitudes->aprobar($cambio, $usuarioId, $nota);
        });
    }

    public function rechazar(CambioTurno $cambio, int $usuarioId, string $motivo): CambioTurno
    {
        return $this->solicitudes->rechazar($cambio, $usuarioId, $motivo);
    }

    public function anular(CambioTurno $cambio): CambioTurno
    {
        if ($cambio->CambioTurnoEstado === 'APROBADO') {
            throw new DomainException('Un cambio aprobado ya se aplicó a la programación: para corregirlo registra un cambio nuevo.');
        }

        return $this->solicitudes->anular($cambio);
    }

    /**
     * Suma de cambios (pendientes o aprobados) en los que participa el trabajador, por mes del turno afectado. La guardia
     * vale dos. Sirve para el tope de cuatro por mes (RIT, Art. 20, incisos b, g y h).
     */
    public function cambiosDelMes(int $trabajadorId, string $fecha, ?int $excluirCambioId = null): int
    {
        $desde = date('Y-m-01', strtotime($fecha));
        $hasta = date('Y-m-t', strtotime($fecha));

        return CambioTurno::query()
            ->with(['turnoProgramado:TurnoProgramadoId,TurnoProgramadoEsGuardia', 'contraparte:TurnoProgramadoId,TurnoProgramadoEsGuardia'])
            ->whereIn('CambioTurnoEstado', ['PENDIENTE', 'APROBADO'])
            ->when($excluirCambioId, fn ($q, $id) => $q->whereKeyNot($id))
            ->where(fn ($q) => $q
                ->whereHas('solicitante', fn ($v) => $v->where('TrabajadorId', $trabajadorId))
                ->orWhereHas('reemplazante', fn ($v) => $v->where('TrabajadorId', $trabajadorId)))
            ->whereHas('turnoProgramado', fn ($t) => $t->whereDate('TurnoProgramadoFecha', '>=', $desde)->whereDate('TurnoProgramadoFecha', '<=', $hasta))
            ->get()
            ->sum(fn (CambioTurno $c) => self::peso($c->turnoProgramado, $c->contraparte));
    }

    /** Un cambio de guardia vale dos cambios; cualquier otro, uno. */
    public static function peso(?TurnoProgramado $turno, ?TurnoProgramado $contraparte = null): int
    {
        return ($turno?->TurnoProgramadoEsGuardia || $contraparte?->TurnoProgramadoEsGuardia) ? 2 : 1;
    }

    /**
     * Motivos por los que el cambio no puede aplicarse hoy a la programacion publicada (campo => motivo). Lo usa el
     * Form Request para avisar al registrar y el servicio para verificar de nuevo al aprobar.
     *
     * @return array<string,string>
     */
    public function problemasDeAplicacion(string $codigoTipo, TurnoProgramado $turno, ?VinculoLaboral $reemplazante, ?TurnoProgramado $contraparte, ?Turno $turnoNuevo): array
    {
        $turno->loadMissing(['turno', 'programacionTrabajador.periodo', 'programacionTrabajador.vinculoLaboral']);
        $periodo = $turno->programacionTrabajador->periodo;
        $duenio = $turno->programacionTrabajador->vinculoLaboral;
        $fecha = $turno->TurnoProgramadoFecha->toDateString();

        if ($periodo->ProgramacionPeriodoEstado !== 'PUBLICADA') {
            return ['TurnoProgramadoId' => $periodo->ProgramacionPeriodoEstado === 'BORRADOR'
                ? 'La programación aún es un borrador: edita sus turnos directamente, no hace falta un cambio de turno.'
                : 'La programación está '.($periodo->ProgramacionPeriodoEstado === 'CERRADA' ? 'cerrada' : 'anulada').': ya no admite cambios de turno.'];
        }
        if (! in_array($turno->TurnoProgramadoEstado, ['PROGRAMADO', 'REPROGRAMADO'], true)) {
            return ['TurnoProgramadoId' => 'El turno ya está '.($turno->TurnoProgramadoEstado === 'CUMPLIDO' ? 'cumplido' : 'anulado').': no se puede cambiar.'];
        }
        if (PeriodosDeAsistencia::cerradoEn($fecha)) {
            return ['TurnoProgramadoId' => 'El período de asistencia de la fecha del turno ya está cerrado: no se puede cambiar.'];
        }

        return match ($codigoTipo) {
            self::ANULACION => [],
            self::REPROGRAMACION => $this->problemasDeReprogramacion($turno, $duenio, $turnoNuevo),
            self::REEMPLAZO => $this->problemasDeReemplazo($turno, $duenio, $reemplazante),
            self::PERMUTA => $this->problemasDePermuta($turno, $duenio, $reemplazante, $contraparte),
            default => ['TipoCambioTurnoId' => "El tipo de cambio \"{$codigoTipo}\" no tiene un procedimiento definido."],
        };
    }

    /** @return array<string,string> */
    private function problemasDeReprogramacion(TurnoProgramado $turno, VinculoLaboral $duenio, ?Turno $nuevo): array
    {
        if ($nuevo === null) {
            return ['TurnoIdNuevo' => 'Indica el turno al que se reprograma.'];
        }
        if ($nuevo->TurnoId === $turno->TurnoId) {
            return ['TurnoIdNuevo' => 'El turno nuevo es el mismo que ya tiene programado.'];
        }
        if ($nuevo->TurnoEsGuardia && ($motivo = $this->agenda->motivoSiNoPuedeHacerGuardia($duenio))) {
            return ['TurnoIdNuevo' => $motivo];
        }
        $conflicto = $this->agenda->conflictoDeAgenda($duenio->TrabajadorId, $turno->TurnoProgramadoFecha->toDateString(),
            substr((string) $nuevo->TurnoHoraEntrada, 0, 8), substr((string) $nuevo->TurnoHoraSalida, 0, 8), [$turno->TurnoProgramadoId]);

        return $conflicto ? ['TurnoIdNuevo' => $conflicto] : [];
    }

    /** @return array<string,string> */
    private function problemasDeReemplazo(TurnoProgramado $turno, VinculoLaboral $duenio, ?VinculoLaboral $reemplazante): array
    {
        if ($reemplazante === null) {
            return ['VinculoLaboralReemplazanteId' => 'Indica quién reemplaza.'];
        }
        $periodo = $turno->programacionTrabajador->periodo;
        $fecha = $turno->TurnoProgramadoFecha->toDateString();

        if ($problema = $this->problemasDeParticipante($reemplazante, $duenio, $periodo->EessId, $fecha, $turno->TurnoProgramadoEsGuardia)) {
            return ['VinculoLaboralReemplazanteId' => $problema];
        }
        $conflicto = $this->agenda->conflictoDeAgenda($reemplazante->TrabajadorId, $fecha, $turno->horaEntradaEfectiva(), $turno->horaSalidaEfectiva(), [$turno->TurnoProgramadoId]);

        return $conflicto ? ['VinculoLaboralReemplazanteId' => "El reemplazante: {$conflicto}"] : [];
    }

    /** @return array<string,string> */
    private function problemasDePermuta(TurnoProgramado $turno, VinculoLaboral $duenio, ?VinculoLaboral $reemplazante, ?TurnoProgramado $contraparte): array
    {
        if ($reemplazante === null) {
            return ['VinculoLaboralReemplazanteId' => 'Indica con quién se permuta.'];
        }
        if ($contraparte === null) {
            return ['TurnoProgramadoContraparteId' => 'Indica el turno del otro trabajador que se recibe a cambio.'];
        }
        $contraparte->loadMissing(['turno', 'programacionTrabajador']);
        $periodo = $turno->programacionTrabajador->periodo;
        $esGuardia = $turno->TurnoProgramadoEsGuardia || $contraparte->TurnoProgramadoEsGuardia;

        if ($contraparte->programacionTrabajador->VinculoLaboralId !== $reemplazante->VinculoLaboralId) {
            return ['TurnoProgramadoContraparteId' => 'El turno que se recibe debe ser del trabajador con quien se permuta.'];
        }
        if ($contraparte->programacionTrabajador->ProgramacionPeriodoId !== $periodo->ProgramacionPeriodoId) {
            return ['TurnoProgramadoContraparteId' => 'Los dos turnos deben ser de la misma programación.'];
        }
        if (! in_array($contraparte->TurnoProgramadoEstado, ['PROGRAMADO', 'REPROGRAMADO'], true)) {
            return ['TurnoProgramadoContraparteId' => 'El turno del otro trabajador ya está '.($contraparte->TurnoProgramadoEstado === 'CUMPLIDO' ? 'cumplido' : 'anulado').'.'];
        }
        $fechaContraparte = $contraparte->TurnoProgramadoFecha->toDateString();
        if (PeriodosDeAsistencia::cerradoEn($fechaContraparte)) {
            return ['TurnoProgramadoContraparteId' => 'El período de asistencia de la fecha de ese turno ya está cerrado.'];
        }
        // Cada uno debe poder tomar el turno del otro: vigente ese dia y, si hay guardia, mismo cargo y regimen.
        if ($problema = $this->problemasDeParticipante($reemplazante, $duenio, $periodo->EessId, $turno->TurnoProgramadoFecha->toDateString(), $esGuardia)) {
            return ['VinculoLaboralReemplazanteId' => $problema];
        }
        if (! $this->estaVigente($duenio, $fechaContraparte)) {
            return ['TurnoProgramadoContraparteId' => 'El solicitante no tiene vínculo vigente en la fecha del turno que recibiría.'];
        }
        $excluir = [$turno->TurnoProgramadoId, $contraparte->TurnoProgramadoId];
        if ($conflicto = $this->agenda->conflictoDeAgenda($reemplazante->TrabajadorId, $turno->TurnoProgramadoFecha->toDateString(), $turno->horaEntradaEfectiva(), $turno->horaSalidaEfectiva(), $excluir)) {
            return ['VinculoLaboralReemplazanteId' => "El otro trabajador: {$conflicto}"];
        }
        if ($conflicto = $this->agenda->conflictoDeAgenda($duenio->TrabajadorId, $fechaContraparte, $contraparte->horaEntradaEfectiva(), $contraparte->horaSalidaEfectiva(), $excluir)) {
            return ['TurnoProgramadoContraparteId' => "El solicitante: {$conflicto}"];
        }

        return [];
    }

    /**
     * Quien toma un turno ajeno: otra persona, del establecimiento de la programacion, con vinculo vigente ese dia. Si el
     * turno es de guardia, ademas del personal D.L. 276 o SERUMS y del mismo cargo y regimen que el titular (RIT, Art. 20).
     */
    private function problemasDeParticipante(VinculoLaboral $quien, VinculoLaboral $titular, int $eessId, string $fecha, bool $esGuardia): ?string
    {
        if ($quien->TrabajadorId === $titular->TrabajadorId) {
            return 'Debe ser otro trabajador, distinto del solicitante.';
        }
        if ($quien->EessId !== $eessId) {
            return 'Debe pertenecer al establecimiento de la programación.';
        }
        if (! $this->estaVigente($quien, $fecha)) {
            return 'No tiene vínculo laboral vigente en la fecha del turno.';
        }
        if ($esGuardia) {
            if ($motivo = $this->agenda->motivoSiNoPuedeHacerGuardia($quien)) {
                return $motivo;
            }
            if ($quien->CargoId !== $titular->CargoId || $quien->RegimenLaboralId !== $titular->RegimenLaboralId) {
                return 'El cambio de una guardia solo procede entre servidores del mismo cargo y régimen laboral (RIT, Art. 20).';
            }
        }

        return null;
    }

    private function estaVigente(VinculoLaboral $vinculo, string $fecha): bool
    {
        return $vinculo->VinculoLaboralEstado
            && $vinculo->VinculoLaboralFechaInicio->toDateString() <= $fecha
            && ($vinculo->VinculoLaboralFechaFin === null || $vinculo->VinculoLaboralFechaFin->toDateString() >= $fecha);
    }

    /** Aplica el cambio a la programacion publicada. Debe correr dentro de la transaccion de aprobar(). */
    private function aplicar(CambioTurno $cambio): void
    {
        $turno = $cambio->turnoProgramado()->firstOrFail();
        $codigo = $cambio->tipo->TipoCambioTurnoCodigo;
        $reemplazante = $cambio->VinculoLaboralReemplazanteId ? VinculoLaboral::query()->find($cambio->VinculoLaboralReemplazanteId) : null;
        $contraparte = $cambio->TurnoProgramadoContraparteId ? TurnoProgramado::query()->find($cambio->TurnoProgramadoContraparteId) : null;
        $nuevo = $cambio->TurnoIdNuevo ? Turno::query()->find($cambio->TurnoIdNuevo) : null;

        if ($problemas = $this->problemasDeAplicacion($codigo, $turno, $reemplazante, $contraparte, $nuevo)) {
            throw new DomainException(reset($problemas));
        }

        match ($codigo) {
            self::ANULACION => $turno->update(['TurnoProgramadoEstado' => 'ANULADO']),
            self::REPROGRAMACION => $turno->update([
                'TurnoId' => $nuevo->TurnoId, 'TurnoProgramadoHoraEntrada' => null, 'TurnoProgramadoHoraSalida' => null,
                'TurnoProgramadoEsGuardia' => (bool) $nuevo->TurnoEsGuardia, 'TurnoProgramadoEstado' => 'REPROGRAMADO',
            ]),
            self::REEMPLAZO => $turno->update([
                'ProgramacionTrabajadorId' => $this->programacionDe($turno, $reemplazante)->ProgramacionTrabajadorId, 'TurnoProgramadoEstado' => 'REPROGRAMADO',
            ]),
            self::PERMUTA => $this->permutar($turno, $contraparte),
        };
    }

    private function permutar(TurnoProgramado $turno, TurnoProgramado $contraparte): void
    {
        $a = $turno->ProgramacionTrabajadorId;
        $b = $contraparte->ProgramacionTrabajadorId;
        $turno->update(['ProgramacionTrabajadorId' => $b, 'TurnoProgramadoEstado' => 'REPROGRAMADO']);
        $contraparte->update(['ProgramacionTrabajadorId' => $a, 'TurnoProgramadoEstado' => 'REPROGRAMADO']);
    }

    /** Programacion del reemplazante en el mismo periodo; si aun no figuraba, se le incorpora con el estado del periodo. */
    private function programacionDe(TurnoProgramado $turno, VinculoLaboral $trabajador): ProgramacionTrabajador
    {
        return ProgramacionTrabajador::query()->firstOrCreate(
            ['ProgramacionPeriodoId' => $turno->programacionTrabajador->ProgramacionPeriodoId, 'VinculoLaboralId' => $trabajador->VinculoLaboralId],
            ['ProgramacionTrabajadorEstado' => 'PUBLICADA'],
        );
    }
}
