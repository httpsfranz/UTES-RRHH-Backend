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
        'ubigeo' => 'ubigeo', 'legal' => 'legal', 'base' => 'base', 'correo' => 'correo electrónico',
        'numero' => 'número', 'categoria' => 'categoría', 'jornada' => 'jornada', 'tolerancia' => 'tolerancia',
        'minutos' => 'minutos', 'descuento' => 'descuento', 'vigencia' => 'vigencia', 'horas' => 'horas',
        'diarias' => 'diarias', 'semanales' => 'semanales', 'mensuales' => 'mensuales', 'foto' => 'foto',
        'nacimiento' => 'nacimiento', 'apellido' => 'apellido', 'medianoche' => 'medianoche', 'duracion' => 'duración',
        'inasistencia' => 'inasistencia', 'refrigerio' => 'refrigerio', 'tipo' => 'tipo', 'es' => '',
        'password' => 'contraseña', 'airhsp' => 'código AIRHSP', 'plaza' => 'plaza', 'cese' => 'cese', 'motivo' => 'motivo',
        'colegiatura' => 'colegiatura', 'habilitacion' => 'habilitación', 'vencimiento' => 'vencimiento', 'observacion' => 'observación',
        'dedo' => 'dedo', 'referencia' => 'referencia', 'aceptado' => 'consentimiento', 'version' => 'versión', 'orden' => 'orden',
        'dia' => 'día', 'hora' => 'hora', 'fecha' => 'fecha', 'inicio' => 'inicio', 'fin' => 'fin',
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

    /** valorEfectivo() de una columna DATE, normalizado a "Y-m-d" (o null). */
    protected function fechaEfectiva(string $campo): ?string
    {
        $valor = $this->valorEfectivo($campo);

        return $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : ($valor === null || $valor === '' ? null : (string) $valor);
    }

    /** valorEfectivo() de una columna BIT como booleano (null = el DEFAULT de la base, que es 1 en los *Estado). */
    protected function booleanoEfectivo(string $campo, bool $porDefecto = true): bool
    {
        return filter_var($this->valorEfectivo($campo) ?? $porDefecto, FILTER_VALIDATE_BOOLEAN);
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
     * FOREIGN KEY a un registro ACTIVO: no se puede asignar algo dado de baja. Si el registro que se
     * edita ya apunta a uno inactivo, ese valor se sigue aceptando (un PATCH que reenvia el formulario
     * completo no debe fallar por un dato que el usuario no toco).
     *
     * @param  class-string<Model>  $modelo
     */
    protected function existeActivo(string $modelo, string $columnaEstado, string $campo): Exists
    {
        $pk = (new $modelo)->getKeyName();
        $actual = $this->registro()?->getAttribute($campo);

        return Rule::exists($modelo, $pk)->where(fn ($q) => $q->where(
            fn ($w) => $w->where($columnaEstado, 1)->when($actual !== null, fn ($w) => $w->orWhere($pk, $actual))
        ));
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

        return [
            'EessId' => 'establecimiento', 'MicroredId' => 'microred', 'ProfesionId' => 'profesión',
            'TipoDocumentoIdentidadId' => 'tipo de documento', 'GrupoOcupacionalId' => 'grupo ocupacional',
            'TipoJornadaId' => 'tipo de jornada', 'TablaToleranciaId' => 'tabla de tolerancia',
            'TipoPapeletaId' => 'tipo de papeleta', 'RolId' => 'rol', 'PermisoId' => 'permiso',
            'TipoEstablecimientoId' => 'tipo de establecimiento', 'RegimenLaboralId' => 'régimen laboral',
            'CondicionLaboralId' => 'condición laboral', 'CargoId' => 'cargo', 'TrabajadorId' => 'trabajador',
            'ColegiaturaTipoId' => 'tipo de colegiatura', 'DocumentoSustentoId' => 'documento de sustento',
            'MetodoMarcacionId' => 'método de marcación', 'HorarioId' => 'horario', 'TurnoId' => 'turno',
            'VinculoLaboralId' => 'vínculo laboral', 'UsuarioId' => 'usuario',
        ] + $atributos;
    }
}
