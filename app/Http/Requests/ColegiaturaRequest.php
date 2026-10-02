<?php

namespace App\Http\Requests;

use App\Models\Personal\Colegiatura;
use App\Models\Personal\ColegiaturaTipo;
use App\Models\Personal\Trabajador;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

class ColegiaturaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TrabajadorId' => [$this->obligatorio(), 'integer', $this->existeActivo(Trabajador::class, 'TrabajadorEstado', 'TrabajadorId')],
            'ColegiaturaTipoId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(ColegiaturaTipo::class, 'ColegiaturaTipoEstado', 'ColegiaturaTipoId'),
            ],
            // Constancia de colegiatura/habilitacion (Soporte.DocumentoSustento).
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'ColegiaturaNumero' => [$this->obligatorio(), 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/D'],
            'ColegiaturaFechaColegiatura' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'ColegiaturaFechaHabilitacion' => ['nullable', 'date_format:Y-m-d'],
            'ColegiaturaFechaVencimiento' => ['nullable', 'date_format:Y-m-d'],
            'ColegiaturaEsHabilitado' => $this->booleano(),
            'ColegiaturaEsPrincipal' => $this->booleano(),
            'ColegiaturaObservacion' => $this->texto(500),
            'ColegiaturaEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'ColegiaturaNumero.regex' => 'El número de colegiatura solo admite letras y números, sin espacios ni guiones.',
            'ColegiaturaFechaColegiatura.before_or_equal' => 'La fecha de colegiatura no puede ser futura.',
        ];
    }

    /**
     * Reglas entre columnas y con otras tablas:
     *  - fechas en orden: colegiatura <= habilitacion <= vencimiento;
     *  - UNIQUE (tipo, numero) y UNIQUE (trabajador, tipo);
     *  - una sola colegiatura PRINCIPAL activa por trabajador (indice UX_Colegiatura_Principal);
     *  - el tipo de colegiatura debe corresponder a la profesion del trabajador (un CMP no es de una enfermera).
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $colegiado = $this->fechaEfectiva('ColegiaturaFechaColegiatura');
            $habilitado = $this->fechaEfectiva('ColegiaturaFechaHabilitacion');
            $vence = $this->fechaEfectiva('ColegiaturaFechaVencimiento');

            if ($colegiado && $habilitado && $habilitado < $colegiado) {
                $validator->errors()->add('ColegiaturaFechaHabilitacion', 'La habilitación no puede ser anterior a la fecha de colegiatura.');
            }
            if ($vence && ($habilitado ?? $colegiado) && $vence < ($habilitado ?? $colegiado)) {
                $validator->errors()->add('ColegiaturaFechaVencimiento', 'El vencimiento no puede ser anterior a la habilitación.');
            }

            $tipoId = $this->valorEfectivo('ColegiaturaTipoId');
            $trabajadorId = $this->valorEfectivo('TrabajadorId');
            $tipo = ColegiaturaTipo::with('profesion:ProfesionId,ProfesionNombre')->find($tipoId);
            $trabajador = Trabajador::with('profesion:ProfesionId,ProfesionNombre')->find($trabajadorId);

            if ($tipo?->ProfesionId && $trabajador?->ProfesionId && $tipo->ProfesionId !== $trabajador->ProfesionId) {
                $validator->errors()->add(
                    'ColegiaturaTipoId',
                    "Ese colegio corresponde a la profesión {$tipo->profesion->ProfesionNombre}, y el trabajador es {$trabajador->profesion->ProfesionNombre}."
                );
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $otras = Colegiatura::query()->when($this->registroId(), fn ($q, $id) => $q->where('ColegiaturaId', '!=', $id));

            if ((clone $otras)->where('ColegiaturaTipoId', $tipoId)->where('ColegiaturaNumero', $this->valorEfectivo('ColegiaturaNumero'))->exists()) {
                $validator->errors()->add('ColegiaturaNumero', 'Ya existe una colegiatura de ese colegio con ese número.');
            }
            if ((clone $otras)->where('TrabajadorId', $trabajadorId)->where('ColegiaturaTipoId', $tipoId)->exists()) {
                $validator->errors()->add('ColegiaturaTipoId', 'El trabajador ya tiene registrada una colegiatura de ese colegio.');
            }
            if (
                $this->booleanoEfectivo('ColegiaturaEsPrincipal', false) && $this->booleanoEfectivo('ColegiaturaEstado')
                && (clone $otras)->where('TrabajadorId', $trabajadorId)->where('ColegiaturaEsPrincipal', 1)->where('ColegiaturaEstado', 1)->exists()
            ) {
                $validator->errors()->add('ColegiaturaEsPrincipal', 'El trabajador ya tiene otra colegiatura principal: quítale esa marca primero.');
            }
        });
    }
}
