<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consolidacion\PeriodoAsistencia;
use App\Models\Personal\VinculoLaboral;
use App\Services\AnexosOficialesService;
use App\Services\SolicitudService;
use App\Support\AlcanceDeEess;
use App\Support\HoraLocal;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Documentos oficiales en PDF: Anexos 012 a 017, planilla de descuentos, constancia y certificado de trabajo. */
class ReporteOficialController extends Controller
{
    // GET /api/reportes/anexos/{anexo}?periodo_asistencia_id=&eess_id=   (Anexo 017: ?fecha=AAAA-MM-DD&eess_id=)
    public function anexo(Request $request, string $anexo, AnexosOficialesService $servicio): Response
    {
        $eessId = $request->validate(['eess_id' => ['required', 'integer']])['eess_id'];
        AlcanceDeEess::exigir((int) $eessId, $request->user());

        if ($anexo === '017') {
            $fecha = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']])['fecha'] ?? HoraLocal::hoy()->toDateString();

            return $this->pdf($servicio->registroManualPdf((int) $eessId, $fecha), "Anexo_017_{$fecha}.pdf");
        }
        abort_unless(isset(AnexosOficialesService::ANEXOS[$anexo]), 404, 'Ese anexo no existe.');
        $periodo = PeriodoAsistencia::query()->findOrFail($request->validate(['periodo_asistencia_id' => ['required', 'integer']])['periodo_asistencia_id']);

        return $this->pdf($servicio->consolidadoPdf($anexo, $periodo, (int) $eessId), "Anexo_{$anexo}_{$periodo->PeriodoAsistenciaAnio}-{$periodo->PeriodoAsistenciaMes}.pdf");
    }

    // GET /api/reportes/planilla-descuentos?periodo_asistencia_id=&eess_id=
    public function planilla(Request $request, AnexosOficialesService $servicio): Response
    {
        $datos = $request->validate(['periodo_asistencia_id' => ['required', 'integer'], 'eess_id' => ['nullable', 'integer']]);
        $periodo = PeriodoAsistencia::query()->findOrFail($datos['periodo_asistencia_id']);
        $eess = isset($datos['eess_id']) ? (int) $datos['eess_id'] : null;
        if ($eess !== null) {
            AlcanceDeEess::exigir($eess, $request->user());
        } elseif (AlcanceDeEess::permitidos($request->user()) !== null) {
            throw new DomainException('Elige el establecimiento.');
        }

        return $this->pdf($servicio->planillaDeDescuentosPdf($periodo, $eess), "Planilla_descuentos_{$periodo->PeriodoAsistenciaAnio}-{$periodo->PeriodoAsistenciaMes}.pdf");
    }

    // GET /api/vinculos-laborales/{vinculo}/documento?tipo=constancia|certificado
    public function documentoDeTrabajo(Request $request, VinculoLaboral $vinculo, AnexosOficialesService $servicio): Response
    {
        $certificado = $request->query('tipo') === 'certificado';
        if ($certificado) {
            if ($vinculo->VinculoLaboralFechaFin === null) {
                throw new DomainException('El certificado se entrega al culminar la relación laboral; para un vínculo vigente emite la constancia (RIT, Art. 83.7 y 131).');
            }
            if (! SolicitudService::tieneEntregaDeCargo((int) $vinculo->VinculoLaboralId, 'CESE', $vinculo->VinculoLaboralFechaFin->copy()->addDays(30)->toDateString())) {
                throw new DomainException('El certificado de trabajo exige la entrega de cargo previa (RIT, Art. 131).');
            }
        }

        return $this->pdf($servicio->documentoDeTrabajoPdf((int) $vinculo->VinculoLaboralId, $certificado), ($certificado ? 'Certificado' : 'Constancia')."_trabajo_{$vinculo->VinculoLaboralId}.pdf");
    }

    // GET /api/reconocimientos/candidatos?anio=&semestre=1|2   personal sin faltas ni tardanzas en el semestre (RIT, Art. 96)
    public function candidatos(Request $request)
    {
        $datos = $request->validate(['anio' => ['required', 'integer'], 'semestre' => ['required', 'in:1,2']]);
        [$desde, $hasta] = $datos['semestre'] == 1 ? ["{$datos['anio']}-01-01", "{$datos['anio']}-06-30"] : ["{$datos['anio']}-07-01", "{$datos['anio']}-12-31"];
        $eess = AlcanceDeEess::permitidos($request->user());

        $filas = DB::table('Asistencia.AsistenciaDiaria as a')->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'a.VinculoLaboralId')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->join('Organizacion.EstablecimientoSalud as es', 'es.EessId', '=', 'v.EessId')
            ->whereDate('a.AsistenciaDiariaFecha', '>=', $desde)->whereDate('a.AsistenciaDiariaFecha', '<=', $hasta)
            ->when($eess !== null, fn ($q) => $q->whereIn('v.EessId', $eess ?: [0]))
            ->groupBy('v.VinculoLaboralId', 't.TrabajadorNombreCompleto', 'es.EessNombre')
            ->havingRaw('SUM(a.AsistenciaDiariaMinutosTardanza) = 0 AND SUM(CASE WHEN e.EstadoAsistenciaEsFalta = 1 THEN 1 ELSE 0 END) = 0 AND COUNT(*) >= 20')
            ->orderBy('t.TrabajadorNombreCompleto')
            ->get(['v.VinculoLaboralId as vinculo_id', 't.TrabajadorNombreCompleto as nombre', 'es.EessNombre as eess', DB::raw('COUNT(*) as dias')]);

        return response()->json(['data' => $filas]);
    }

    private function pdf(string $contenido, string $nombre): Response
    {
        return response($contenido, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$nombre.'"']);
    }
}
