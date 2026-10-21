<?php

namespace App\Http\Requests;

use App\Models\Personal\Colegiatura;
use App\Models\Personal\ColegiaturaTipo;
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

    /** Nombre de una profesion o colegio escrito con "Otro": letras, numeros, espacios y puntuacion simple. */
    private const NOMBRE_LIBRE = 'regex:/^[\pL\pN\s.,()\/-]+$/uD';

    /** La Red identifica a su personal solo con DNI: si el formulario no envia el tipo, es DNI. */
    protected function prepareForValidation(): void
    {
        if ($this->esCreacion() && ! $this->filled('TipoDocumentoIdentidadId')) {
            $this->merge(['TipoDocumentoIdentidadId' => TipoDocumentoIdentidad::query()->where('TipoDocumentoIdentidadCodigo', 'DNI')->value('TipoDocumentoIdentidadId')]);
        }
    }

    public function rules(): array
    {
        return [
            // Profesion "Otro": nombre de una profesion que aun no existe (se crea al guardar).
            'ProfesionNueva' => ['nullable', 'string', 'max:150', self::NOMBRE_LIBRE],
            // Colegiaturas del trabajador (se registran en el mismo formulario). Ausente = no se tocan.
            'Colegiaturas' => ['sometimes', 'array', 'max:5'],
            'Colegiaturas.*.ColegiaturaId' => ['nullable', 'integer'],
            'Colegiaturas.*.ColegiaturaTipoId' => ['nullable', 'integer', 'required_without:Colegiaturas.*.ColegiaturaTipoNuevo', $this->existeActivo(ColegiaturaTipo::class, 'ColegiaturaTipoEstado', 'ColegiaturaTipoId')],
            'Colegiaturas.*.ColegiaturaTipoNuevo' => ['nullable', 'string', 'max:150', self::NOMBRE_LIBRE],
            'Colegiaturas.*.ColegiaturaNumero' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/D'],
            'Colegiaturas.*.ColegiaturaFechaColegiatura' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'Colegiaturas.*.ColegiaturaFechaVencimiento' => ['nullable', 'date_format:Y-m-d'],
            'Colegiaturas.*.ColegiaturaEsHabilitado' => ['nullable', 'boolean'],
            'Colegiaturas.*.ColegiaturaEsPrincipal' => ['nullable', 'boolean'],
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

            $this->validarColegiaturas($validator);
        });
    }

    /**
     * Las mismas reglas que Personal.Colegiatura exige una por una (ColegiaturaRequest), sobre la lista del formulario:
     * un colegio por trabajador, una sola principal, numero unico por colegio y colegio de la profesion del trabajador.
     */
    private function validarColegiaturas(Validator $validator): void
    {
        $filas = $this->input('Colegiaturas');
        if (! is_array($filas)) {
            return;
        }
        $profesionId = filled($this->input('ProfesionNueva')) ? null : $this->valorEfectivo('ProfesionId');
        $profesion = $profesionId ? Profesion::find($profesionId) : null;

        if (count(array_filter($filas, fn ($f) => filter_var($f['ColegiaturaEsPrincipal'] ?? false, FILTER_VALIDATE_BOOLEAN))) > 1) {
            $validator->errors()->add('Colegiaturas', 'Solo una colegiatura puede ser la principal.');
        }

        $vistos = [];
        foreach ($filas as $i => $fila) {
            // Un colegio escrito con "Otro" que ya existe (mismo nombre) es ese colegio: se valida igual.
            $tipo = filled($fila['ColegiaturaTipoId'] ?? null)
                ? ColegiaturaTipo::with('profesion:ProfesionId,ProfesionNombre')->find($fila['ColegiaturaTipoId'])
                : (filled($fila['ColegiaturaTipoNuevo'] ?? null) ? ColegiaturaTipo::with('profesion:ProfesionId,ProfesionNombre')->whereRaw('LOWER(ColegiaturaTipoNombre) = ?', [mb_strtolower(trim((string) $fila['ColegiaturaTipoNuevo']))])->first() : null);
            $claveTipo = $tipo?->ColegiaturaTipoId ?? 'nuevo:'.mb_strtolower(trim((string) ($fila['ColegiaturaTipoNuevo'] ?? '')));
            if (isset($vistos[$claveTipo])) {
                $validator->errors()->add("Colegiaturas.{$i}.ColegiaturaTipoId", 'Ese colegio ya está en la lista.');
            }
            $vistos[$claveTipo] = true;

            if ($tipo?->ProfesionId && $profesion && $tipo->ProfesionId !== $profesion->ProfesionId) {
                $validator->errors()->add("Colegiaturas.{$i}.ColegiaturaTipoId", "Ese colegio corresponde a la profesión {$tipo->profesion->ProfesionNombre}, y el trabajador es {$profesion->ProfesionNombre}.");
            }

            if ($tipo !== null) {
                $repetido = Colegiatura::query()->where('ColegiaturaTipoId', $tipo->ColegiaturaTipoId)->where('ColegiaturaNumero', strtoupper((string) ($fila['ColegiaturaNumero'] ?? '')))
                    ->when($this->registroId(), fn ($q, $id) => $q->where('TrabajadorId', '!=', $id))->exists();
                if ($repetido) {
                    $validator->errors()->add("Colegiaturas.{$i}.ColegiaturaNumero", 'Ya existe una colegiatura de ese colegio con ese número.');
                }
            }
        }
    }
}
