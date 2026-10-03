<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Solicitudes\DescansoMedico;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Constatacion domiciliaria: visita que verifica el estado de salud de quien esta con descanso medico (RIT, Art. 23 d).
 * Nace PENDIENTE y se resuelve CONFORME o NO_CONFORME con su resultado; una vez resuelta, queda como constancia.
 */
class ConstatacionDomiciliariaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** ANULADO no se envia: se llega con DELETE. */
    public const ESTADOS_EDITABLES = ['PENDIENTE', 'CONFORME', 'NO_CONFORME'];

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'DescansoMedicoId' => ['nullable', 'integer', $this->existe(DescansoMedico::class)],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            // Quien registra. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioRegistroId' => ['nullable', 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioRegistroId')],
            'ConstatacionDomiciliariaFecha' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'ConstatacionDomiciliariaDireccion' => $this->texto(300),
            'ConstatacionDomiciliariaResultado' => $this->texto(500),
            'ConstatacionDomiciliariaEstado' => ['sometimes', 'in:'.implode(',', self::ESTADOS_EDITABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'ConstatacionDomiciliariaEstado.in' => 'El estado debe ser PENDIENTE, CONFORME o NO_CONFORME (para anular, elimina la constatación).',
            'ConstatacionDomiciliariaFecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
        ];
    }

    /**
     * - Nueva = PENDIENTE; resuelta (conforme o no) exige el resultado; una resuelta o anulada ya no se modifica.
     * - Si verifica un descanso medico: debe ser del mismo trabajador, no estar rechazado ni anulado, y la visita cae
     *   dentro de sus fechas (se verifica la salud DURANTE el descanso).
     * - El vinculo vigente ese dia.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $estado = $this->valorEfectivo('ConstatacionDomiciliariaEstado') ?? 'PENDIENTE';
            $actual = $this->registro()?->ConstatacionDomiciliariaEstado;

            if ($this->esCreacion() && $this->filled('ConstatacionDomiciliariaEstado') && $estado !== 'PENDIENTE') {
                $validator->errors()->add('ConstatacionDomiciliariaEstado', 'Una constatación nueva se registra PENDIENTE; se resuelve después con su resultado.');
            }
            if ($actual !== null && $actual !== 'PENDIENTE') {
                $validator->errors()->add('ConstatacionDomiciliariaEstado', 'La constatación ya está '.($actual === 'ANULADO' ? 'anulada' : 'resuelta').' y no se puede modificar.');

                return;
            }
            if (in_array($estado, ['CONFORME', 'NO_CONFORME'], true) && blank($this->valorEfectivo('ConstatacionDomiciliariaResultado'))) {
                $validator->errors()->add('ConstatacionDomiciliariaResultado', 'Indica el resultado de la visita para resolver la constatación.');
            }

            $fecha = $this->fechaEfectiva('ConstatacionDomiciliariaFecha');
            $this->vinculoVigenteEn($validator, 'ConstatacionDomiciliariaFecha', $fecha);

            $descanso = DescansoMedico::query()->find($this->valorEfectivo('DescansoMedicoId'));
            if ($descanso) {
                if ($descanso->VinculoLaboralId !== (int) $this->valorEfectivo('VinculoLaboralId')) {
                    $validator->errors()->add('DescansoMedicoId', 'El descanso médico es de otro trabajador.');
                } elseif (in_array($descanso->DescansoMedicoEstado, ['RECHAZADO', 'ANULADO'], true)) {
                    $validator->errors()->add('DescansoMedicoId', 'El descanso médico está rechazado o anulado.');
                } elseif ($fecha < $descanso->DescansoMedicoFechaInicio->toDateString() || $fecha > $descanso->DescansoMedicoFechaFin->toDateString()) {
                    $validator->errors()->add('ConstatacionDomiciliariaFecha', 'La visita debe hacerse dentro de las fechas del descanso médico ('.$this->fechaCorta($descanso->DescansoMedicoFechaInicio->toDateString()).' a '.$this->fechaCorta($descanso->DescansoMedicoFechaFin->toDateString()).').');
                }
            }
        });
    }
}
