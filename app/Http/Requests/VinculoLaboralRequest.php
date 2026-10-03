<?php

namespace App\Http\Requests;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\Cargo;
use App\Models\Personal\CondicionLaboral;
use App\Models\Personal\RegimenLaboral;
use App\Models\Personal\Trabajador;
use App\Models\Personal\VinculoLaboral;
use App\Rules\Codigo;
use Illuminate\Validation\Validator;

class VinculoLaboralRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TrabajadorId' => [$this->obligatorio(), 'integer', $this->existeActivo(Trabajador::class, 'TrabajadorEstado', 'TrabajadorId')],
            'EessId' => [$this->obligatorio(), 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            'RegimenLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(RegimenLaboral::class, 'RegimenLaboralEstado', 'RegimenLaboralId')],
            'CondicionLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(CondicionLaboral::class, 'CondicionLaboralEstado', 'CondicionLaboralId')],
            'CargoId' => [$this->obligatorio(), 'integer', $this->existeActivo(Cargo::class, 'CargoEstado', 'CargoId')],
            // Opcionales pero unicos cuando existen (indices filtrados UX_VinculoLaboral_Codigo y UX_VinculoLaboral_Airhsp).
            'VinculoLaboralCodigo' => ['nullable', 'string', 'max:50', new Codigo, $this->unico(VinculoLaboral::class, 'VinculoLaboralCodigo')],
            'VinculoLaboralCodigoAirhsp' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/D', $this->unico(VinculoLaboral::class, 'VinculoLaboralCodigoAirhsp')],
            'VinculoLaboralNumeroPlaza' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/.]*$/D'],
            'VinculoLaboralFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'VinculoLaboralFechaFin' => ['nullable', 'date_format:Y-m-d'],
            'VinculoLaboralMotivoCese' => $this->texto(300),
            'VinculoLaboralEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'VinculoLaboralCodigoAirhsp.regex' => 'El código AIRHSP solo admite letras y números.',
            'VinculoLaboralCodigoAirhsp.unique' => 'Ya existe un vínculo con ese código AIRHSP (identifica de forma única plaza y persona).',
            'VinculoLaboralNumeroPlaza.regex' => 'El número de plaza solo admite letras, números, guion, punto y barra.',
        ];
    }

    /**
     * Reglas entre columnas y con otras tablas:
     *  - fin >= inicio, y el motivo de cese solo tiene sentido con fecha de fin;
     *  - la condicion laboral puede exigir codigo AIRHSP (Personal.CondicionLaboral.RequiereAirhsp);
     *  - RIT Art. 86 (doble percepcion): un trabajador no puede tener dos vinculos ACTIVOS que se
     *    superpongan en el tiempo. El indice UX_VinculoLaboral_Vigente solo cubre dos vinculos abiertos;
     *    aqui se cubre tambien el solape de periodos con fecha de fin.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $inicio = $this->fechaEfectiva('VinculoLaboralFechaInicio');
            $fin = $this->fechaEfectiva('VinculoLaboralFechaFin');

            if ($fin !== null && $fin < $inicio) {
                $validator->errors()->add('VinculoLaboralFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');
            }
            if ($fin === null && filled($this->valorEfectivo('VinculoLaboralMotivoCese'))) {
                $validator->errors()->add('VinculoLaboralMotivoCese', 'El motivo de cese solo aplica cuando el vínculo tiene fecha de fin.');
            }

            $condicion = CondicionLaboral::find($this->valorEfectivo('CondicionLaboralId'));
            if ($condicion?->CondicionLaboralRequiereAirhsp && blank($this->valorEfectivo('VinculoLaboralCodigoAirhsp'))) {
                $validator->errors()->add('VinculoLaboralCodigoAirhsp', "La condición laboral \"{$condicion->CondicionLaboralNombre}\" requiere código AIRHSP.");
            }
            if ($validator->errors()->isNotEmpty() || ! $this->booleanoEfectivo('VinculoLaboralEstado')) {
                return;
            }

            // RIT Art. 86: el personal medico (con o sin especialidad) puede tener un segundo vinculo, de forma
            // excepcional y previa autorizacion de la Direccion General de Personal. La autorizacion aun no se
            // modela en la base: aqui solo se exceptua al medico de la regla de no superposicion.
            if ($this->esPersonalMedico()) {
                return;
            }

            $solapa = VinculoLaboral::query()
                ->where('TrabajadorId', $this->valorEfectivo('TrabajadorId'))
                ->where('VinculoLaboralEstado', 1)
                ->when($this->registroId(), fn ($q, $id) => $q->where('VinculoLaboralId', '!=', $id))
                ->whereDate('VinculoLaboralFechaInicio', '<=', $fin ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('VinculoLaboralFechaFin')->orWhereDate('VinculoLaboralFechaFin', '>=', $inicio))
                ->exists();

            if ($solapa) {
                $validator->errors()->add('TrabajadorId', 'El trabajador ya tiene un vínculo laboral activo en esas fechas (RIT, Art. 86: no se admite doble percepción).');
            }
        });
    }

    private function esPersonalMedico(): bool
    {
        return Trabajador::query()
            ->whereKey($this->valorEfectivo('TrabajadorId'))
            ->whereHas('profesion', fn ($q) => $q->where('ProfesionCodigo', 'MEDICO'))
            ->exists();
    }
}
