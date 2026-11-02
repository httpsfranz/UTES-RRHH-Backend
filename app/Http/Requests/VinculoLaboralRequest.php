<?php

namespace App\Http\Requests;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\Cargo;
use App\Models\Personal\CondicionLaboral;
use App\Models\Personal\RegimenLaboral;
use App\Models\Personal\Trabajador;
use App\Models\Personal\VinculoLaboral;
use App\Rules\Codigo;
use App\Support\ReglasDeSolicitud;
use Illuminate\Validation\Validator;

class VinculoLaboralRequest extends CatalogoRequest
{
    /** Causas de termino del servicio (RIT, Art. 129). */
    public const CAUSAS_DE_CESE = ['FALLECIMIENTO', 'RENUNCIA', 'INCAPACIDAD', 'DESTITUCION', 'PERIODO_DE_PRUEBA', 'FIN_DE_CONTRATO', 'INHABILITACION', 'SENTENCIA', 'TERMINO_DE_DESIGNACION', 'MUTUO_DISENSO', 'LIMITE_DE_EDAD', 'OTRO'];

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
            'VinculoLaboralIngresoMensual' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'VinculoLaboralCausaCese' => ['nullable', 'in:'.implode(',', self::CAUSAS_DE_CESE)],
            'VinculoLaboralFechaRenuncia' => ['nullable', 'date_format:Y-m-d'],
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

            $causa = $this->valorEfectivo('VinculoLaboralCausaCese');
            if ($causa === 'RENUNCIA') {
                $renuncia = $this->valorEfectivo('VinculoLaboralFechaRenuncia');
                $renuncia = $renuncia ? substr((string) $renuncia, 0, 10) : null;
                if ($renuncia === null) {
                    $validator->errors()->add('VinculoLaboralFechaRenuncia', 'Indica la fecha en que presentó la renuncia (RIT, Art. 130).');
                } elseif ($fin !== null && ! $this->boolean('ExoneraPlazoRenuncia') && (strtotime($fin) - strtotime($renuncia)) / 86400 < 30) {
                    $validator->errors()->add('VinculoLaboralFechaRenuncia', 'La renuncia se presenta con 30 días de anticipación, salvo exoneración (RIT, Art. 130).');
                }
                if (ReglasDeSolicitud::tienePadEnCurso((int) $this->registroId())) {
                    $validator->errors()->add('VinculoLaboralCausaCese', 'Con un procedimiento disciplinario en curso no se presenta renuncia (RIT, Art. 107).');
                }
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
