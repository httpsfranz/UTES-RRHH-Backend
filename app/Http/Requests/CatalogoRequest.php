<?php

namespace App\Http\Requests;

use App\Rules\Codigo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

/**
 * Base de los Form Request de catalogo. Un solo Request sirve para store y update:
 * en POST las columnas NOT NULL son `required`; en PUT/PATCH son `sometimes`
 * (actualizacion parcial). Las restricciones de SQL Server (NOT NULL, UNIQUE,
 * FOREIGN KEY, CHECK, largo) se espejan aqui para devolver 422 legible en vez de 500.
 */
abstract class CatalogoRequest extends FormRequest
{
    /**
     * Palabras de los nombres de columna -> como se muestran en los mensajes de error.
     *
     * @var array<string,string>
     */
    private const ETIQUETAS = [
        'codigo' => 'código', 'nombre' => 'nombre', 'descripcion' => 'descripción', 'telefono' => 'teléfono',
        'direccion' => 'dirección', 'ubicacion' => 'ubicación', 'maximo' => 'máximo', 'dias' => 'días',
        'anio' => 'año', 'ip' => 'IP', 'eess' => 'establecimiento', 'id' => '', 'estado' => 'estado',
        'ubigeo' => 'ubigeo', 'legal' => 'legal', 'base' => 'base',
    ];

    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad (M04), true. Despues: $this->user()->puede(...).
        return true;
    }

    /**
     * Registro que se edita (null en POST): el modelo que resolvio el route model binding.
     */
    protected function registro(): ?Model
    {
        return collect($this->route()?->parameters() ?? [])->first(fn ($p) => $p instanceof Model);
    }

    /**
     * Id del registro que se edita. Permite que UNIQUE ignore la propia fila sin depender
     * del nombre del parametro de ruta.
     */
    protected function registroId(): int|string|null
    {
        return $this->registro()?->getKey();
    }

    /**
     * Valor que quedara guardado para un campo: el enviado, o el actual del registro
     * (en un PATCH parcial). Sirve para validar reglas que cruzan dos columnas.
     */
    protected function valorEfectivo(string $campo): mixed
    {
        return $this->exists($campo) ? $this->input($campo) : $this->registro()?->getAttribute($campo);
    }

    protected function esCreacion(): bool
    {
        return $this->isMethod('POST');
    }

    /** `required` al crear, `sometimes` al actualizar. */
    protected function obligatorio(): string
    {
        return $this->esCreacion() ? 'required' : 'sometimes';
    }

    /**
     * UNIQUE de una columna ignorando la fila que se edita.
     *
     * @param  class-string<Model>  $modelo
     */
    protected function unico(string $modelo, string $columna): Unique
    {
        return Rule::unique($modelo, $columna)->ignore($this->registroId(), (new $modelo)->getKeyName());
    }

    /**
     * FOREIGN KEY: la fila referenciada debe existir.
     *
     * @param  class-string<Model>  $modelo
     */
    protected function existe(string $modelo): Exists
    {
        return Rule::exists($modelo, (new $modelo)->getKeyName());
    }

    /**
     * Columna NVARCHAR NOT NULL.
     *
     * @return list<mixed>
     */
    protected function textoObligatorio(int $max): array
    {
        return [$this->obligatorio(), 'string', "max:{$max}"];
    }

    /**
     * Columna NVARCHAR NULL.
     *
     * @return list<mixed>
     */
    protected function texto(int $max): array
    {
        return ['nullable', 'string', "max:{$max}"];
    }

    /**
     * Columna de codigo NOT NULL UNIQUE.
     *
     * @param  class-string<Model>  $modelo
     * @return list<mixed>
     */
    protected function codigoUnico(string $modelo, string $columna, int $max): array
    {
        return [$this->obligatorio(), 'string', "max:{$max}", new Codigo, $this->unico($modelo, $columna)];
    }

    /**
     * Columna de nombre NOT NULL UNIQUE.
     *
     * @param  class-string<Model>  $modelo
     * @return list<mixed>
     */
    protected function nombreUnico(string $modelo, string $columna, int $max): array
    {
        return [$this->obligatorio(), 'string', "max:{$max}", $this->unico($modelo, $columna)];
    }

    /**
     * Columna BIT: opcional (la base asigna su DEFAULT).
     *
     * @return list<string>
     */
    protected function booleano(): array
    {
        return ['sometimes', 'boolean'];
    }

    /**
     * Las validaciones cruzadas (UNIQUE compuesto, fecha fin >= inicio) consultan la base:
     * solo corren si todo lo demas ya paso, para no mandar valores invalidos a SQL Server.
     */
    protected function despuesDeValidar(Validator $validator, callable $regla): void
    {
        $validator->after(function (Validator $validator) use ($regla) {
            if ($validator->errors()->isEmpty()) {
                $regla($validator);
            }
        });
    }

    /**
     * Nombres legibles: MicroredTelefono -> "teléfono", ColegiaturaTipoEntidad -> "entidad".
     *
     * @return array<string,string>
     */
    public function attributes(): array
    {
        $prefijo = preg_replace('/Request$/', '', class_basename($this));
        $atributos = [];

        foreach (array_keys($this->rules()) as $campo) {
            $resto = str_starts_with($campo, $prefijo) ? substr($campo, strlen($prefijo)) : $campo;
            $palabras = preg_split('/(?=[A-Z])/', $resto, -1, PREG_SPLIT_NO_EMPTY) ?: [$resto];
            $atributos[$campo] = trim(implode(' ', array_map(
                fn (string $p) => self::ETIQUETAS[strtolower($p)] ?? strtolower($p),
                $palabras,
            ))) ?: strtolower($campo);
        }

        return ['EessId' => 'establecimiento', 'MicroredId' => 'microred', 'ProfesionId' => 'profesión'] + $atributos;
    }
}
