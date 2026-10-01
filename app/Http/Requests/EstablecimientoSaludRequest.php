<?php

namespace App\Http\Requests;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Organizacion\Microred;
use App\Models\Organizacion\TipoEstablecimiento;
use App\Rules\TelefonoPeruano;
use App\Rules\Ubigeo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EstablecimientoSaludRequest extends CatalogoRequest
{
    /** Categorias de establecimientos de salud (NTS de categorias del MINSA). */
    public const CATEGORIAS = ['I-1', 'I-2', 'I-3', 'I-4', 'II-1', 'II-2', 'II-E', 'III-1', 'III-2', 'III-E'];

    public function rules(): array
    {
        return [
            'MicroredId' => [$this->obligatorio(), 'integer', $this->existeActivo(Microred::class, 'MicroredEstado', 'MicroredId')],
            'TipoEstablecimientoId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(TipoEstablecimiento::class, 'TipoEstablecimientoEstado', 'TipoEstablecimientoId'),
            ],
            'EessCodigo' => $this->codigoUnico(EstablecimientoSalud::class, 'EessCodigo', 30),
            // Codigo RENIPRESS (IPRESS): 8 digitos. Unico cuando existe (indice filtrado UX_Eess_Renipres).
            'EessCodigoRenipres' => ['nullable', 'string', 'regex:/^\d{8}$/D', $this->unico(EstablecimientoSalud::class, 'EessCodigoRenipres')],
            'EessNombre' => $this->textoObligatorio(150),
            'EessCategoria' => ['nullable', Rule::in(self::CATEGORIAS)],
            'EessUbigeo' => ['nullable', new Ubigeo],
            'EessDireccion' => $this->texto(300),
            'EessTelefono' => ['nullable', new TelefonoPeruano],
            'EessDescripcion' => $this->texto(300),
            'EessEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'EessCodigoRenipres.regex' => 'El código RENIPRESS debe tener exactamente 8 dígitos numéricos.',
            'EessCodigoRenipres.unique' => 'Ya existe un establecimiento con ese código RENIPRESS.',
            'EessCategoria.in' => 'La categoría debe ser una de: '.implode(', ', self::CATEGORIAS).'.',
        ];
    }

    /** UNIQUE (MicroredId, EessNombre): el mismo nombre puede repetirse en microredes distintas. */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $existe = EstablecimientoSalud::query()
                ->where('MicroredId', $this->valorEfectivo('MicroredId'))
                ->where('EessNombre', $this->valorEfectivo('EessNombre'))
                ->when($this->registroId(), fn ($q, $id) => $q->where('EessId', '!=', $id))
                ->exists();

            if ($existe) {
                $validator->errors()->add('EessNombre', 'Ya existe un establecimiento con ese nombre en la microred.');
            }
        });
    }
}
