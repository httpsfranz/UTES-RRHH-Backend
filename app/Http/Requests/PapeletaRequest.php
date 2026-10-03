<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Solicitudes\MotivoPapeleta;
use App\Models\Solicitudes\Papeleta;
use App\Models\Solicitudes\TipoPapeleta;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Papeleta de permiso o salida (RIT, Art. 14 y 57 a 63). Nace PENDIENTE; la autoriza el jefe con la accion aprobar.
 * RIT: la papeleta de comision de servicios vale como maximo 3 horas; el exceso se justifica con documento.
 */
class PapeletaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** Horas maximas de una papeleta de comision de servicios (RIT, Art. 14). */
    public const MAXIMO_COMISION_MINUTOS = 180;

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'TipoPapeletaId' => [$this->obligatorio(), 'integer', $this->existeActivo(TipoPapeleta::class, 'TipoPapeletaEstado', 'TipoPapeletaId')],
            'MotivoPapeletaId' => ['nullable', 'integer', $this->existeActivo(MotivoPapeleta::class, 'MotivoPapeletaEstado', 'MotivoPapeletaId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            // Quien registra. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioRegistroId' => ['nullable', 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioRegistroId')],
            'PapeletaNumero' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/.]*$/D', $this->unico(Papeleta::class, 'PapeletaNumero')],
            'PapeletaFecha' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'PapeletaHoraSalida' => ['nullable', 'date_format:H:i,H:i:s'],
            'PapeletaHoraRetorno' => ['nullable', 'date_format:H:i,H:i:s'],
            'PapeletaEsDiaCompleto' => $this->booleano(),
            'PapeletaMinutosUtilizados' => ['nullable', 'integer', 'between:0,1440'],
            'PapeletaMotivo' => $this->texto(1000),
            'PapeletaObservacion' => $this->texto(1000),
        ];
    }

    public function messages(): array
    {
        return [
            'PapeletaNumero.regex' => 'El número solo admite letras, números, guion, barra y punto.',
            'PapeletaHoraSalida.date_format' => 'La hora de salida debe tener el formato HH:MM.',
            'PapeletaHoraRetorno.date_format' => 'La hora de retorno debe tener el formato HH:MM.',
            'PapeletaMinutosUtilizados.between' => 'Los minutos utilizados deben estar entre 0 y 1440.',
        ];
    }

    /**
     * - El vinculo vigente ese dia y el periodo de asistencia no cerrado.
     * - Dia completo = sin horas; si no, hora de salida obligatoria y retorno no anterior a la salida.
     * - La comision de servicios no pasa de 3 horas (RIT, Art. 14).
     * - El motivo debe pertenecer al tipo (FK compuesta de la tabla).
     * - Sin superposicion con otra papeleta (pendiente o aprobada) del mismo trabajador ese dia.
     * - Solo se modifica pendiente; el estado y quien autoriza no se envian.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'PapeletaEstado');
            $this->soloSiPendiente($validator, 'PapeletaEstado', 'PapeletaEstado', 'La papeleta');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fecha = $this->fechaEfectiva('PapeletaFecha');
            $diaCompleto = $this->booleanoEfectivo('PapeletaEsDiaCompleto', false);
            $salida = $this->hora('PapeletaHoraSalida');
            $retorno = $this->hora('PapeletaHoraRetorno');

            $this->vinculoVigenteEn($validator, 'PapeletaFecha', $fecha);
            $this->rechazaPeriodoCerrado($validator, 'PapeletaFecha', $fecha);

            if ($diaCompleto && ($salida || $retorno)) {
                $validator->errors()->add('PapeletaEsDiaCompleto', 'Una papeleta de día completo no lleva horas de salida ni de retorno.');
            }
            if (! $diaCompleto && ! $salida) {
                $validator->errors()->add('PapeletaHoraSalida', 'Indica la hora de salida (o marca la papeleta como de día completo).');
            }
            if ($salida && $retorno && $retorno < $salida) {
                $validator->errors()->add('PapeletaHoraRetorno', 'La hora de retorno no puede ser anterior a la de salida.');
            }

            $tipo = TipoPapeleta::query()->find($this->valorEfectivo('TipoPapeletaId'));
            if ($tipo?->TipoPapeletaCodigo === 'COMISION' && $salida && $retorno
                && (strtotime("1970-01-01 {$retorno}") - strtotime("1970-01-01 {$salida}")) / 60 > self::MAXIMO_COMISION_MINUTOS) {
                $validator->errors()->add('PapeletaHoraRetorno', 'La papeleta de comisión de servicios vale como máximo 3 horas (RIT, Art. 14); el exceso se justifica con documento.');
            }

            $motivoId = $this->valorEfectivo('MotivoPapeletaId');
            if ($motivoId && MotivoPapeleta::query()->whereKey($motivoId)->where('TipoPapeletaId', $this->valorEfectivo('TipoPapeletaId'))->doesntExist()) {
                $validator->errors()->add('MotivoPapeletaId', 'El motivo no pertenece al tipo de papeleta elegido.');
            }

            $hasta = $retorno ?? '23:59:59';
            $solapa = Papeleta::query()
                ->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))
                ->whereDate('PapeletaFecha', $fecha)
                ->whereNotIn('PapeletaEstado', ['RECHAZADO', 'ANULADO'])
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                // Un dia completo choca con cualquier otra papeleta del dia; las papeletas por horas, solo si las horas se cruzan.
                ->when(! $diaCompleto, fn ($q) => $q->where(fn ($w) => $w->where('PapeletaEsDiaCompleto', 1)
                    ->orWhereNull('PapeletaHoraSalida')
                    ->orWhere(fn ($h) => $h->where('PapeletaHoraSalida', '<', $hasta)
                        ->where(fn ($r) => $r->whereNull('PapeletaHoraRetorno')->orWhere('PapeletaHoraRetorno', '>', $salida ?? '00:00:00')))))
                ->exists();
            if ($solapa) {
                $validator->errors()->add('PapeletaFecha', 'El trabajador ya tiene una papeleta pendiente o aprobada que se superpone ese día.');
            }
        });
    }

    /** "HH:MM:SS" de la hora que quedara guardada (null si no hay). */
    private function hora(string $campo): ?string
    {
        $valor = $this->valorEfectivo($campo);
        if (blank($valor)) {
            return null;
        }

        return strlen((string) $valor) === 5 ? "{$valor}:00" : substr((string) $valor, 0, 8);
    }

    public function datos(): array
    {
        $datos = $this->validated();

        foreach (['PapeletaHoraSalida', 'PapeletaHoraRetorno'] as $campo) {
            if (isset($datos[$campo]) && strlen($datos[$campo]) === 5) {
                $datos[$campo] .= ':00';
            }
        }
        // Dia completo: se limpian las horas que hubieran quedado de una edicion anterior.
        if ($this->booleanoEfectivo('PapeletaEsDiaCompleto', false)) {
            $datos['PapeletaHoraSalida'] = null;
            $datos['PapeletaHoraRetorno'] = null;
        }
        // Un valor vacio (el formulario lo envia asi) significa "calcular", no NULL.
        if (array_key_exists('PapeletaMinutosUtilizados', $datos) && $datos['PapeletaMinutosUtilizados'] === null) {
            unset($datos['PapeletaMinutosUtilizados']);
        }
        // Minutos utilizados = retorno - salida, salvo que se indiquen.
        $salida = $this->hora('PapeletaHoraSalida');
        $retorno = $this->hora('PapeletaHoraRetorno');
        if (! array_key_exists('PapeletaMinutosUtilizados', $datos) && $salida && $retorno && ! $this->booleanoEfectivo('PapeletaEsDiaCompleto', false)) {
            $datos['PapeletaMinutosUtilizados'] = (int) ((strtotime("1970-01-01 {$retorno}") - strtotime("1970-01-01 {$salida}")) / 60);
        }

        return $datos;
    }
}
