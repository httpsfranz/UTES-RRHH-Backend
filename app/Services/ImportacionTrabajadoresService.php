<?php

namespace App\Services;

use App\Rules\DocumentoIdentidad;
use App\Rules\NombrePersona;
use App\Rules\TelefonoPeruano;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as LectorCsv;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Alta masiva de TRABAJADORES (Personal.Trabajador) desde una hoja de calculo. Solo la persona: el vinculo laboral, el
 * cargo y el establecimiento se registran despues en su propia pantalla.
 *
 * Flujo: plantilla() entrega el modelo; analizar() lee el archivo y valida cada fila SIN guardar (con las mismas reglas de
 * TrabajadorRequest); importar() guarda las filas validas por lotes dentro de una transaccion. Se lee en flujo (fila a
 * fila), de modo que miles de filas no cargan el archivo completo en memoria.
 */
class ImportacionTrabajadoresService
{
    public const MAX_FILAS = 20000;

    /** Cabeceras de la plantilla: clave interna => titulo en el Excel. */
    public const COLUMNAS = [
        'tipo' => 'Tipo de documento', 'numero' => 'Número de documento', 'paterno' => 'Apellido paterno', 'materno' => 'Apellido materno',
        'nombres' => 'Nombres', 'sexo' => 'Sexo', 'nacimiento' => 'Fecha de nacimiento', 'correo' => 'Correo', 'telefono' => 'Teléfono',
        'direccion' => 'Dirección', 'profesion' => 'Profesión',
    ];

    private const EDAD_MINIMA = 18;

    private const EDAD_MAXIMA = 100;

    /** Escribe la plantilla en un archivo temporal y devuelve su ruta. */
    public function plantilla(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plt').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($ruta);

        $escritor->getCurrentSheet()->setName('Trabajadores');
        $negrita = (new Style)->withFontBold(true)->withBackgroundColor('D9EEF0');
        $texto = (new Style)->withFormat('@');
        $escritor->addRow(Row::fromValuesWithStyle(array_values(self::COLUMNAS), $negrita));
        // Fila de ejemplo: se borra antes de cargar (o se deja: el numero de documento ya existente se rechaza).
        $escritor->addRow(Row::fromValuesWithStyles(['DNI', '12345678', 'QUISPE', 'MAMANI', 'JUAN CARLOS', 'M', '1990-05-17', 'juan.quispe@correo.pe', '987654321', 'Av. España 123, Trujillo', 'Enfermería'], [1 => $texto, 8 => $texto]));

        $escritor->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        $filas = [
            ['Cómo llenar la hoja «Trabajadores»'],
            ['• Una fila por trabajador, a partir de la fila 2. Borra la fila de ejemplo.'],
            ['• Obligatorios: Tipo de documento, Número de documento, Apellido paterno y Nombres.'],
            ['• El número de documento va como texto, sin espacios ni guiones (el DNI tiene 8 dígitos).'],
            ['• Sexo: M o F. Fecha de nacimiento: AAAA-MM-DD o DD/MM/AAAA (mayor de 18 años).'],
            ['• Teléfono: celular de 9 dígitos que empieza con 9, o teléfono fijo.'],
            ['• Profesión: escríbela igual que en la lista de abajo (puede quedar vacía).'],
            ['• Un documento que ya existe en el sistema, o repetido en el archivo, se rechaza.'],
            [''],
            ['Tipos de documento válidos'],
        ];
        foreach (DB::table('Personal.TipoDocumentoIdentidad')->where('TipoDocumentoIdentidadEstado', 1)->orderBy('TipoDocumentoIdentidadCodigo')->get(['TipoDocumentoIdentidadCodigo', 'TipoDocumentoIdentidadNombre']) as $t) {
            $filas[] = [$t->TipoDocumentoIdentidadCodigo.' — '.$t->TipoDocumentoIdentidadNombre];
        }
        $filas[] = [''];
        $filas[] = ['Profesiones válidas'];
        foreach (DB::table('Personal.Profesion')->where('ProfesionEstado', 1)->orderBy('ProfesionNombre')->pluck('ProfesionNombre') as $nombre) {
            $filas[] = [$nombre];
        }
        foreach ($filas as $i => $fila) {
            $escritor->addRow(Row::fromValues($fila));
        }
        $escritor->close();

        return $ruta;
    }

    /**
     * Lee y valida el archivo sin guardar nada.
     *
     * @return array{total:int,validas:list<array<string,mixed>>,errores:list<array{fila:int,campo:string,mensaje:string}>,filas_con_error:int}
     */
    public function analizar(string $ruta, string $extension): array
    {
        $lector = $this->abrir($ruta, $extension);
        $tipos = $this->catalogo('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadId', ['TipoDocumentoIdentidadCodigo', 'TipoDocumentoIdentidadAbreviatura', 'TipoDocumentoIdentidadNombre'], 'TipoDocumentoIdentidadEstado');
        $profesiones = $this->catalogo('Personal.Profesion', 'ProfesionId', ['ProfesionNombre', 'ProfesionCodigo'], 'ProfesionEstado');
        $tiposInfo = DB::table('Personal.TipoDocumentoIdentidad')->get(['TipoDocumentoIdentidadId', 'TipoDocumentoIdentidadCodigo', 'TipoDocumentoIdentidadLongitud'])->keyBy('TipoDocumentoIdentidadId');

        $mapa = null;
        $total = 0;
        $validas = [];
        $errores = [];
        $filasConError = [];
        $vistos = [];

        try {
            foreach ($lector->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $numeroFila => $fila) {
                    $celdas = $fila->toArray();
                    if ($mapa === null) {
                        $mapa = $this->mapearCabeceras($celdas);
                        if ($mapa === null) {
                            continue;
                        }

                        // La fila de cabecera se reconocio: lo que sigue son datos.
                        continue;
                    }
                    if ($this->filaVacia($celdas)) {
                        continue;
                    }
                    if (++$total > self::MAX_FILAS) {
                        $errores[] = ['fila' => $numeroFila, 'campo' => 'Archivo', 'mensaje' => 'El archivo supera el máximo de '.self::MAX_FILAS.' filas por carga: divídelo en varios.'];
                        break 2;
                    }

                    [$datos, $problemas] = $this->validarFila($celdas, $mapa, $tipos, $profesiones, $tiposInfo);
                    $clave = ($datos['TipoDocumentoIdentidadId'] ?? '').'|'.($datos['TrabajadorNumeroDocumento'] ?? '');
                    if ($problemas === [] && isset($vistos[$clave])) {
                        $problemas[] = ['Número de documento', "Repetido en el archivo (también en la fila {$vistos[$clave]})."];
                    }
                    if ($problemas === []) {
                        $vistos[$clave] = $numeroFila;
                        $validas[] = ['fila' => $numeroFila] + $datos;
                    } else {
                        $filasConError[$numeroFila] = true;
                        foreach ($problemas as [$campo, $mensaje]) {
                            $errores[] = ['fila' => $numeroFila, 'campo' => $campo, 'mensaje' => $mensaje];
                        }
                    }
                }
                break; // solo la primera hoja
            }
        } finally {
            $lector->close();
        }

        if ($mapa === null) {
            return ['total' => 0, 'validas' => [], 'filas_con_error' => 1, 'errores' => [['fila' => 1, 'campo' => 'Cabecera', 'mensaje' => 'No se encontraron las columnas de la plantilla (al menos Tipo de documento, Número de documento, Apellido paterno y Nombres). Descarga la plantilla y úsala.']]];
        }

        // Documentos que ya existen en el sistema
        $existentes = $this->documentosExistentes($validas);
        $validasFinal = [];
        foreach ($validas as $fila) {
            if (isset($existentes[$fila['TipoDocumentoIdentidadId'].'|'.$fila['TrabajadorNumeroDocumento']])) {
                $filasConError[$fila['fila']] = true;
                $errores[] = ['fila' => $fila['fila'], 'campo' => 'Número de documento', 'mensaje' => 'Ya existe un trabajador con ese documento de identidad.'];
            } else {
                $validasFinal[] = $fila;
            }
        }
        usort($errores, fn ($a, $b) => $a['fila'] <=> $b['fila']);

        return ['total' => $total, 'validas' => $validasFinal, 'errores' => $errores, 'filas_con_error' => count($filasConError)];
    }

    /**
     * Guarda las filas ya validadas, de a 150 (SQL Server admite 2100 parametros por consulta), dentro de una transaccion.
     *
     * @param  list<array<string,mixed>>  $filas
     */
    public function importar(array $filas): int
    {
        return DB::transaction(function () use ($filas) {
            foreach (array_chunk($filas, 150) as $lote) {
                DB::table('Personal.Trabajador')->insert(array_map(function (array $f) {
                    unset($f['fila']);

                    return $f + ['TrabajadorEstado' => 1];
                }, $lote));
            }

            return count($filas);
        });
    }

    // ------------------------------------------------------------------ lectura

    private function abrir(string $ruta, string $extension): ReaderInterface
    {
        $lector = strtolower($extension) === 'csv' ? new LectorCsv : new LectorXlsx;
        $lector->open($ruta);

        return $lector;
    }

    /**
     * @param  list<mixed>  $celdas
     * @return array<string,int>|null clave interna => indice de columna
     */
    private function mapearCabeceras(array $celdas): ?array
    {
        $mapa = [];
        foreach ($celdas as $i => $valor) {
            $texto = $this->normalizar((string) $valor);
            foreach (self::COLUMNAS as $clave => $titulo) {
                if ($texto === $this->normalizar($titulo)) {
                    $mapa[$clave] = $i;
                }
            }
        }

        return isset($mapa['tipo'], $mapa['numero'], $mapa['paterno'], $mapa['nombres']) ? $mapa : null;
    }

    /** @param  list<mixed>  $celdas */
    private function filaVacia(array $celdas): bool
    {
        foreach ($celdas as $c) {
            if (trim((string) ($c instanceof DateTimeInterface ? $c->format('Y-m-d') : $c)) !== '') {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ validacion de una fila

    /**
     * @param  list<mixed>  $celdas
     * @param  array<string,int>  $mapa
     * @param  array<string,int>  $tipos
     * @param  array<string,int>  $profesiones
     * @return array{0:array<string,mixed>,1:list<array{0:string,1:string}>}
     */
    private function validarFila(array $celdas, array $mapa, array $tipos, array $profesiones, $tiposInfo): array
    {
        $valor = function (string $clave) use ($celdas, $mapa): ?string {
            $v = isset($mapa[$clave]) ? ($celdas[$mapa[$clave]] ?? null) : null;
            if ($v instanceof DateTimeInterface) {
                return $v->format('Y-m-d');
            }
            if (is_float($v) && floor($v) === $v) {
                $v = (int) $v;
            }
            $texto = trim(preg_replace('/\s+/', ' ', (string) $v));

            return $texto === '' ? null : $texto;
        };

        $problemas = [];
        $datos = [];

        $tipoTexto = $valor('tipo');
        $tipoId = $tipoTexto === null ? null : ($tipos[$this->normalizar($tipoTexto)] ?? null);
        if ($tipoId === null) {
            $problemas[] = ['Tipo de documento', $tipoTexto === null ? 'Es obligatorio.' : "«{$tipoTexto}» no es un tipo de documento válido (DNI, CE, PAS, PTP)."];
        }
        $datos['TipoDocumentoIdentidadId'] = $tipoId;

        $numero = $valor('numero');
        if ($numero !== null && $tipoId !== null && ($tiposInfo[$tipoId]->TipoDocumentoIdentidadCodigo ?? '') === 'DNI' && preg_match('/^\d{1,7}$/', $numero) && ! is_string($celdas[$mapa['numero']] ?? null)) {
            $numero = str_pad($numero, 8, '0', STR_PAD_LEFT); // Excel quita los ceros de la izquierda
        }
        $datos['TrabajadorNumeroDocumento'] = $numero;

        $nacimiento = $valor('nacimiento');
        $nacimiento = $nacimiento === null ? null : $this->normalizarFecha($nacimiento);
        $sexo = $valor('sexo');
        $sexo = $sexo === null ? null : (['M' => 'M', 'F' => 'F', 'MASCULINO' => 'M', 'FEMENINO' => 'F', 'HOMBRE' => 'M', 'MUJER' => 'F'][strtoupper(Str::ascii($sexo))] ?? $sexo);

        $datos += [
            'TrabajadorNombres' => $valor('nombres'), 'TrabajadorApellidoPaterno' => $valor('paterno'), 'TrabajadorApellidoMaterno' => $valor('materno'),
            'TrabajadorSexo' => $sexo, 'TrabajadorFechaNacimiento' => $nacimiento, 'TrabajadorCorreo' => $valor('correo'),
            'TrabajadorTelefono' => $valor('telefono'), 'TrabajadorDireccion' => $valor('direccion'),
        ];

        $profesionTexto = $valor('profesion');
        $datos['ProfesionId'] = null;
        if ($profesionTexto !== null) {
            $profesionId = $profesiones[$this->normalizar($profesionTexto)] ?? null;
            $profesionId === null ? $problemas[] = ['Profesión', "«{$profesionTexto}» no está en el catálogo de profesiones."] : $datos['ProfesionId'] = $profesionId;
        }

        $etiquetas = [
            'TrabajadorNumeroDocumento' => 'Número de documento', 'TrabajadorNombres' => 'Nombres', 'TrabajadorApellidoPaterno' => 'Apellido paterno',
            'TrabajadorApellidoMaterno' => 'Apellido materno', 'TrabajadorSexo' => 'Sexo', 'TrabajadorFechaNacimiento' => 'Fecha de nacimiento',
            'TrabajadorCorreo' => 'Correo', 'TrabajadorTelefono' => 'Teléfono', 'TrabajadorDireccion' => 'Dirección',
        ];
        $validador = Validator::make($datos, [
            'TrabajadorNumeroDocumento' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/D'],
            'TrabajadorNombres' => ['required', 'string', 'max:100', new NombrePersona],
            'TrabajadorApellidoPaterno' => ['required', 'string', 'max:100', new NombrePersona],
            'TrabajadorApellidoMaterno' => ['nullable', 'string', 'max:100', new NombrePersona],
            'TrabajadorSexo' => ['nullable', 'in:M,F'],
            'TrabajadorFechaNacimiento' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->subYears(self::EDAD_MINIMA)->toDateString(), 'after_or_equal:'.now()->subYears(self::EDAD_MAXIMA)->toDateString()],
            'TrabajadorCorreo' => ['nullable', 'string', 'email', 'max:200', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/D'],
            'TrabajadorTelefono' => ['nullable', new TelefonoPeruano],
            'TrabajadorDireccion' => ['nullable', 'string', 'max:300'],
        ], [
            'required' => 'Es obligatorio.', 'regex' => 'Formato no válido: solo letras y números, sin espacios ni guiones.', 'in' => 'Debe ser M o F.',
            'date_format' => 'Fecha no válida (usa AAAA-MM-DD o DD/MM/AAAA).', 'before_or_equal' => 'Debe ser mayor de '.self::EDAD_MINIMA.' años.',
            'after_or_equal' => 'La fecha de nacimiento no es válida.', 'email' => 'Correo no válido.', 'max' => 'Es demasiado largo.',
        ], $etiquetas);

        foreach ($validador->errors()->messages() as $campo => $mensajes) {
            $problemas[] = [$etiquetas[$campo] ?? $campo, $mensajes[0]];
        }

        if ($tipoId !== null && $numero !== null && ! $validador->errors()->has('TrabajadorNumeroDocumento')) {
            (new DocumentoIdentidad($tiposInfo[$tipoId]->TipoDocumentoIdentidadCodigo, (int) $tiposInfo[$tipoId]->TipoDocumentoIdentidadLongitud))
                ->validate('TrabajadorNumeroDocumento', $numero, function (string $mensaje) use (&$problemas) {
                    $problemas[] = ['Número de documento', $mensaje];
                });
        }

        return [$datos, $problemas];
    }

    private function normalizarFecha(string $texto): string
    {
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $texto, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (is_numeric($texto) && (float) $texto > 1000) { // numero de serie de Excel
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $texto)->toDateString();
        }

        return $texto;
    }

    private function normalizar(string $texto): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', Str::ascii($texto))));
    }

    /**
     * Catalogo para buscar por varios textos (codigo, abreviatura, nombre).
     *
     * @param  list<string>  $columnasTexto
     * @return array<string,int> texto normalizado => id
     */
    private function catalogo(string $tabla, string $columnaId, array $columnasTexto, string $columnaEstado): array
    {
        $mapa = [];
        foreach (DB::table($tabla)->where($columnaEstado, 1)->get() as $fila) {
            foreach ($columnasTexto as $c) {
                if (filled($fila->{$c})) {
                    $mapa[$this->normalizar((string) $fila->{$c})] = (int) $fila->{$columnaId};
                }
            }
        }

        return $mapa;
    }

    /**
     * @param  list<array<string,mixed>>  $filas
     * @return array<string,true> "tipoId|numero" que ya estan en la base
     */
    private function documentosExistentes(array $filas): array
    {
        $existentes = [];
        foreach (array_chunk(array_column($filas, 'TrabajadorNumeroDocumento'), 1000) as $numeros) {
            foreach (DB::table('Personal.Trabajador')->whereIn('TrabajadorNumeroDocumento', $numeros)->get(['TipoDocumentoIdentidadId', 'TrabajadorNumeroDocumento']) as $t) {
                $existentes[$t->TipoDocumentoIdentidadId.'|'.$t->TrabajadorNumeroDocumento] = true;
            }
        }

        return $existentes;
    }
}
