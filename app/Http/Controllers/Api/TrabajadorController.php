<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrabajadorRequest;
use App\Http\Resources\TrabajadorResource;
use App\Models\Personal\Trabajador;
use App\Services\PersonalSinHorarioService;
use App\Services\TrabajadorService;
use App\Support\AlcanceDeEess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrabajadorController extends Controller
{
    /** Relaciones que se muestran con el trabajador: documento, profesion y colegiaturas activas con su colegio. */
    private const DETALLE = ['tipoDocumento', 'profesion', 'colegiaturas.tipo:ColegiaturaTipoId,ColegiaturaTipoCodigo,ColegiaturaTipoNombre'];

    // GET /api/trabajadores?buscar=perez|12345678&tipo_documento_id=1&profesion_id=2&sexo=F&estado=1&pendiente=horario|vinculo&por_pagina=15
    // pendiente=horario: con vinculo vigente sin horario ni programacion; pendiente=vinculo: sin vinculo vigente.
    public function index(Request $request, PersonalSinHorarioService $sinHorario)
    {
        $buscar = $request->string('buscar')->toString();

        $trabajadores = Trabajador::query()
            ->with(['tipoDocumento:TipoDocumentoIdentidadId,TipoDocumentoIdentidadCodigo,TipoDocumentoIdentidadNombre,TipoDocumentoIdentidadAbreviatura', 'profesion:ProfesionId,ProfesionNombre',
                'colegiaturas.tipo:ColegiaturaTipoId,ColegiaturaTipoCodigo,ColegiaturaTipoNombre',
                'vinculosVigentes.cargo:CargoId,CargoNombre', 'vinculosVigentes.eess:EessId,EessNombre', 'vinculosVigentes.condicionLaboral:CondicionLaboralId,CondicionLaboralNombre'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('TrabajadorNumeroDocumento', 'like', "%{$buscar}%")
                ->orWhere('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                ->orWhere('TrabajadorCorreo', 'like', "%{$buscar}%")))
            ->when($request->filled('tipo_documento_id'), fn ($q) => $q->where('TipoDocumentoIdentidadId', $request->integer('tipo_documento_id')))
            ->when($request->filled('profesion_id'), fn ($q) => $q->where('ProfesionId', $request->integer('profesion_id')))
            ->when($request->filled('sexo'), fn ($q) => $q->where('TrabajadorSexo', $request->string('sexo')->toString()))
            ->when($request->filled('estado'), fn ($q) => $q->where('TrabajadorEstado', $request->boolean('estado')))
            ->when($request->input('pendiente') === 'horario', fn ($q) => $q->whereIn('TrabajadorId', $sinHorario->vinculos(AlcanceDeEess::permitidos($request->user()))->select('vl.TrabajadorId')))
            ->when($request->input('pendiente') === 'vinculo', fn ($q) => $q->where('TrabajadorEstado', 1)->whereDoesntHave('vinculosVigentes'))
            ->orderBy('TrabajadorApellidoPaterno')
            ->orderBy('TrabajadorApellidoMaterno')
            ->orderBy('TrabajadorNombres')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        // Marca en cada vinculo de la pagina si aun le falta horario (la lista lo muestra como pendiente).
        $sinHorarioIds = $sinHorario->vinculos(null)->whereIn('vl.TrabajadorId', $trabajadores->getCollection()->pluck('TrabajadorId')->all() ?: [0])
            ->pluck('vl.VinculoLaboralId')->map(fn ($id) => (int) $id)->all();
        $trabajadores->getCollection()->each(fn (Trabajador $t) => $t->vinculosVigentes->each(fn ($v) => $v->setAttribute('sin_horario', in_array((int) $v->VinculoLaboralId, $sinHorarioIds, true))));

        return TrabajadorResource::collection($trabajadores);
    }

    // POST /api/trabajadores   (+ ProfesionNueva?, Colegiaturas?[]: ver TrabajadorService)
    public function store(TrabajadorRequest $request, TrabajadorService $servicio): JsonResponse
    {
        $trabajador = $servicio->guardar(null, $this->columnas($request), $request->input('ProfesionNueva'), $request->has('Colegiaturas') ? $request->validated('Colegiaturas', []) : null);

        // fresh(): trae lo que calcula la base (nombre completo, fecha de registro, Estado por DEFAULT).
        return (new TrabajadorResource($trabajador->load(self::DETALLE)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/trabajadores/{trabajador}
    public function show(Trabajador $trabajador)
    {
        return new TrabajadorResource($trabajador->load(self::DETALLE));
    }

    // PUT|PATCH /api/trabajadores/{trabajador}
    public function update(TrabajadorRequest $request, Trabajador $trabajador, TrabajadorService $servicio)
    {
        $trabajador = $servicio->guardar($trabajador, $this->columnas($request), $request->input('ProfesionNueva'), $request->has('Colegiaturas') ? $request->validated('Colegiaturas', []) : null);

        return new TrabajadorResource($trabajador->load(self::DETALLE));
    }

    // DELETE /api/trabajadores/{trabajador}
    public function destroy(Trabajador $trabajador): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: vinculos laborales, asistencia y usuarios cuelgan del trabajador
        // y el Reglamento obliga a conservar el historial.
        $trabajador->update(['TrabajadorEstado' => false]);

        return response()->json(['mensaje' => 'Trabajador desactivado.'], 200);
    }

    /** @return array<string,mixed> columnas de Personal.Trabajador (sin la profesion nueva ni las colegiaturas) */
    private function columnas(TrabajadorRequest $request): array
    {
        return collect($request->validated())->except(['ProfesionNueva', 'Colegiaturas'])->all();
    }
}
