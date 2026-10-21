<?php

namespace App\Services;

use App\Models\Asistencia\CargaAsistenciaManual;
use App\Models\Asistencia\Marcacion;
use App\Models\Seguridad\Usuario;
use App\Support\HojaDeCalculo;
use App\Support\HoraLocal;
use App\Support\PeriodosDeAsistencia;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Hojas de control de asistencia (papel) pasadas al sistema. El RIT dispone que los establecimientos que no cuentan con el
 * Registro Automatico de Control de Asistencia (reloj digital de reconocimiento facial) "continuaran utilizando las Hojas
 * para el control de Asistencia de los trabajadores hasta que se implemente el sistema digital". Esta carga digitaliza esas
 * hojas, con estas garantias:
 *
 *   - Solo para un establecimiento SIN reloj facial activo: donde lo hay, la asistencia se marca en el reloj.
 *   - Cada carga exige la hoja firmada escaneada como documento de sustento y queda registrada en
 *     Asistencia.CargaAsistenciaManual; cada marca guarda su carga (anular la carga invalida sus marcas).
 *   - Solo personal del establecimiento con vinculo vigente ese dia, dias del mes elegido que ya pasaron y periodos de
 *     asistencia abiertos. No se duplica una marca que el trabajador ya tiene ese dia.
 *   - Una salida anterior o igual a la entrada es del dia siguiente (guardia nocturna).
 *   - Al confirmar se recalcula la asistencia del mes del establecimiento.
 */
class ImportacionHojaAsistenciaService
{
    public const ORIGEN = 'HOJA_ASISTENCIA';

    /** Cabeceras de la plantilla: clave interna => titulo en el Excel. */
    public const COLUMNAS = [
        'dni' => 'DNI', 'trabajador' => 'Trabajador', 'fecha' => 'Fecha', 'turno' => 'Turno programado',
        'entrada' => 'Hora de entrada', 'salida' => 'Hora de salida', 'observacion' => 'Observación',
    ];

    /** @var array<int,object|null> fechas y estado de cada vinculo consultado */
    private array $vinculos = [];

    public function __construct(
        private readonly CalendarioAsistenciaService $base,
        private readonly CalculoAsistenciaService $calculo,
    ) {}

    /** Motivo por el que el establecimiento no lleva hojas de asistencia (null = las lleva). */
    public function motivoSinHojas(int $eessId): ?string
    {
        $reloj = DB::table('Biometria.DispositivoMarcacion')->where('EessId', $eessId)->where('DispositivoMarcacionEstado', 1)
            ->where('DispositivoMarcacionTipo', 'like', '%facial%')->value('DispositivoMarcacionNombre');

        return $reloj === null ? null
            : "El establecimiento cuenta con reloj de reconocimiento facial ({$reloj}): la asistencia se registra en el reloj. Las hojas de asistencia solo corresponden a los establecimientos sin registro automático (RIT, disposiciones complementarias).";
    }

    /** Plantilla del mes: una fila por trabajador y dia (hasta hoy), con su turno programado como referencia. */
    public function plantilla(int $eessId, int $anio, int $mes): string
    {
        [$primero, $ultimo] = $this->rango($anio, $mes);
        $hasta = min($ultimo, HoraLocal::hoy());
        $eess = DB::table('Organizacion.EstablecimientoSalud')->where('EessId', $eessId)->value('EessNombre');
        $trabajadores = $this->base->trabajadores($eessId, $primero, $ultimo, null);
        $turnos = $this->turnosProgramados($trabajadores->pluck('vinculo_id')->all(), $primero, $ultimo);

        $ruta = tempnam(sys_get_temp_dir(), 'hja').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($ruta);
        $escritor->getCurrentSheet()->setName('Hoja de asistencia');
        $cabecera = (new Style)->withFontBold(true)->withBackgroundColor('D9EEF0');
        $llenar = (new Style)->withFontBold(true)->withBackgroundColor('FCE9C8');
        $texto = (new Style)->withFormat('@');

        $escritor->addRow(Row::fromValuesWithStyle(["Hoja de control de asistencia · {$eess} · ".CalendarioAsistenciaService::MESES[$mes]." {$anio}"], (new Style)->withFontBold(true)->withFontSize(13)));
        $escritor->addRow(Row::fromValues(['Copia las horas de la hoja firmada (HH:MM, 24 horas). Deja vacío lo que no se marcó. No cambies DNI ni Fecha.']));
        $escritor->addRow(Row::fromValuesWithStyles(array_values(self::COLUMNAS), [$cabecera, $cabecera, $cabecera, $cabecera, $llenar, $llenar, $llenar]));
        for ($dia = $primero; $dia <= $hasta; $dia = $dia->addDay()) {
            foreach ($trabajadores as $t) {
                if (! $this->vigente($t, $dia->toDateString())) {
                    continue;
                }
                $escritor->addRow(Row::fromValuesWithStyles(
                    [$t['dni'], $t['nombre'], $dia->format('d/m/Y'), $turnos[$t['vinculo_id'].'|'.$dia->toDateString()] ?? '', '', '', ''],
                    [$texto, null, $texto, null, $texto, $texto, null],
                ));
            }
        }
        $escritor->close();

        return $ruta;
    }

    /**
     * Lee la hoja y registra las marcas: con $confirmar = false (o con errores sin $omitir) todo se deshace al terminar.
     *
     * @return array{filas:int,marcas:int,registradas:int,filas_con_error:int,carga_id:int|null,errores:list<array{fila:int,campo:string,mensaje:string}>}
     */
    public function importar(int $eessId, int $anio, int $mes, string $ruta, string $extension, string $nombreArchivo, int $documentoId, Usuario $usuario, bool $confirmar, bool $omitir = false): array
    {
        if ($motivo = $this->motivoSinHojas($eessId)) {
            throw new DomainException($motivo);
        }
        [$primero, $ultimo] = $this->rango($anio, $mes);
        if ($primero > HoraLocal::hoy()) {
            throw new DomainException('Ese mes aún no empieza: solo se cargan hojas de días que ya pasaron.');
        }
        [$filas, $marcas, $errores] = $this->leer($eessId, $primero, $ultimo, $ruta, $extension);
        $filasConError = count(array_unique(array_column($errores, 'fila')));
        $guardar = $confirmar && ($errores === [] || $omitir) && $marcas !== [];

        $cargaId = null;
        DB::beginTransaction();
        try {
            $carga = CargaAsistenciaManual::create([
                'UsuarioId' => $usuario->UsuarioId, 'EessId' => $eessId, 'DocumentoSustentoId' => $documentoId,
                'CargaAsistenciaManualFecha' => HoraLocal::ahora()->toDateTimeString(),
                'CargaAsistenciaManualNombreArchivo' => mb_substr($nombreArchivo, 0, 255),
                'CargaAsistenciaManualRegistros' => count($marcas),
                'CargaAsistenciaManualObservacion' => 'Hoja de asistencia de '.mb_strtolower(CalendarioAsistenciaService::MESES[$mes])." {$anio}.",
                'CargaAsistenciaManualEstado' => 'PROCESADO',
            ]);
            $metodo = (int) DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'MANUAL')->value('MetodoMarcacionId');
            foreach ($marcas as $m) {
                Marcacion::create([
                    'VinculoLaboralId' => $m['vinculo_id'], 'MetodoMarcacionId' => $metodo, 'CargaAsistenciaManualId' => $carga->CargaAsistenciaManualId,
                    'MarcacionFechaHora' => $m['fecha_hora'], 'MarcacionTipo' => $m['tipo'], 'MarcacionOrigen' => self::ORIGEN, 'MarcacionEsValida' => true,
                    'MarcacionObservacion' => mb_substr(trim("Hoja de asistencia (fila {$m['fila']}). ".($m['observacion'] ?? '')), 0, 500),
                ]);
            }
            $cargaId = (int) $carga->CargaAsistenciaManualId;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        $guardar ? DB::commit() : DB::rollBack();

        if ($guardar) {
            // Una salida de guardia del ultimo dia cae en el mes siguiente: se recalcula hasta el dia posterior.
            $this->calculo->calcular($primero->toDateString(), min($ultimo->addDay(), HoraLocal::hoy())->toDateString(), null, $eessId);
        }

        return [
            'filas' => $filas, 'marcas' => count($marcas), 'registradas' => $guardar ? count($marcas) : 0, 'filas_con_error' => $filasConError,
            'carga_id' => $guardar ? $cargaId : null, 'errores' => $errores,
        ];
    }

    /**
     * Marcas validas del archivo y errores por fila.
     *
     * @return array{0:int,1:list<array{fila:int,vinculo_id:int,tipo:string,fecha_hora:string,observacion:?string}>,2:list<array{fila:int,campo:string,mensaje:string}>}
     */
    private function leer(int $eessId, CarbonImmutable $primero, CarbonImmutable $ultimo, string $ruta, string $extension): array
    {
        $porDni = $this->base->trabajadores($eessId, $primero, $ultimo, null)->groupBy('dni');
        $existentes = $this->marcasExistentes($porDni->flatten(1)->pluck('vinculo_id')->all(), $primero, $ultimo->addDays(2));
        $ahora = HoraLocal::ahora();
        $mesTexto = mb_strtolower(CalendarioAsistenciaService::MESES[$primero->month]).' '.$primero->year;

        $mapa = null;
        $filas = 0;
        $marcas = [];
        $errores = [];
        $vistos = [];
        foreach (HojaDeCalculo::filas($ruta, $extension) as $numero => $celdas) {
            if ($mapa === null) {
                $mapa = $this->mapearCabeceras($celdas);

                continue;
            }
            $valor = fn (string $clave) => isset($mapa[$clave]) ? HojaDeCalculo::texto($celdas[$mapa[$clave]] ?? null) : null;
            $entrada = $valor('entrada');
            $salida = $valor('salida');
            if ($entrada === null && $salida === null) {
                continue; // dia sin marcas en la hoja: el calculo lo evalua como corresponda
            }
            $filas++;
            $problemas = [];

            $dni = HojaDeCalculo::dni($celdas[$mapa['dni']] ?? null);
            $fecha = $valor('fecha') === null ? null : substr(HojaDeCalculo::fecha($valor('fecha')), 0, 10);
            $trabajador = null;
            if ($fecha === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || ! checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
                $problemas[] = ['Fecha', 'Fecha no válida (usa DD/MM/AAAA).'];
            } elseif ($fecha < $primero->toDateString() || $fecha > $ultimo->toDateString()) {
                $problemas[] = ['Fecha', "No es un día de {$mesTexto}: la carga es de un solo mes."];
            } elseif ($fecha > $ahora->toDateString()) {
                $problemas[] = ['Fecha', 'Es un día que aún no llega.'];
            } elseif (PeriodosDeAsistencia::cerradoEn($fecha)) {
                $problemas[] = ['Fecha', 'El período de asistencia de ese día ya está cerrado.'];
            } elseif ($dni === null || ($candidatos = $porDni->get($dni)) === null) {
                $problemas[] = ['DNI', "No figura en el personal del establecimiento en {$mesTexto}."];
            } else {
                $trabajador = $candidatos->first(fn ($t) => $this->vigente($t, $fecha));
                if ($trabajador === null) {
                    $problemas[] = ['DNI', 'Su vínculo laboral no está vigente ese día.'];
                } elseif (isset($vistos[$dni.'|'.$fecha])) {
                    $problemas[] = ['Fecha', "El mismo trabajador y día ya está en la fila {$vistos[$dni.'|'.$fecha]}."];
                }
            }

            $horaEntrada = $entrada === null ? null : $this->hora($entrada);
            $horaSalida = $salida === null ? null : $this->hora($salida);
            if ($entrada !== null && $horaEntrada === null) {
                $problemas[] = ['Hora de entrada', "«{$entrada}» no es una hora (usa HH:MM, por ejemplo 07:35)."];
            }
            if ($salida !== null && $horaSalida === null) {
                $problemas[] = ['Hora de salida', "«{$salida}» no es una hora (usa HH:MM, por ejemplo 15:40)."];
            }

            $nuevas = [];
            if ($problemas === []) {
                $vistos[$dni.'|'.$fecha] = $numero;
                foreach (['ENTRADA' => $horaEntrada, 'SALIDA' => $horaSalida] as $tipo => $hora) {
                    if ($hora === null) {
                        continue;
                    }
                    $momento = CarbonImmutable::parse("{$fecha} {$hora}");
                    if ($tipo === 'SALIDA' && $horaEntrada !== null && $hora <= $horaEntrada) {
                        $momento = $momento->addDay(); // guardia nocturna: sale al dia siguiente
                    }
                    $columna = $tipo === 'ENTRADA' ? 'Hora de entrada' : 'Hora de salida';
                    if ($momento > $ahora) {
                        $problemas[] = [$columna, 'Esa hora aún no llega.'];

                        continue;
                    }
                    if ($ya = $existentes[$trabajador['vinculo_id'].'|'.$tipo.'|'.$momento->toDateString()] ?? null) {
                        $problemas[] = [$columna, 'Ya tiene una '.mb_strtolower($tipo)." registrada ese día ({$ya}): no se duplica."];

                        continue;
                    }
                    $nuevas[] = ['fila' => $numero, 'vinculo_id' => $trabajador['vinculo_id'], 'tipo' => $tipo, 'fecha_hora' => $momento->toDateTimeString(), 'observacion' => $valor('observacion')];
                }
            }
            if ($problemas === []) {
                array_push($marcas, ...$nuevas);
            }
            foreach ($problemas as [$campo, $mensaje]) {
                $errores[] = ['fila' => $numero, 'campo' => $campo, 'mensaje' => $mensaje];
            }
        }
        if ($mapa === null) {
            throw new DomainException('No se encontraron las columnas de la plantilla (DNI, Fecha, Hora de entrada y Hora de salida). Descarga la plantilla del mes y úsala.');
        }

        return [$filas, $marcas, $errores];
    }

    /**
     * @param  list<mixed>  $celdas
     * @return array<string,int>|null
     */
    private function mapearCabeceras(array $celdas): ?array
    {
        $mapa = [];
        foreach ($celdas as $i => $valor) {
            $texto = HojaDeCalculo::normalizar((string) HojaDeCalculo::texto($valor));
            foreach (self::COLUMNAS as $clave => $titulo) {
                if ($texto === HojaDeCalculo::normalizar($titulo)) {
                    $mapa[$clave] = $i;
                }
            }
        }

        return isset($mapa['dni'], $mapa['fecha'], $mapa['entrada'], $mapa['salida']) ? $mapa : null;
    }

    /** "7:35", "07:35", "7.35", "07h35" o una hora de Excel -> "07:35:00" (null si no es una hora valida). */
    private function hora(string $texto): ?string
    {
        if (preg_match('/(?:^|\s)(\d{1,2})[:.hH](\d{2})(?::\d{2})?$/', $texto, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d:00', $m[1], $m[2]);
        }

        return null;
    }

    /** @param  array<string,mixed>  $trabajador  fila de CalendarioAsistenciaService::trabajadores() */
    private function vigente(array $trabajador, string $fecha): bool
    {
        $vinculo = $this->vinculos[$trabajador['vinculo_id']] ??= DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $trabajador['vinculo_id'])
            ->first(['VinculoLaboralFechaInicio', 'VinculoLaboralFechaFin', 'VinculoLaboralEstado']);

        return $vinculo !== null && $vinculo->VinculoLaboralEstado
            && substr((string) $vinculo->VinculoLaboralFechaInicio, 0, 10) <= $fecha
            && ($vinculo->VinculoLaboralFechaFin === null || substr((string) $vinculo->VinculoLaboralFechaFin, 0, 10) >= $fecha);
    }

    /**
     * Marcas validas que ya existen: "vinculo|TIPO|fecha" => hora.
     *
     * @param  list<int>  $vinculos
     * @return array<string,string>
     */
    private function marcasExistentes(array $vinculos, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $existentes = [];
        foreach (array_chunk($vinculos, 1000) as $lote) {
            foreach (DB::table('Asistencia.Marcacion')->whereIn('VinculoLaboralId', $lote)->where('MarcacionEsValida', 1)->whereIn('MarcacionTipo', ['ENTRADA', 'SALIDA'])
                ->where('MarcacionFechaHora', '>=', $desde->toDateTimeString())->where('MarcacionFechaHora', '<', $hasta->toDateTimeString())
                ->get(['VinculoLaboralId', 'MarcacionTipo', 'MarcacionFechaHora']) as $m) {
                $existentes[$m->VinculoLaboralId.'|'.$m->MarcacionTipo.'|'.substr((string) $m->MarcacionFechaHora, 0, 10)] = substr((string) $m->MarcacionFechaHora, 11, 5);
            }
        }

        return $existentes;
    }

    /**
     * Codigos de los turnos programados por "vinculo|fecha" (solo como referencia en la plantilla).
     *
     * @param  list<int>  $vinculos
     * @return array<string,string>
     */
    private function turnosProgramados(array $vinculos, CarbonImmutable $primero, CarbonImmutable $ultimo): array
    {
        if ($vinculos === []) {
            return [];
        }

        return DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'tp.TurnoId')
            ->whereIn('pt.VinculoLaboralId', $vinculos)->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')
            ->whereDate('tp.TurnoProgramadoFecha', '>=', $primero->toDateString())->whereDate('tp.TurnoProgramadoFecha', '<=', $ultimo->toDateString())
            ->orderBy('t.TurnoHoraEntrada')
            ->get(['pt.VinculoLaboralId', 'tp.TurnoProgramadoFecha', 't.TurnoCodigo'])
            ->groupBy(fn ($r) => $r->VinculoLaboralId.'|'.substr((string) $r->TurnoProgramadoFecha, 0, 10))
            ->map(fn ($g) => $g->pluck('TurnoCodigo')->implode('+'))
            ->all();
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function rango(int $anio, int $mes): array
    {
        $primero = CarbonImmutable::create($anio, $mes, 1)->startOfDay();

        return [$primero, $primero->endOfMonth()->startOfDay()];
    }
}
