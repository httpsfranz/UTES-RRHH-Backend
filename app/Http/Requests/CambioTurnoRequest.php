<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Configuracion\Turno;
use App\Models\Personal\VinculoLaboral;
use App\Models\Programacion\CambioTurno;
use App\Models\Programacion\TipoCambioTurno;
use App\Models\Programacion\TurnoProgramado;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use App\Services\CambioTurnoService;
use App\Support\HoraLocal;
use Illuminate\Validation\Validator;

/**
 * Solicitud de cambio de turno sobre una programacion publicada (RIT, Art. 16 y 20). Es la unica forma de modificar
 * una programacion ya remitida y exige, segun el RIT:
 *   - peticion con 48 horas de anticipacion; con menos solo procede de manera excepcional y sustentada;
 *   - un reemplazante: el cambio "se materializa" cuando lo hay; sin el (reprogramacion o anulacion) solo procede por
 *     hechos fortuitos o necesidad del servicio, justificado y con autorizacion escrita del jefe (motivo y documento);
 *   - hasta cuatro cambios por mes para cada servidor que pide o acepta (la guardia cuenta como dos);
 *   - en una guardia, que ambos servidores sean del mismo cargo y regimen y del D.L. 276 o SERUMS.
 * La aprobacion (aprobar / rechazar) no pasa por aqui: la fecha de solicitud, la de resolucion, el usuario que aprueba
 * y el estado los pone el sistema.
 */
class CambioTurnoRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** Horas de anticipacion con que se pide el cambio (RIT, Art. 20, inciso a). */
    public const HORAS_DE_ANTICIPACION = 48;

    public function rules(): array
    {
        return [
            'TipoCambioTurnoId' => [$this->obligatorio(), 'integer', $this->existeActivo(TipoCambioTurno::class, 'TipoCambioTurnoEstado', 'TipoCambioTurnoId')],
            'TurnoProgramadoId' => [$this->obligatorio(), 'integer', $this->existe(TurnoProgramado::class)],
            'TurnoProgramadoContraparteId' => ['nullable', 'integer', $this->existe(TurnoProgramado::class)],
            'TurnoIdNuevo' => ['nullable', 'integer', $this->existeActivo(Turno::class, 'TurnoEstado', 'TurnoIdNuevo')],
            'VinculoLaboralSolicitanteId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralSolicitanteId')],
            'VinculoLaboralReemplazanteId' => ['nullable', 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralReemplazanteId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            // Quien registra la solicitud. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioRegistroId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioRegistroId')],
            'CambioTurnoMotivo' => $this->texto(1000),
            'CambioTurnoObservacion' => $this->texto(1000),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'CambioTurnoEstado');
            $this->soloSiPendiente($validator, 'CambioTurnoEstado', 'CambioTurnoEstado', 'La solicitud');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tipo = TipoCambioTurno::query()->find($this->valorEfectivo('TipoCambioTurnoId'));
            $codigo = $tipo->TipoCambioTurnoCodigo;
            if (! in_array($codigo, CambioTurnoService::TIPOS, true)) {
                $validator->errors()->add('TipoCambioTurnoId', "El tipo \"{$tipo->TipoCambioTurnoNombre}\" no tiene un procedimiento definido: use reprogramación, permuta, reemplazo o anulación.");

                return;
            }

            $turno = TurnoProgramado::query()->with(['turno', 'programacionTrabajador.periodo', 'programacionTrabajador.vinculoLaboral'])->find($this->valorEfectivo('TurnoProgramadoId'));
            $solicitante = VinculoLaboral::query()->find($this->valorEfectivo('VinculoLaboralSolicitanteId'));
            $reemplazante = $this->vinculo('VinculoLaboralReemplazanteId');
            $contraparte = $this->idOpcional('TurnoProgramadoContraparteId') ? TurnoProgramado::query()->with(['turno', 'programacionTrabajador'])->find($this->valorEfectivo('TurnoProgramadoContraparteId')) : null;
            $nuevo = $this->idOpcional('TurnoIdNuevo') ? Turno::query()->find($this->valorEfectivo('TurnoIdNuevo')) : null;

            if ($turno->programacionTrabajador->VinculoLaboralId !== $solicitante->VinculoLaboralId) {
                $validator->errors()->add('VinculoLaboralSolicitanteId', 'El solicitante debe ser el trabajador a quien se programó el turno.');
            }

            $this->camposSegunElTipo($validator, $codigo, (bool) $tipo->TipoCambioTurnoRequiereReemplazante, $reemplazante, $contraparte, $nuevo);
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->unSoloCambioPendiente($validator, $turno, $contraparte);
            foreach (app(CambioTurnoService::class)->problemasDeAplicacion($codigo, $turno, $reemplazante, $contraparte, $nuevo) as $campo => $motivo) {
                $validator->errors()->add($campo, $motivo);
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->anticipacionDelPedido($validator, $turno, $contraparte);
            $this->topeMensual($validator, $turno, $contraparte, $solicitante, $reemplazante);
        });
    }

    /** Campos que el tipo exige o prohibe (reemplazante, turno del otro trabajador, turno nuevo, motivo y documento). */
    private function camposSegunElTipo(Validator $validator, string $codigo, bool $requiereReemplazante, ?VinculoLaboral $reemplazante, ?TurnoProgramado $contraparte, ?Turno $nuevo): void
    {
        if ($requiereReemplazante && $reemplazante === null) {
            $validator->errors()->add('VinculoLaboralReemplazanteId', 'Este tipo de cambio exige un reemplazante: el cambio de turno se materializa cuando existe un servidor reemplazante (RIT, Art. 20).');
        }
        if (! $requiereReemplazante && $reemplazante !== null) {
            $validator->errors()->add('VinculoLaboralReemplazanteId', 'Este tipo de cambio no lleva reemplazante.');
        }
        if ($codigo === CambioTurnoService::PERMUTA && $contraparte === null) {
            $validator->errors()->add('TurnoProgramadoContraparteId', 'Indica el turno del otro trabajador que se recibe a cambio.');
        }
        if ($codigo !== CambioTurnoService::PERMUTA && $contraparte !== null) {
            $validator->errors()->add('TurnoProgramadoContraparteId', 'Solo la permuta lleva el turno de la contraparte.');
        }
        if ($codigo === CambioTurnoService::REPROGRAMACION && $nuevo === null) {
            $validator->errors()->add('TurnoIdNuevo', 'Indica el turno al que se reprograma.');
        }
        if ($codigo !== CambioTurnoService::REPROGRAMACION && $nuevo !== null) {
            $validator->errors()->add('TurnoIdNuevo', 'Solo la reprogramación lleva un turno nuevo.');
        }
        // Sin reemplazante: excepcional, justificado y con la autorizacion expresa por escrito del jefe (RIT, Art. 20, inciso f).
        if (! $requiereReemplazante) {
            if (blank($this->valorEfectivo('CambioTurnoMotivo'))) {
                $validator->errors()->add('CambioTurnoMotivo', 'Sin reemplazante el cambio solo procede por hechos fortuitos o necesidad del servicio, debidamente justificados: indica el motivo (RIT, Art. 20).');
            }
            if ($this->valorEfectivo('DocumentoSustentoId') === null) {
                $validator->errors()->add('DocumentoSustentoId', 'Sin reemplazante se necesita la autorización expresa por escrito del jefe del establecimiento: adjunta el documento (RIT, Art. 20).');
            }
        }
    }

    /** Un turno (como afectado o como contraparte) no puede estar en dos solicitudes pendientes a la vez. */
    private function unSoloCambioPendiente(Validator $validator, TurnoProgramado $turno, ?TurnoProgramado $contraparte): void
    {
        $ids = array_filter([$turno->TurnoProgramadoId, $contraparte?->TurnoProgramadoId]);
        $existe = CambioTurno::query()
            ->where('CambioTurnoEstado', 'PENDIENTE')
            ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
            ->where(fn ($q) => $q->whereIn('TurnoProgramadoId', $ids)->orWhereIn('TurnoProgramadoContraparteId', $ids))
            ->exists();
        if ($existe) {
            $validator->errors()->add('TurnoProgramadoId', 'Ese turno ya tiene un cambio de turno pendiente de resolver.');
        }
    }

    /** Peticion con 48 horas de anticipacion; con menos, solo de manera excepcional y sustentada con documento. */
    private function anticipacionDelPedido(Validator $validator, TurnoProgramado $turno, ?TurnoProgramado $contraparte): void
    {
        $primero = collect([$turno, $contraparte])->filter()->map(fn (TurnoProgramado $t) => $t->inicio())->min();
        $ahora = HoraLocal::ahora();
        if ($primero->lte($ahora)) {
            $validator->errors()->add('TurnoProgramadoId', 'El turno ya empezó o se realizó: no se puede pedir su cambio.');

            return;
        }
        if ($ahora->diffInHours($primero) < self::HORAS_DE_ANTICIPACION && $this->valorEfectivo('DocumentoSustentoId') === null) {
            $validator->errors()->add('DocumentoSustentoId', 'El cambio se pide con '.self::HORAS_DE_ANTICIPACION.' horas de anticipación; con menos solo procede de manera excepcional y sustentada: adjunta el documento (RIT, Art. 20).');
        }
    }

    /** Cuatro cambios por mes como maximo para cada servidor que pide o acepta; la guardia cuenta como dos (RIT, Art. 20). */
    private function topeMensual(Validator $validator, TurnoProgramado $turno, ?TurnoProgramado $contraparte, VinculoLaboral $solicitante, ?VinculoLaboral $reemplazante): void
    {
        $servicio = app(CambioTurnoService::class);
        $peso = CambioTurnoService::peso($turno, $contraparte);
        $fecha = $turno->TurnoProgramadoFecha->toDateString();
        $mes = date('m/Y', strtotime($fecha));

        foreach ([['VinculoLaboralSolicitanteId', $solicitante, 'El solicitante'], ['VinculoLaboralReemplazanteId', $reemplazante, 'El reemplazante']] as [$campo, $vinculo, $quien]) {
            if ($vinculo === null) {
                continue;
            }
            $usados = $servicio->cambiosDelMes($vinculo->TrabajadorId, $fecha, $this->registroId());
            if ($usados + $peso > CambioTurnoService::MAXIMO_POR_MES) {
                $validator->errors()->add($campo, "{$quien} ya tiene {$usados} de ".CambioTurnoService::MAXIMO_POR_MES." cambios de turno en {$mes}"
                    .($peso === 2 ? ' y una guardia cuenta como dos' : '').' (RIT, Art. 20).');
            }
        }
    }

    private function idOpcional(string $campo): ?int
    {
        $valor = $this->valorEfectivo($campo);

        return blank($valor) ? null : (int) $valor;
    }

    private function vinculo(string $campo): ?VinculoLaboral
    {
        $id = $this->idOpcional($campo);

        return $id ? VinculoLaboral::query()->find($id) : null;
    }
}
