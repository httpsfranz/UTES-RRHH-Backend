<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AlcanceDeEess;
use App\Support\HoraLocal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administracion de biometria en UNA pantalla (RIT, Art. 21): por cada trabajador, como marca (reconocimiento facial o
 * registro manual autorizado por RRHH), si tiene su plantilla facial y si dio su consentimiento (y cuando). Las altas
 * siguen yendo a sus tablas (autorizaciones-metodo, plantillas-biometricas, consentimientos-biometricos); esto solo las
 * resume juntas.
 */
class BiometriaController extends Controller
{
    // GET /api/autorizaciones-metodo/resumen?eess_id=&buscar=
    public function resumen(Request $request): JsonResponse
    {
        $hoy = HoraLocal::hoy()->toDateString();
        $buscar = $request->string('buscar')->toString();
        $manual = DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'MANUAL')->value('MetodoMarcacionId');

        $trabajadores = DB::table('Personal.VinculoLaboral as v')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
            ->where('v.VinculoLaboralEstado', 1)->where('t.TrabajadorEstado', 1)
            ->where(fn ($q) => $q->whereNull('v.VinculoLaboralFechaFin')->orWhereDate('v.VinculoLaboralFechaFin', '>=', $hoy))
            ->when(AlcanceDeEess::restringe($request), fn ($q) => $q->whereIn('v.EessId', AlcanceDeEess::ids($request)))
            ->when($buscar !== '', fn ($q) => $q->where(fn ($s) => $s->where('t.TrabajadorNombreCompleto', 'like', "%{$buscar}%")->orWhere('t.TrabajadorNumeroDocumento', 'like', "%{$buscar}%")))
            ->orderBy('t.TrabajadorNombreCompleto')->limit(500)
            ->get(['t.TrabajadorId', 't.TrabajadorNumeroDocumento', 't.TrabajadorNombreCompleto', 'e.EessNombre'])
            ->unique('TrabajadorId');
        $ids = $trabajadores->pluck('TrabajadorId')->all() ?: [0];

        // Ultimo evento de consentimiento de cada trabajador (el historial es inmutable: el vigente es el mas reciente).
        $consentimientos = DB::table('Biometria.ConsentimientoBiometrico')->whereIn('TrabajadorId', $ids)->orderBy('ConsentimientoBiometricoFecha')->orderBy('ConsentimientoBiometricoId')
            ->get(['TrabajadorId', 'ConsentimientoBiometricoAceptado', 'ConsentimientoBiometricoFecha'])->keyBy('TrabajadorId');
        $plantillas = DB::table('Biometria.PlantillaBiometrica')->whereIn('TrabajadorId', $ids)->where('PlantillaBiometricaTipo', 'ROSTRO')->where('PlantillaBiometricaEstado', 1)
            ->get(['PlantillaBiometricaId', 'TrabajadorId', 'PlantillaBiometricaFechaRegistro', DB::raw('CASE WHEN PlantillaBiometricaReferencia IS NULL THEN 0 ELSE 1 END AS con_referencia')])->keyBy('TrabajadorId');
        $autorizaciones = DB::table('Biometria.AutorizacionMetodo')->whereIn('TrabajadorId', $ids)->where('MetodoMarcacionId', $manual)
            ->get(['AutorizacionMetodoId', 'TrabajadorId', 'AutorizacionMetodoFechaInicio', 'AutorizacionMetodoFechaFin', 'AutorizacionMetodoEstado'])->keyBy('TrabajadorId');

        return response()->json(['data' => $trabajadores->values()->map(function ($t) use ($consentimientos, $plantillas, $autorizaciones, $hoy) {
            $c = $consentimientos->get($t->TrabajadorId);
            $p = $plantillas->get($t->TrabajadorId);
            $a = $autorizaciones->get($t->TrabajadorId);
            $manualVigente = $a && $a->AutorizacionMetodoEstado && substr((string) $a->AutorizacionMetodoFechaInicio, 0, 10) <= $hoy
                && ($a->AutorizacionMetodoFechaFin === null || substr((string) $a->AutorizacionMetodoFechaFin, 0, 10) >= $hoy);

            return [
                'trabajador_id' => (int) $t->TrabajadorId,
                'dni' => $t->TrabajadorNumeroDocumento,
                'nombre' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $t->TrabajadorNombreCompleto))),
                'eess' => $t->EessNombre,
                'consiente' => $c ? (bool) $c->ConsentimientoBiometricoAceptado : null,
                'fecha_consentimiento' => $c ? substr((string) $c->ConsentimientoBiometricoFecha, 0, 10) : null,
                'plantilla_facial' => $p ? ['id' => (int) $p->PlantillaBiometricaId, 'con_referencia' => (bool) $p->con_referencia, 'fecha' => substr((string) $p->PlantillaBiometricaFechaRegistro, 0, 10)] : null,
                'autorizacion_manual' => $a ? ['id' => (int) $a->AutorizacionMetodoId, 'desde' => substr((string) $a->AutorizacionMetodoFechaInicio, 0, 10),
                    'hasta' => $a->AutorizacionMetodoFechaFin ? substr((string) $a->AutorizacionMetodoFechaFin, 0, 10) : null, 'vigente' => $manualVigente] : null,
                // Como marca hoy: con autorizacion vigente, registro manual; si no, reconocimiento facial (Art. 21).
                'metodo' => $manualVigente ? 'MANUAL' : 'FACIAL',
            ];
        })->all()]);
    }
}
