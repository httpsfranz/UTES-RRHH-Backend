<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Supervision inopinada (RIT, Art. 24 y 29): visita sin aviso de Recursos Humanos para verificar la asistencia y
 * permanencia del personal. El jefe del establecimiento responde las observaciones del acta en un plazo de 3 dias.
 */
class SupervisionInopinadaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** ANULADO no se envia: se llega con DELETE. */
    public const ESTADOS_EDITABLES = ['REGISTRADO', 'CONFORME', 'OBSERVADO'];

    /** El datetime-local del navegador envia "2026-09-28T06:30". */
    protected function prepareForValidation(): void
    {
        $valor = $this->input('SupervisionInopinadaFechaHora');
        if (is_string($valor)) {
            $this->merge(['SupervisionInopinadaFechaHora' => str_replace('T', ' ', trim($valor))]);
        }
    }

    public function rules(): array
    {
        return [
            'EessId' => [$this->obligatorio(), 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            'VinculoLaboralId' => ['nullable', 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            // Supervisor. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioId')],
            // Acta de supervision escaneada.
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'SupervisionInopinadaFechaHora' => ['sometimes', 'date_format:Y-m-d H:i,Y-m-d H:i:s'],
            'SupervisionInopinadaResultado' => $this->texto(500),
            'SupervisionInopinadaObservacion' => $this->texto(1000),
            'SupervisionInopinadaEstado' => ['sometimes', 'in:'.implode(',', self::ESTADOS_EDITABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'SupervisionInopinadaFechaHora.date_format' => 'La fecha y hora deben tener el formato AAAA-MM-DD HH:MM.',
            'SupervisionInopinadaEstado.in' => 'El estado debe ser REGISTRADO, CONFORME u OBSERVADO (para anular, elimina la supervisión).',
        ];
    }

    /**
     * - Nueva = REGISTRADO; anulada ya no se modifica; la fecha no es futura.
     * - Si se supervisa a una persona, trabaja en el establecimiento supervisado.
     * - OBSERVADO exige las observaciones del acta (el jefe responde sobre ellas en 3 dias, RIT Art. 29).
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $estado = $this->valorEfectivo('SupervisionInopinadaEstado') ?? 'REGISTRADO';

            if ($this->esCreacion() && $this->filled('SupervisionInopinadaEstado') && $estado !== 'REGISTRADO') {
                $validator->errors()->add('SupervisionInopinadaEstado', 'Una supervisión nueva se registra con estado REGISTRADO.');
            }
            if (! $this->esCreacion() && $this->registro()?->SupervisionInopinadaEstado === 'ANULADO') {
                $validator->errors()->add('SupervisionInopinadaEstado', 'La supervisión está anulada y ya no se puede modificar.');
            }

            $fecha = $this->input('SupervisionInopinadaFechaHora');
            if ($fecha && strtotime($fecha) > now()->addMinutes(5)->getTimestamp()) {
                $validator->errors()->add('SupervisionInopinadaFechaHora', 'La fecha y hora de la supervisión no pueden ser futuras.');
            }

            $vinculo = VinculoLaboral::query()->find($this->valorEfectivo('VinculoLaboralId'));
            if ($vinculo && $vinculo->EessId !== (int) $this->valorEfectivo('EessId')) {
                $validator->errors()->add('VinculoLaboralId', 'El trabajador supervisado no trabaja en ese establecimiento.');
            }

            if ($estado === 'OBSERVADO' && blank($this->valorEfectivo('SupervisionInopinadaObservacion'))) {
                $validator->errors()->add('SupervisionInopinadaObservacion', 'Indica las observaciones del acta de supervisión.');
            }
        });
    }
}
