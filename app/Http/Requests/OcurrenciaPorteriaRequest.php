<?php

namespace App\Http\Requests;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Solicitudes\OcurrenciaPorteria;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OcurrenciaPorteriaRequest extends CatalogoRequest
{
    /**
     * Tipos que registra el servicio de vigilancia segun el RIT (Art. 21): salidas con papeleta, su retorno,
     * el exceso de las 3 horas de la papeleta y el abandono sin autorizacion; OTRO queda abierto.
     */
    public const TIPOS = [
        'SALIDA_CON_PAPELETA', 'RETORNO_DE_PAPELETA', 'EXCESO_DE_PAPELETA',
        'SALIDA_SIN_AUTORIZACION', 'OTRO',
    ];

    /** El datetime-local del navegador envia "2026-09-28T06:30": se acepta igual que "2026-09-28 06:30". */
    protected function prepareForValidation(): void
    {
        $valor = $this->input('OcurrenciaPorteriaFechaHora');
        if (is_string($valor)) {
            $this->merge(['OcurrenciaPorteriaFechaHora' => str_replace('T', ' ', trim($valor))]);
        }
    }

    public function rules(): array
    {
        return [
            'EessId' => [$this->obligatorio(), 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            // Persona involucrada. Opcional solo para el tipo OTRO (ver withValidator).
            'VinculoLaboralId' => ['nullable', 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            // Quien registra. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioId' => ['nullable', 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioId')],
            'OcurrenciaPorteriaFechaHora' => ['sometimes', 'date_format:Y-m-d H:i,Y-m-d H:i:s'],
            'OcurrenciaPorteriaTipo' => [$this->obligatorio(), Rule::in(self::TIPOS)],
            'OcurrenciaPorteriaDescripcion' => $this->texto(1000),
            'OcurrenciaPorteriaEstado' => ['sometimes', Rule::in(OcurrenciaPorteria::ESTADOS)],
        ];
    }

    public function messages(): array
    {
        return [
            'OcurrenciaPorteriaFechaHora.date_format' => 'La fecha y hora deben tener el formato AAAA-MM-DD HH:MM.',
            'OcurrenciaPorteriaTipo.in' => 'El tipo de ocurrencia no es válido.',
            'OcurrenciaPorteriaEstado.in' => 'El estado debe ser REGISTRADO, ATENDIDO o ANULADO.',
        ];
    }

    /**
     * - Salvo OTRO, la ocurrencia involucra a una persona (vinculo); OTRO exige descripcion.
     * - La fecha no puede ser futura (se tolera un margen de 5 minutos por diferencia de relojes).
     * - Una ocurrencia nueva nace REGISTRADA; una ANULADA ya no se modifica (anular es definitivo).
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $tipo = $this->valorEfectivo('OcurrenciaPorteriaTipo');

            if ($tipo !== 'OTRO' && blank($this->valorEfectivo('VinculoLaboralId'))) {
                $validator->errors()->add('VinculoLaboralId', 'Indica la persona involucrada en la ocurrencia.');
            }
            if ($tipo === 'OTRO' && blank($this->valorEfectivo('OcurrenciaPorteriaDescripcion'))) {
                $validator->errors()->add('OcurrenciaPorteriaDescripcion', 'Describe la ocurrencia (el tipo "otro" no se explica solo).');
            }

            $fecha = $this->input('OcurrenciaPorteriaFechaHora');
            if ($fecha && strtotime($fecha) > now()->addMinutes(5)->getTimestamp()) {
                $validator->errors()->add('OcurrenciaPorteriaFechaHora', 'La fecha y hora de la ocurrencia no pueden ser futuras.');
            }

            if ($this->esCreacion() && $this->filled('OcurrenciaPorteriaEstado') && $this->input('OcurrenciaPorteriaEstado') !== 'REGISTRADO') {
                $validator->errors()->add('OcurrenciaPorteriaEstado', 'Una ocurrencia nueva se registra con estado REGISTRADO.');
            }
            if (! $this->esCreacion() && $this->registro()?->estaAnulada()) {
                $validator->errors()->add('OcurrenciaPorteriaEstado', 'La ocurrencia está anulada y ya no se puede modificar.');
            }
        });
    }
}
