<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AsistenciaCalendarioRequest;
use App\Services\CalculoAsistenciaService;
use App\Services\CalendarioAsistenciaPdf;
use App\Services\CalendarioAsistenciaService;
use App\Services\MarcacionManualService;
use App\Support\AccesoUsuario;
use App\Support\AlcanceDeEess;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AsistenciaCalendarioController extends Controller
{
    public function __construct(
        private CalendarioAsistenciaService $servicio,
        private CalculoAsistenciaService $calculo,
        private MarcacionManualService $marcado,
    ) {}

    // GET /api/asistencia-diaria/calendario?eess_id=&vista=dia|semana|mes&fecha=AAAA-MM-DD&buscar=   (o anio=&mes=)
    // Calendario de un establecimiento (trabajadores en filas, dias en columnas). Antes de armarlo se recalcula la
    // asistencia del rango visible hasta hoy, asi la pantalla siempre muestra el resultado vigente sin un boton "Calcular".
    // Cada trabajador trae ademas las marcas del dia que se ve (o de hoy, en semana y mes) y si se le puede marcar.
    public function mostrar(AsistenciaCalendarioRequest $request): JsonResponse
    {
        $calendario = $this->calendario($request, true);

        return response()->json($this->conMarcas($calendario, $request));
    }

    // GET /api/asistencia-diaria/calendario/pdf?...&papel=a4|a3
    // El mismo calendario como documento PDF apaisado.
    public function pdf(AsistenciaCalendarioRequest $request, CalendarioAsistenciaPdf $pdf): Response
    {
        $calendario = $this->calendario($request, false);

        return response($pdf->generar($calendario, $request->input('papel', 'a4')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.CalendarioAsistenciaPdf::nombreDeArchivo($calendario).'"',
        ]);
    }

    // POST /api/asistencia-diaria/marcar   { VinculoLaboralId, MarcacionTipo: ENTRADA|SALIDA, Observacion? }
    // Marca manual con la hora del servidor (no se envia ni se puede elegir la hora). Ver MarcacionManualService.
    public function marcar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'VinculoLaboralId' => ['required', 'integer'],
            'MarcacionTipo' => ['required', Rule::in(['ENTRADA', 'SALIDA'])],
            'Observacion' => ['nullable', 'string', 'max:300'],
        ], [], ['VinculoLaboralId' => 'trabajador', 'MarcacionTipo' => 'tipo de marca']);

        $resultado = $this->marcado->marcar((int) $datos['VinculoLaboralId'], $datos['MarcacionTipo'], $request->user(), $datos['Observacion'] ?? null);

        return response()->json($resultado, 201);
    }

    /** @return array<string,mixed> */
    private function calendario(AsistenciaCalendarioRequest $request, bool $recalcular): array
    {
        $eessId = $request->integer('eess_id');
        AlcanceDeEess::exigir($eessId, $request->user());

        $vista = $request->input('vista', 'mes');
        $fecha = $request->filled('vista')
            ? CarbonImmutable::parse($request->input('fecha'))
            : CarbonImmutable::create($request->integer('anio'), $request->integer('mes'), 1);
        [$desde, $hasta] = CalendarioAsistenciaService::rangoDeVista($vista, $fecha);

        $hoy = HoraLocal::hoy();
        if ($recalcular && $desde <= $hoy) {
            $this->calculo->calcular($desde->toDateString(), min($hasta, $hoy)->toDateString(), null, $eessId);
        }

        return $this->servicio->armarRango($eessId, $desde, $hasta, $vista, $request->input('buscar'), AlcanceDeEess::vinculosPropios($request->user()));
    }

    /**
     * @param  array<string,mixed>  $calendario
     * @return array<string,mixed>
     */
    private function conMarcas(array $calendario, Request $request): array
    {
        // Las marcas del dia que se ve (vista dia) o, en semana y mes, las de hoy si el rango lo incluye.
        $ahora = HoraLocal::ahora();
        $hoy = $ahora->toDateString();
        $periodo = $calendario['periodo'];
        $fecha = $periodo['vista'] === 'dia' ? $periodo['desde'] : ($hoy >= $periodo['desde'] && $hoy <= $periodo['hasta'] ? $hoy : null);
        if ($fecha === null) {
            return $calendario + ['puede_marcar' => false, 'ahora' => $ahora->format('H:i'), 'fecha_marcas' => null];
        }
        $esHoy = $fecha === $hoy;
        $calendario['fecha_marcas'] = $fecha;
        $puedeMarcar = $esHoy && in_array('ASISTENCIA_MARCAR', AccesoUsuario::de($request->user())->permisos, true);
        $marcas = $this->marcado->marcasDelDia(array_column($calendario['trabajadores'], 'vinculo_id'), $fecha);

        $calendario['trabajadores'] = array_map(function (array $t) use ($marcas, $esHoy, $ahora) {
            $abierta = $esHoy ? $this->marcado->entradaAbierta($t['vinculo_id'], $ahora) : null;

            return $t + [
                'marcas' => $marcas->get($t['vinculo_id'], []),
                'entrada_abierta' => $abierta?->format('H:i'),
            ];
        }, $calendario['trabajadores']);
        $calendario['puede_marcar'] = $puedeMarcar;
        $calendario['ahora'] = $ahora->format('H:i');

        return $calendario;
    }
}
