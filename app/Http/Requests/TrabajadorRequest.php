<?php

namespace App\Http\Requests;

use App\Models\Personal\Profesion;
use App\Models\Personal\TipoDocumentoIdentidad;
use App\Models\Personal\Trabajador;
use App\Rules\DocumentoIdentidad;
use App\Rules\NombrePersona;
use App\Rules\TelefonoPeruano;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TrabajadorRequest extends CatalogoRequest
{
    /** Edad minima para ser servidor (mayoria de edad) y maxima razonable. */
    private const EDAD_MINIMA = 18;

    private const EDAD_MAXIMA = 100;

    public function rules(): array
    {
        return [
            'TipoDocumentoIdentidadId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(TipoDocumentoIdentidad::class, 'TipoDocumentoIdentidadEstado', 'TipoDocumentoIdentidadId'),
            ],
            'ProfesionId' => ['nullable', 'integer', $this->existeActivo(Profesion::class, 'ProfesionEstado', 'ProfesionId')],
            // El formato exacto depende del tipo de documento: se valida en withValidator.
            'TrabajadorNumeroDocumento' => [$this->obligatorio(), 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/D'],
            'TrabajadorNombres' => [$this->obligatorio(), 'string', 'max:100', new NombrePersona],
            'TrabajadorApellidoPaterno' => [$this->obligatorio(), 'string', 'max:100', new NombrePersona],
            'TrabajadorApellidoMaterno' => ['nullable', 'string', 'max:100', new NombrePersona],
            'TrabajadorSexo' => ['nullable', Rule::in(['M', 'F'])],
            'TrabajadorFechaNacimiento' => [
                'nullable', 'date_format:Y-m-d',
                'before_or_equal:'.now()->subYears(self::EDAD_MINIMA)->toDateString(),
                'after_or_equal:'.now()->subYears(self::EDAD_MAXIMA)->toDateString(),
            ],
            // Con punto en el dominio: "a@b" es sintacticamente valido pero no es un correo real.
            'TrabajadorCorreo' => ['nullable', 'string', 'email', 'max:200', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/D'],
            'TrabajadorTelefono' => ['nullable', new TelefonoPeruano],
            'TrabajadorDireccion' => $this->texto(300),
            'TrabajadorFotoRuta' => $this->texto(500),
            'TrabajadorEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'TrabajadorNumeroDocumento.regex' => 'El número de documento solo admite letras y números, sin espacios ni guiones.',
            'TrabajadorCorreo.regex' => 'Ingresa un correo electrónico válido (con dominio, por ejemplo nombre@dominio.pe).',
            'TrabajadorSexo.in' => 'El sexo debe ser M (masculino) o F (femenino).',
            'TrabajadorFechaNacimiento.before_or_equal' => 'El trabajador debe ser mayor de '.self::EDAD_MINIMA.' años.',
            'TrabajadorFechaNacimiento.after_or_equal' => 'La fecha de nacimiento no es válida.',
        ];
    }

    /**
     * Reglas que dependen de otra columna: el formato del numero segun el tipo de documento y el
     * UNIQUE (tipo, numero). Un PATCH parcial usa el valor guardado de la columna que no se envia.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $tipoId = $this->valorEfectivo('TipoDocumentoIdentidadId');
            $numero = $this->valorEfectivo('TrabajadorNumeroDocumento');
            $tipo = TipoDocumentoIdentidad::find($tipoId);

            if ($tipo) {
                $regla = new DocumentoIdentidad($tipo->TipoDocumentoIdentidadCodigo, $tipo->TipoDocumentoIdentidadLongitud);
                $regla->validate('TrabajadorNumeroDocumento', $numero, function (string $mensaje) use ($validator) {
                    $validator->errors()->add('TrabajadorNumeroDocumento', $mensaje);
                });
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $existe = Trabajador::query()
                ->where('TipoDocumentoIdentidadId', $tipoId)
                ->where('TrabajadorNumeroDocumento', $numero)
                ->when($this->registroId(), fn ($q, $id) => $q->where('TrabajadorId', '!=', $id))
                ->exists();

            if ($existe) {
                $validator->errors()->add('TrabajadorNumeroDocumento', 'Ya existe un trabajador con ese documento de identidad.');
            }
        });
    }
}
