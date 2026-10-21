<?php

namespace App\Services;

use App\Models\Asistencia\Marcacion;
use App\Support\HoraLocal;
use App\Support\PeriodosDeAsistencia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marcacion por reconocimiento facial (RIT, Art. 21: el rostro es la forma normal de marcar) desde el marcador facial,
 * un proyecto aparte que corre en el navegador del kiosco.
 *
 * El kiosco no guarda fotos ni plantillas: calcula el descriptor del rostro (128 numeros) y lo envia. Aqui se compara
 * con las plantillas ROSTRO activas (Biometria.PlantillaBiometrica, registradas con consentimiento vigente) del personal
 * con vinculo vigente en el establecimiento del dispositivo. Si coincide se registra la marca con las mismas reglas que la
 * marcacion manual: hora del servidor, vinculo vigente, periodo abierto; el tipo (entrada o salida) se deduce solo.
 * Cada intento queda en Soporte.LogIntegracion (sin datos biometricos).
 */
class MarcacionFacialService
{
    public const DIMENSION = 128;

    public function __construct(
        private readonly MarcacionManualService $manual,
        private readonly CalculoAsistenciaService $calculo,
    ) {}

    /**
     * @param  object{id:int,codigo:string,eess_id:?int}  $dispositivo
     * @param  list<float>  $descriptor
     * @return array{resultado:string, tipo:string, hora:string, trabajador:string, mensaje:string}
     */
    public function marcar(object $dispositivo, array $descriptor): array
    {
        $ahora = HoraLocal::ahora();
        $hoy = $ahora->toDateString();

        $coincidencia = $this->identificar($descriptor, $dispositivo->eess_id === null ? null : (int) $dispositivo->eess_id, $hoy);
        if ($coincidencia === null) {
            $this->registrarIntento($dispositivo, 'NO_RECONOCIDO', 'Rostro sin coincidencia en el establecimiento');
            throw ValidationException::withMessages(['descriptor' => 'No te reconocí. Acércate a la cámara con buena luz y vuelve a intentarlo; si no estás registrado, acude a RRHH.']);
        }
        $nombre = trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $coincidencia['nombre'])));

        if (PeriodosDeAsistencia::cerradoEn($hoy)) {
            $this->registrarIntento($dispositivo, 'RECHAZADO', "Periodo cerrado (vinculo {$coincidencia['vinculo']})");
            throw ValidationException::withMessages(['descriptor' => 'El período de asistencia de hoy está cerrado: ya no admite marcaciones.']);
        }

        // Quedarse frente a la camara no marca dos veces: dentro de este margen se responde la marca ya registrada.
        $ultima = Marcacion::query()->where('VinculoLaboralId', $coincidencia['vinculo'])->where('MarcacionEsValida', 1)->whereIn('MarcacionTipo', ['ENTRADA', 'SALIDA'])
            ->where('MarcacionFechaHora', '>=', $ahora->subMinutes((int) config('biometria.minutos_entre_marcas'))->toDateTimeString())
            ->where('MarcacionFechaHora', '<=', $ahora->toDateTimeString())
            ->orderByDesc('MarcacionFechaHora')->first(['MarcacionTipo', 'MarcacionFechaHora']);
        if ($ultima !== null) {
            $hora = substr((string) $ultima->MarcacionFechaHora, 11, 5);
            $this->registrarIntento($dispositivo, 'REPETIDA', "{$ultima->MarcacionTipo} ya registrada a las {$hora} (vinculo {$coincidencia['vinculo']})");

            return ['resultado' => 'REPETIDA', 'tipo' => $ultima->MarcacionTipo, 'hora' => $hora, 'trabajador' => $nombre,
                'mensaje' => 'Ya registraste tu '.($ultima->MarcacionTipo === 'ENTRADA' ? 'entrada' : 'salida')." a las {$hora}."];
        }

        $tipo = $this->manual->entradaAbierta($coincidencia['vinculo'], $ahora) === null ? 'ENTRADA' : 'SALIDA';
        Marcacion::create([
            'VinculoLaboralId' => $coincidencia['vinculo'],
            'MetodoMarcacionId' => (int) DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'ROSTRO')->value('MetodoMarcacionId'),
            'DispositivoMarcacionId' => $dispositivo->id,
            'PlantillaBiometricaId' => $coincidencia['plantilla'],
            'MarcacionFechaHora' => $ahora->toDateTimeString(),
            'MarcacionTipo' => $tipo,
            'MarcacionObservacion' => "Reconocimiento facial en {$dispositivo->codigo}.",
            'MarcacionOrigen' => 'DISPOSITIVO',
            'MarcacionEsValida' => true,
        ]);
        // La salida de una guardia nocturna pertenece al dia anterior: se recalculan ayer y hoy.
        $this->calculo->calcular($ahora->subDay()->toDateString(), $hoy, $coincidencia['vinculo']);

        $hora = $ahora->format('H:i');
        $this->registrarIntento($dispositivo, 'OK', "{$tipo} a las {$hora} (vinculo {$coincidencia['vinculo']}, distancia ".round($coincidencia['distancia'], 3).')');

        return ['resultado' => 'REGISTRADA', 'tipo' => $tipo, 'hora' => $hora, 'trabajador' => $nombre,
            'mensaje' => ($tipo === 'ENTRADA' ? 'Entrada' : 'Salida')." registrada a las {$hora}."];
    }

    /**
     * La plantilla mas parecida bajo el umbral, entre el personal con vinculo vigente hoy en el establecimiento (null =
     * toda la Red) y con consentimiento biometrico vigente (el ultimo evento del trabajador, aceptado).
     *
     * @param  list<float>  $descriptor
     * @return array{plantilla:int, vinculo:int, nombre:string, distancia:float}|null
     */
    public function identificar(array $descriptor, ?int $eessId, string $hoy): ?array
    {
        $consentimientoVigente = DB::table('Biometria.ConsentimientoBiometrico')->selectRaw('MAX(ConsentimientoBiometricoId)')->groupBy('TrabajadorId');

        $candidatas = DB::table('Biometria.PlantillaBiometrica as p')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'p.TrabajadorId')
            ->join('Personal.VinculoLaboral as vl', 'vl.TrabajadorId', '=', 'p.TrabajadorId')
            ->join('Biometria.ConsentimientoBiometrico as c', 'c.TrabajadorId', '=', 'p.TrabajadorId')
            ->whereIn('c.ConsentimientoBiometricoId', $consentimientoVigente)->where('c.ConsentimientoBiometricoAceptado', 1)
            ->where('p.PlantillaBiometricaTipo', 'ROSTRO')->where('p.PlantillaBiometricaEstado', 1)->whereNotNull('p.PlantillaBiometricaReferencia')
            ->where('t.TrabajadorEstado', 1)
            ->where('vl.VinculoLaboralEstado', 1)->whereDate('vl.VinculoLaboralFechaInicio', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('vl.VinculoLaboralFechaFin')->orWhereDate('vl.VinculoLaboralFechaFin', '>=', $hoy))
            ->when($eessId !== null, fn ($q) => $q->where('vl.EessId', $eessId))
            ->orderBy('vl.VinculoLaboralId')
            ->get(['p.PlantillaBiometricaId as plantilla', 'vl.VinculoLaboralId as vinculo', 't.TrabajadorNombreCompleto as nombre',
                DB::raw('CONVERT(VARCHAR(MAX), p.PlantillaBiometricaReferencia, 2) as referencia')]);

        $mejor = null;
        foreach ($candidatas as $c) {
            $referencia = self::decodificar((string) $c->referencia);
            if ($referencia === null) {
                continue;
            }
            $distancia = self::distancia($descriptor, $referencia);
            if ($distancia <= (float) config('biometria.umbral_facial') && ($mejor === null || $distancia < $mejor['distancia'])) {
                $mejor = ['plantilla' => (int) $c->plantilla, 'vinculo' => (int) $c->vinculo, 'nombre' => (string) $c->nombre, 'distancia' => $distancia];
            }
        }

        return $mejor;
    }

    /** Plantilla guardada (hex de 128 float32 little-endian, como Float32Array del navegador) -> lista de numeros. */
    public static function decodificar(string $hex): ?array
    {
        $bytes = $hex === '' ? false : hex2bin($hex);
        if ($bytes === false || strlen($bytes) !== self::DIMENSION * 4) {
            return null;
        }

        return array_values(unpack('g'.self::DIMENSION, $bytes));
    }

    /** Lista de numeros -> base64 de float32 little-endian (lo que envia el kiosco al registrar un rostro). */
    public static function codificar(array $descriptor): string
    {
        return base64_encode(pack('g*', ...array_map('floatval', $descriptor)));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function distancia(array $a, array $b): float
    {
        $suma = 0.0;
        foreach ($a as $i => $valor) {
            $suma += ($valor - $b[$i]) ** 2;
        }

        return sqrt($suma);
    }

    private function registrarIntento(object $dispositivo, string $resultado, string $resumen): void
    {
        DB::table('Soporte.LogIntegracion')->insert([
            'LogIntegracionSistemaExterno' => 'MARCADOR_FACIAL',
            'LogIntegracionOperacion' => 'MARCACION_FACIAL',
            'LogIntegracionDireccion' => 'ENTRADA',
            'LogIntegracionPayloadResumen' => mb_substr("{$dispositivo->codigo}: {$resumen}", 0, 1000),
            'LogIntegracionResultado' => $resultado,
            'LogIntegracionFechaHora' => HoraLocal::ahora()->toDateTimeString(),
        ]);
    }
}
