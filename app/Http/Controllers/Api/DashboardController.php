<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seguridad\Usuario;
use App\Support\AccesoUsuario;
use App\Support\HoraLocal;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // GET /api/dashboard
    // Resumen de la pantalla de inicio, limitado al alcance de quien consulta (su EESS, su microred o toda la Red; el
    // trabajador solo ve lo suyo). Son conteos puntuales: cada cifra es una consulta agregada, sin traer filas.
    public function resumen(Request $request): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $acceso = AccesoUsuario::de($usuario);
        $hoy = HoraLocal::hoy();
        $mes = [$hoy->startOfMonth()->toDateString(), $hoy->toDateString()];

        $delAlcance = function (Builder $q, string $columnaVinculo) use ($acceso): Builder {
            $q->join('Personal.VinculoLaboral as vl', 'vl.VinculoLaboralId', '=', $columnaVinculo);
            if ($acceso->propio) {
                return $q->whereIn('vl.VinculoLaboralId', $acceso->vinculoIds);
            }

            return $acceso->eessIds === null ? $q : $q->whereIn('vl.EessId', $acceso->eessIds);
        };

        $pendientes = [
            'papeletas' => $delAlcance(DB::table('Solicitudes.Papeleta as s')->where('s.PapeletaEstado', 'PENDIENTE'), 's.VinculoLaboralId')->count(),
            'licencias' => $delAlcance(DB::table('Solicitudes.Licencia as s')->where('s.LicenciaEstado', 'PENDIENTE'), 's.VinculoLaboralId')->count(),
            'descansos_medicos' => $delAlcance(DB::table('Solicitudes.DescansoMedico as s')->where('s.DescansoMedicoEstado', 'PENDIENTE'), 's.VinculoLaboralId')->count(),
            'justificaciones' => $delAlcance(DB::table('Asistencia.JustificacionFalta as s')->where('s.JustificacionFaltaEstado', 'PENDIENTE'), 's.VinculoLaboralId')->count(),
            'cambios_turno' => $delAlcance(DB::table('Programacion.CambioTurno as s')->where('s.CambioTurnoEstado', 'PENDIENTE'), 's.VinculoLaboralSolicitanteId')->count(),
            'vacaciones' => $delAlcance(DB::table('Vacaciones.GoceVacacional as s')
                ->join('Vacaciones.RolVacacional as r', 'r.RolVacacionalId', '=', 's.RolVacacionalId')
                ->join('Vacaciones.PeriodoVacacional as p', 'p.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
                ->where('s.GoceVacacionalEstado', 'PENDIENTE'), 'p.VinculoLaboralId')->count(),
        ];

        $asistenciaDelMes = $delAlcance(DB::table('Asistencia.AsistenciaDiaria as ad')
            ->join('Asistencia.EstadoAsistencia as ea', 'ea.EstadoAsistenciaId', '=', 'ad.EstadoAsistenciaId')
            ->whereDate('ad.AsistenciaDiariaFecha', '>=', $mes[0])->whereDate('ad.AsistenciaDiariaFecha', '<=', $mes[1]), 'ad.VinculoLaboralId')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 0 AND ea.EstadoAsistenciaEsLaborable = 1 THEN 1 ELSE 0 END) AS trabajados')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 1 AND ea.EstadoAsistenciaEsDescontable = 1 THEN 1 ELSE 0 END) AS faltas')
            ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 1 AND ea.EstadoAsistenciaEsDescontable = 0 THEN 1 ELSE 0 END) AS justificadas')
            ->selectRaw('SUM(CASE WHEN ad.AsistenciaDiariaMinutosTardanza > 0 THEN 1 ELSE 0 END) AS tardanzas')
            ->first();

        return response()->json([
            'fecha' => $hoy->toDateString(),
            'alcance' => $acceso->propio ? 'propio' : ($acceso->eessIds === null ? 'red' : 'establecimientos'),
            'trabajadores_activos' => $delAlcance(DB::table('Personal.VinculoLaboral as v0')->where('v0.VinculoLaboralEstado', 1)
                ->where(fn ($q) => $q->whereNull('v0.VinculoLaboralFechaFin')->orWhereDate('v0.VinculoLaboralFechaFin', '>=', $hoy->toDateString())), 'v0.VinculoLaboralId')->count(),
            'marcaciones_hoy' => $delAlcance(DB::table('Asistencia.Marcacion as m')->whereDate('m.MarcacionFechaHora', $hoy->toDateString()), 'm.VinculoLaboralId')->count(),
            'solicitudes_pendientes' => array_sum($pendientes),
            'pendientes_por_tipo' => $pendientes,
            'asistencia_mes' => [
                'desde' => $mes[0],
                'hasta' => $mes[1],
                'trabajados' => (int) ($asistenciaDelMes->trabajados ?? 0),
                'faltas' => (int) ($asistenciaDelMes->faltas ?? 0),
                'justificadas' => (int) ($asistenciaDelMes->justificadas ?? 0),
                'tardanzas' => (int) ($asistenciaDelMes->tardanzas ?? 0),
            ],
            'notificaciones_sin_leer' => DB::table('Soporte.Notificacion')->where('UsuarioId', $usuario->UsuarioId)->where('NotificacionLeida', 0)->count(),
            'notificaciones' => DB::table('Soporte.Notificacion')->where('UsuarioId', $usuario->UsuarioId)->orderByDesc('NotificacionFecha')->limit(5)
                ->get(['NotificacionId', 'NotificacionTipo', 'NotificacionTitulo', 'NotificacionMensaje', 'NotificacionEnlace', 'NotificacionFecha', 'NotificacionLeida'])
                ->map(fn ($n) => ['id' => (int) $n->NotificacionId, 'tipo' => $n->NotificacionTipo, 'titulo' => $n->NotificacionTitulo, 'mensaje' => $n->NotificacionMensaje, 'enlace' => $n->NotificacionEnlace, 'fecha' => $n->NotificacionFecha, 'leida' => (bool) $n->NotificacionLeida])->all(),
        ]);
    }
}
