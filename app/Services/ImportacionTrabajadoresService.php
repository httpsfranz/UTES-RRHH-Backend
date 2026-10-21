<?php

namespace App\Services;

use App\Http\Requests\AsignacionHorarioRequest;
use App\Http\Requests\VinculoLaboralRequest;
use App\Models\Personal\AsignacionHorario;
use App\Models\Personal\VinculoLaboral;
use App\Rules\DocumentoIdentidad;
use App\Rules\NombrePersona;
use App\Rules\TelefonoPeruano;
use App\Support\AlcanceDeEess;
use App\Support\HojaDeCalculo;
use App\Support\ValidacionDeFormulario;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Alta masiva de TRABAJADORES (Personal.Trabajador) desde una hoja de calculo y, si la fila lo trae, su VINCULO LABORAL
 * (establecimiento, regimen, condicion, cargo) y su HORARIO, como el asistente "Nuevo trabajador".
 *
 * Flujo: plantilla() entrega el modelo; analizar() lee el archivo y valida cada fila SIN guardar; importar() guarda las
 * filas validas dentro de una transaccion. Se lee en flujo (fila a fila), de modo que miles de filas no cargan el archivo
 * completo en memoria.
 *
 *   - Los datos de la persona se validan con las reglas de TrabajadorRequest y se guardan por lotes.
 *   - El vinculo y el horario pasan por VinculoLaboralRequest y AsignacionHorarioRequest (las del formulario, incluida la
 *     doble percepcion del RIT Art. 86). Como esas reglas consultan la base, al analizar se ensaya el guardado completo
 *     y se deshace.
 *   - Un documento que ya existe solo se acepta si la fila trae un vinculo: se le agrega al trabajador registrado (sus
 *     datos personales no se tocan) y, si la fila trae el apellido paterno, debe coincidir.
 *   - La fila es la unidad: si su vinculo o su horario no son validos, tampoco se registra la persona.
 */
class ImportacionTrabajadoresService
{
    public const MAX_FILAS = 20000;

    /** Cabeceras de la plantilla: clave interna => titulo en el Excel. */
    public const COLUMNAS = [
        'tipo' => 'Tipo de documento', 'numero' => 'Número de documento', 'paterno' => 'Apellido paterno', 'materno' => 'Apellido materno',
        'nombres' => 'Nombres', 'sexo' => 'Sexo', 'nacimiento' => 'Fecha de nacimiento', 'correo' => 'Correo', 'telefono' => 'Teléfono',
        'direccion' => 'Dirección', 'profesion' => 'Profesión',
        'eess' => 'Establecimiento', 'regimen' => 'Régimen laboral', 'condicion' => 'Condición laboral', 'cargo' => 'Cargo',
        'inicio' => 'Inicio del vínculo', 'airhsp' => 'Código AIRHSP', 'plaza' => 'Número de plaza', 'horario' => 'Horario',
    ];

    /** Columnas del vinculo: si una tiene valor, la fila registra un vinculo. */
    private const COLUMNAS_VINCULO = ['eess', 'regimen', 'condicion', 'cargo', 'inicio', 'airhsp', 'plaza'];

    /** Campo del Form Request => columna del Excel donde se senala el error. */
    private const CAMPOS_VINCULO = [
        'EessId' => 'Establecimiento', 'RegimenLaboralId' => 'Régimen laboral', 'CondicionLaboralId' => 'Condición laboral',
        'CargoId' => 'Cargo', 'VinculoLaboralFechaInicio' => 'Inicio del vínculo', 'VinculoLaboralCodigoAirhsp' => 'Código AIRHSP',
        'VinculoLaboralNumeroPlaza' => 'Número de plaza',
    ];

    private const EDAD_MINIMA = 18;

    private const EDAD_MAXIMA = 100;

    /** Escribe la plantilla en un archivo temporal y devuelve su ruta. */
    public function plantilla(?Authenticatable $usuario = null): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plt').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($ruta);

        $establecimientos = DB::table('Organizacion.EstablecimientoSalud')->where('EessEstado', 1)
            ->when(AlcanceDeEess::permitidos($usuario) !== null, fn ($q) => $q->whereIn('EessId', AlcanceDeEess::permitidos($usuario)))
            ->orderBy('EessCodigo')->get(['EessCodigo', 'EessNombre']);
        $regimenes = DB::table('Personal.RegimenLaboral')->where('RegimenLaboralEstado', 1)->orderBy('RegimenLaboralCodigo')->get(['RegimenLaboralCodigo', 'RegimenLaboralNombre']);
        $condiciones = DB::table('Personal.CondicionLaboral')->where('CondicionLaboralEstado', 1)->orderBy('CondicionLaboralCodigo')
            ->get(['CondicionLaboralCodigo', 'CondicionLaboralNombre', 'CondicionLaboralRequiereAirhsp']);
        $cargos = DB::table('Personal.Cargo')->where('CargoEstado', 1)->orderBy('CargoNombre')->get(['CargoCodigo', 'CargoNombre']);
        $horarios = DB::table('Configuracion.Horario as h')->leftJoin('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'h.EessId')
            ->where('h.HorarioEstado', 1)->orderBy('h.HorarioCodigo')->get(['h.HorarioCodigo', 'h.HorarioNombre', 'e.EessCodigo']);

        $escritor->getCurrentSheet()->setName('Trabajadores');
        $negrita = (new Style)->withFontBold(true)->withBackgroundColor('D9EEF0');
        $negritaVinculo = (new Style)->withFontBold(true)->withBackgroundColor('FCE9C8');
        $texto = (new Style)->withFormat('@');
        $cabecera = array_values(self::COLUMNAS);
        $escritor->addRow(Row::fromValuesWithStyles($cabecera, array_map(
            fn (int $i) => $i >= array_search('eess', array_keys(self::COLUMNAS), true) ? $negritaVinculo : $negrita,
            array_keys($cabecera),
        )));
        // Fila de ejemplo: se borra antes de cargar (o se deja: el numero de documento ya existente se rechaza).
        $escritor->addRow(Row::fromValuesWithStyles([
            'DNI', '12345678', 'QUISPE', 'MAMANI', 'JUAN CARLOS', 'M', '1990-05-17', 'juan.quispe@correo.pe', '987654321', 'Av. España 123, Trujillo', 'Enfermería',
            $establecimientos->first()->EessCodigo ?? '', $regimenes->first()->RegimenLaboralCodigo ?? '', $condiciones->first()->CondicionLaboralCodigo ?? '',
            $cargos->first()->CargoCodigo ?? '', '2026-01-02', '000123', '', $horarios->first()->HorarioCodigo ?? '',
        ], [1 => $texto, 8 => $texto, 16 => $texto]));

        $escritor->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        $filas = [
            ['Cómo llenar la hoja «Trabajadores»'],
            ['• Una fila por trabajador, a partir de la fila 2. Borra la fila de ejemplo.'],
            ['• Obligatorios: Tipo de documento, Número de documento, Apellido paterno y Nombres.'],
            ['• El número de documento va como texto, sin espacios ni guiones (el DNI tiene 8 dígitos).'],
            ['• Sexo: M o F. Fecha de nacimiento: AAAA-MM-DD o DD/MM/AAAA (mayor de 18 años).'],
            ['• Teléfono: celular de 9 dígitos que empieza con 9, o teléfono fijo.'],
            ['• Profesión: escríbela igual que en la lista de abajo (puede quedar vacía).'],
            ['• Un documento repetido en el archivo se rechaza.'],
            [''],
            ['Vínculo laboral y horario (columnas naranjas, opcionales)'],
            ['• Si las dejas vacías, solo se registra la persona y el vínculo se agrega después desde su ficha.'],
            ['• Si llenas el vínculo, son obligatorios: Establecimiento, Régimen laboral, Condición laboral, Cargo e Inicio del vínculo.'],
            ['• Escribe el código o el nombre tal como aparecen en las listas de abajo. La fecha de inicio va como AAAA-MM-DD o DD/MM/AAAA.'],
            ['• Las condiciones marcadas con (AIRHSP) exigen el Código AIRHSP.'],
            ['• Horario (opcional): se asigna desde la fecha de inicio del vínculo. Un horario propio de un establecimiento solo vale para ese establecimiento.'],
            ['• Si el trabajador ya está registrado, la fila solo le agrega el vínculo: basta el tipo y número de documento (sus datos personales no se cambian).'],
            ['• Un trabajador no puede tener dos vínculos activos en las mismas fechas (RIT, Art. 86), salvo el personal médico.'],
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
        $listas = [
            'Establecimientos' => $establecimientos->map(fn ($e) => $e->EessCodigo.' — '.$e->EessNombre),
            'Regímenes laborales' => $regimenes->map(fn ($r) => $r->RegimenLaboralCodigo.' — '.$r->RegimenLaboralNombre),
            'Condiciones laborales' => $condiciones->map(fn ($c) => $c->CondicionLaboralCodigo.' — '.$c->CondicionLaboralNombre.($c->CondicionLaboralRequiereAirhsp ? ' (AIRHSP)' : '')),
            'Cargos' => $cargos->map(fn ($c) => $c->CargoCodigo.' — '.$c->CargoNombre),
            'Horarios' => $horarios->map(fn ($h) => $h->HorarioCodigo.' — '.$h->HorarioNombre.($h->EessCodigo ? " (solo {$h->EessCodigo})" : '')),
        ];
        foreach ($listas as $titulo => $valores) {
            $filas[] = [''];
            $filas[] = [$titulo];
            foreach ($valores as $valor) {
                $filas[] = [$valor];
            }
        }
        foreach ($filas as $fila) {
            $escritor->addRow(Row::fromValues($fila));
        }
        $escritor->close();

        return $ruta;
    }

    /**
     * Lee y valida el archivo sin guardar nada. Con $ensayar = false no se ensaya el guardado de los vinculos (lo hace
     * importar(), que igual los valida uno por uno): sirve para confirmar sin recorrer dos veces las reglas de la base.
     *
     * @return array{total:int,validas:list<array<string,mixed>>,errores:list<array{fila:int,campo:string,mensaje:string}>,filas_con_error:int}
     */
    public function analizar(string $ruta, string $extension, ?Authenticatable $usuario = null, bool $ensayar = true): array
    {
        $catalogos = [
            'tipos' => $this->catalogo('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadId', ['TipoDocumentoIdentidadCodigo', 'TipoDocumentoIdentidadAbreviatura', 'TipoDocumentoIdentidadNombre'], 'TipoDocumentoIdentidadEstado'),
            'profesiones' => $this->catalogo('Personal.Profesion', 'ProfesionId', ['ProfesionNombre', 'ProfesionCodigo'], 'ProfesionEstado'),
            'eess' => $this->catalogo('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo', 'EessNombre', 'EessCodigoRenipres'], 'EessEstado'),
            'regimen' => $this->catalogo('Personal.RegimenLaboral', 'RegimenLaboralId', ['RegimenLaboralCodigo', 'RegimenLaboralNombre'], 'RegimenLaboralEstado'),
            'condicion' => $this->catalogo('Personal.CondicionLaboral', 'CondicionLaboralId', ['CondicionLaboralCodigo', 'CondicionLaboralNombre'], 'CondicionLaboralEstado'),
            'cargo' => $this->catalogo('Personal.Cargo', 'CargoId', ['CargoCodigo', 'CargoNombre'], 'CargoEstado'),
            'horario' => $this->catalogo('Configuracion.Horario', 'HorarioId', ['HorarioCodigo', 'HorarioNombre'], 'HorarioEstado'),
        ];
        $tiposInfo = DB::table('Personal.TipoDocumentoIdentidad')->get(['TipoDocumentoIdentidadId', 'TipoDocumentoIdentidadCodigo', 'TipoDocumentoIdentidadLongitud'])->keyBy('TipoDocumentoIdentidadId');
        $registrados = $this->trabajadoresRegistrados();
        $permitidos = AlcanceDeEess::permitidos($usuario);

        $mapa = null;
        $total = 0;
        $validas = [];
        $errores = [];
        $filasConError = [];
        $vistos = [];

        foreach (HojaDeCalculo::filas($ruta, $extension) as $numeroFila => $celdas) {
            if ($mapa === null) {
                // Hasta reconocer la fila de cabecera se saltan las filas; lo que sigue a ella son datos.
                $mapa = $this->mapearCabeceras($celdas);

                continue;
            }
            if (HojaDeCalculo::vacia($celdas)) {
                continue;
            }
            if (++$total > self::MAX_FILAS) {
                $errores[] = ['fila' => $numeroFila, 'campo' => 'Archivo', 'mensaje' => 'El archivo supera el máximo de '.self::MAX_FILAS.' filas por carga: divídelo en varios.'];
                break;
            }

            $valor = $this->lectorDeCeldas($celdas, $mapa);
            [$vinculo, $problemasVinculo] = $this->leerVinculo($valor, $catalogos, $permitidos);
            [$datos, $problemas] = $this->validarFila($valor, $celdas, $mapa, $catalogos, $tiposInfo);
            $clave = ($datos['TipoDocumentoIdentidadId'] ?? '').'|'.($datos['TrabajadorNumeroDocumento'] ?? '');

            $registrado = $registrados[$clave] ?? null;
            $trabajadorId = null;
            if ($registrado !== null && $vinculo['vinculo'] === null && $problemasVinculo === []) {
                $problemas[] = ['Número de documento', 'Ya existe un trabajador con ese documento de identidad (para agregarle un vínculo, llena las columnas del vínculo).'];
            } elseif ($registrado !== null) {
                // Solo se le agrega el vinculo: sus datos personales no se validan ni se cambian.
                $problemas = array_values(array_filter($problemas, fn ($p) => in_array($p[0], ['Tipo de documento', 'Número de documento'], true)));
                $paterno = $valor('paterno');
                if ($paterno !== null && HojaDeCalculo::normalizar($paterno) !== HojaDeCalculo::normalizar((string) $registrado->TrabajadorApellidoPaterno)) {
                    $problemas[] = ['Apellido paterno', "El documento pertenece a {$registrado->TrabajadorNombreCompleto}: revisa el número."];
                }
                $trabajadorId = (int) $registrado->TrabajadorId;
            }
            $problemas = [...$problemas, ...$problemasVinculo];

            if ($problemas === [] && isset($vistos[$clave])) {
                $problemas[] = ['Número de documento', "Repetido en el archivo (también en la fila {$vistos[$clave]})."];
            }
            if ($problemas === []) {
                $vistos[$clave] = $numeroFila;
                $validas[] = ['fila' => $numeroFila, 'trabajador' => $trabajadorId === null ? $datos : null, 'trabajador_id' => $trabajadorId] + $vinculo;
            } else {
                $filasConError[$numeroFila] = true;
                foreach ($problemas as [$campo, $mensaje]) {
                    $errores[] = ['fila' => $numeroFila, 'campo' => $campo, 'mensaje' => $mensaje];
                }
            }
        }

        if ($mapa === null) {
            return ['total' => 0, 'validas' => [], 'filas_con_error' => 1, 'errores' => [['fila' => 1, 'campo' => 'Cabecera', 'mensaje' => 'No se encontraron las columnas de la plantilla (al menos Tipo de documento, Número de documento, Apellido paterno y Nombres). Descarga la plantilla y úsala.']]];
        }

        // El vinculo y el horario se validan con las reglas del formulario: se ensaya el guardado y se deshace.
        if ($ensayar && $this->traenVinculo($validas)) {
            $ensayo = $this->guardar($validas, $usuario, false);
            $validas = $this->sinRechazadas($validas, $ensayo['errores']);
            foreach ($ensayo['errores'] as $error) {
                $filasConError[$error['fila']] = true;
                $errores[] = $error;
            }
        }
        usort($errores, fn ($a, $b) => $a['fila'] <=> $b['fila']);

        return ['total' => $total, 'validas' => $validas, 'errores' => $errores, 'filas_con_error' => count($filasConError)];
    }

    /**
     * Guarda las filas validas de analizar() en una transaccion. Las filas cuyo vinculo u horario no pasan las reglas del
     * formulario (cuando se analizo sin ensayar, o porque otro usuario registro lo mismo entretanto) se devuelven en
     * `errores`: si se pidio omitirlas, se guarda el resto; si no, no se guarda nada.
     *
     * @param  list<array<string,mixed>>  $filas
     * @return array{trabajadores:int,vinculos:int,horarios:int,errores:list<array{fila:int,campo:string,mensaje:string}>}
     */
    public function importar(array $filas, ?Authenticatable $usuario = null, bool $omitirErrores = false): array
    {
        $resultado = $this->guardar($filas, $usuario, true, $omitirErrores);

        return $resultado['errores'] !== [] && ! $omitirErrores ? ['trabajadores' => 0, 'vinculos' => 0, 'horarios' => 0] + $resultado : $resultado;
    }

    /** @param  list<array<string,mixed>>  $filas */
    public function traenVinculo(array $filas): bool
    {
        foreach ($filas as $fila) {
            if ($fila['vinculo'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $filas
     * @param  list<array{fila:int}>  $errores
     * @return list<array<string,mixed>>
     */
    public function sinRechazadas(array $filas, array $errores): array
    {
        $rechazadas = array_flip(array_column($errores, 'fila'));

        return array_values(array_filter($filas, fn ($f) => ! isset($rechazadas[$f['fila']])));
    }

    // ------------------------------------------------------------------ guardado

    /**
     * Las filas sin vinculo se registran por lotes de 150 (SQL Server admite 2100 parametros por consulta). Las que traen
     * vinculo, una por una dentro de su propio punto de guardado: persona, vinculo y horario validados con los Form Request;
     * si algo falla, se deshace solo esa fila. Se confirma al final solo si $confirmar y (no hubo errores u $omitir).
     *
     * @param  list<array<string,mixed>>  $filas
     * @return array{errores:list<array{fila:int,campo:string,mensaje:string}>,trabajadores:int,vinculos:int,horarios:int}
     */
    private function guardar(array $filas, ?Authenticatable $usuario, bool $confirmar, bool $omitir = false): array
    {
        $resultado = ['errores' => [], 'trabajadores' => 0, 'vinculos' => 0, 'horarios' => 0];

        DB::beginTransaction();
        try {
            $soloPersona = array_values(array_filter($filas, fn ($f) => $f['vinculo'] === null));
            foreach (array_chunk($soloPersona, 150) as $lote) {
                DB::table('Personal.Trabajador')->insert(array_map(fn ($f) => $f['trabajador'] + ['TrabajadorEstado' => 1], $lote));
            }
            $resultado['trabajadores'] = count($soloPersona);

            foreach ($filas as $fila) {
                if ($fila['vinculo'] === null) {
                    continue;
                }
                $problemas = $this->guardarConVinculo($fila, $usuario);
                if ($problemas !== []) {
                    foreach ($problemas as [$campo, $mensaje]) {
                        $resultado['errores'][] = ['fila' => $fila['fila'], 'campo' => $campo, 'mensaje' => $mensaje];
                    }

                    continue;
                }
                $resultado['trabajadores'] += $fila['trabajador'] !== null ? 1 : 0;
                $resultado['vinculos']++;
                $resultado['horarios'] += $fila['horario_id'] !== null ? 1 : 0;
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $confirmar && ($resultado['errores'] === [] || $omitir) ? DB::commit() : DB::rollBack();

        return $resultado;
    }

    /**
     * Persona (si es nueva), vinculo y horario de una fila, todo o nada.
     *
     * @param  array<string,mixed>  $fila
     * @return list<array{0:string,1:string}> problemas (vacio = guardada)
     */
    private function guardarConVinculo(array $fila, ?Authenticatable $usuario): array
    {
        DB::beginTransaction();
        try {
            $trabajadorId = $fila['trabajador_id'] ?? (int) DB::table('Personal.Trabajador')->insertGetId($fila['trabajador'] + ['TrabajadorEstado' => 1], 'TrabajadorId');

            [$datos, $problemas] = ValidacionDeFormulario::alta(VinculoLaboralRequest::class, $fila['vinculo'] + ['TrabajadorId' => $trabajadorId], $usuario);
            if ($problemas !== []) {
                DB::rollBack();

                return $this->enColumnas($problemas, self::CAMPOS_VINCULO, 'Vínculo');
            }
            $vinculo = VinculoLaboral::create($datos);

            if ($fila['horario_id'] !== null) {
                [$datos, $problemas] = ValidacionDeFormulario::alta(AsignacionHorarioRequest::class, [
                    'VinculoLaboralId' => $vinculo->VinculoLaboralId,
                    'HorarioId' => $fila['horario_id'],
                    'AsignacionHorarioFechaInicio' => $fila['vinculo']['VinculoLaboralFechaInicio'],
                ], $usuario);
                if ($problemas !== []) {
                    DB::rollBack();

                    return $this->enColumnas($problemas, [], 'Horario');
                }
                AsignacionHorario::create($datos);
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        DB::commit();

        return [];
    }

    /**
     * @param  array<string,string>  $problemas  campo => mensaje
     * @param  array<string,string>  $columnas  campo => columna del Excel
     * @return list<array{0:string,1:string}>
     */
    private function enColumnas(array $problemas, array $columnas, string $porDefecto): array
    {
        $salida = [];
        foreach ($problemas as $campo => $mensaje) {
            $salida[] = [$columnas[$campo] ?? $porDefecto, $mensaje];
        }

        return $salida;
    }

    // ------------------------------------------------------------------ lectura

    /**
     * @param  list<mixed>  $celdas
     * @return array<string,int>|null clave interna => indice de columna
     */
    private function mapearCabeceras(array $celdas): ?array
    {
        $mapa = [];
        foreach ($celdas as $i => $valor) {
            $texto = HojaDeCalculo::normalizar((string) $valor);
            foreach (self::COLUMNAS as $clave => $titulo) {
                if ($texto === HojaDeCalculo::normalizar($titulo)) {
                    $mapa[$clave] = $i;
                }
            }
        }

        return isset($mapa['tipo'], $mapa['numero'], $mapa['paterno'], $mapa['nombres']) ? $mapa : null;
    }

    /**
     * Texto limpio de una columna de la fila (null si esta vacia o no esta en el archivo).
     *
     * @param  list<mixed>  $celdas
     * @param  array<string,int>  $mapa
     * @return Closure(string): ?string
     */
    private function lectorDeCeldas(array $celdas, array $mapa): Closure
    {
        return fn (string $clave): ?string => HojaDeCalculo::texto(isset($mapa[$clave]) ? ($celdas[$mapa[$clave]] ?? null) : null);
    }

    /** @return array<string,object> "tipoId|numero" => trabajador ya registrado */
    private function trabajadoresRegistrados(): array
    {
        $registrados = [];
        foreach (DB::table('Personal.Trabajador')->get(['TrabajadorId', 'TipoDocumentoIdentidadId', 'TrabajadorNumeroDocumento', 'TrabajadorApellidoPaterno', 'TrabajadorNombreCompleto']) as $t) {
            $registrados[$t->TipoDocumentoIdentidadId.'|'.$t->TrabajadorNumeroDocumento] = $t;
        }

        return $registrados;
    }

    // ------------------------------------------------------------------ validacion de una fila

    /**
     * Columnas del vinculo y del horario: textos de catalogo convertidos en ids. Lo que dependa de la base (vigencia,
     * superposicion, AIRHSP segun la condicion) lo revisa despues VinculoLaboralRequest.
     *
     * @param  Closure(string): ?string  $valor
     * @param  array<string,array<string,int>>  $catalogos
     * @param  list<int>|null  $permitidos  establecimientos del ambito del usuario (null = todos)
     * @return array{0:array{vinculo:array<string,mixed>|null,horario_id:int|null},1:list<array{0:string,1:string}>}
     */
    private function leerVinculo(Closure $valor, array $catalogos, ?array $permitidos): array
    {
        $ninguno = ['vinculo' => null, 'horario_id' => null];
        $hayVinculo = collect(self::COLUMNAS_VINCULO)->contains(fn ($c) => $valor($c) !== null);
        if (! $hayVinculo) {
            return [$ninguno, $valor('horario') === null ? [] : [['Horario', 'Para asignar un horario completa también los datos del vínculo.']]];
        }

        $problemas = [];
        $ids = [];
        foreach (['eess' => 'Establecimiento', 'regimen' => 'Régimen laboral', 'condicion' => 'Condición laboral', 'cargo' => 'Cargo', 'horario' => 'Horario'] as $clave => $columna) {
            $texto = $valor($clave);
            if ($texto === null) {
                $ids[$clave] = null;
                if ($clave !== 'horario') {
                    $problemas[] = [$columna, 'Es obligatorio para registrar el vínculo.'];
                }

                continue;
            }
            $ids[$clave] = $catalogos[$clave][HojaDeCalculo::normalizar($texto)] ?? null;
            if ($ids[$clave] === null) {
                $problemas[] = [$columna, "«{$texto}» no está en la lista de la hoja Instrucciones."];
            }
        }
        if ($ids['eess'] !== null && $permitidos !== null && ! in_array($ids['eess'], $permitidos, true)) {
            $problemas[] = ['Establecimiento', 'No puedes registrar personal en ese establecimiento: está fuera de tu ámbito.'];
        }
        $inicio = $valor('inicio');
        if ($inicio === null) {
            $problemas[] = ['Inicio del vínculo', 'Es obligatorio para registrar el vínculo.'];
        }

        return [$problemas !== [] ? $ninguno : [
            'vinculo' => [
                'EessId' => $ids['eess'], 'RegimenLaboralId' => $ids['regimen'], 'CondicionLaboralId' => $ids['condicion'], 'CargoId' => $ids['cargo'],
                'VinculoLaboralFechaInicio' => HojaDeCalculo::fecha($inicio),
                'VinculoLaboralCodigoAirhsp' => $valor('airhsp'), 'VinculoLaboralNumeroPlaza' => $valor('plaza'),
            ],
            'horario_id' => $ids['horario'],
        ], $problemas];
    }

    /**
     * @param  Closure(string): ?string  $valor
     * @param  list<mixed>  $celdas
     * @param  array<string,int>  $mapa
     * @param  array<string,array<string,int>>  $catalogos
     * @return array{0:array<string,mixed>,1:list<array{0:string,1:string}>}
     */
    private function validarFila(Closure $valor, array $celdas, array $mapa, array $catalogos, $tiposInfo): array
    {
        $problemas = [];
        $datos = [];

        $tipoTexto = $valor('tipo');
        $tipoId = $tipoTexto === null ? null : ($catalogos['tipos'][HojaDeCalculo::normalizar($tipoTexto)] ?? null);
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
        $nacimiento = $nacimiento === null ? null : HojaDeCalculo::fecha($nacimiento);
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
            $profesionId = $catalogos['profesiones'][HojaDeCalculo::normalizar($profesionTexto)] ?? null;
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
                    $mapa[HojaDeCalculo::normalizar((string) $fila->{$c})] = (int) $fila->{$columnaId};
                }
            }
        }

        return $mapa;
    }
}
