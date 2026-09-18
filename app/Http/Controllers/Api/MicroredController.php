<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MicroredRequest;
use App\Models\Microred;
use Illuminate\Http\Request;

class MicroredController extends Controller
{
    // GET /api/microredes?buscar=esperanza&estado=1
    public function index(Request $request)
    {
        $query = Microred::query();

        if ($request->filled('buscar')) {
            $b = $request->buscar;
            $query->where(fn ($q) => $q
                ->where('MicroredNombre', 'like', "%{$b}%")
                ->orWhere('MicroredCodigo', 'like', "%{$b}%"));
        }

        if ($request->has('estado')) {
            $query->where('MicroredEstado', $request->boolean('estado'));
        }

        return response()->json($query->orderBy('MicroredNombre')->paginate(15));
    }

    // POST /api/microredes
    public function store(MicroredRequest $request)
    {
        $microred = Microred::create($request->validated());
        return response()->json($microred->fresh(), 201);
    }

    // GET /api/microredes/{id}
    public function show(Microred $microred)
    {
        return response()->json($microred);
    }

    // PUT/PATCH /api/microredes/{id}
    public function update(MicroredRequest $request, Microred $microred)
    {
        $microred->update($request->validated());
        return response()->json($microred->fresh());
    }

    // DELETE /api/microredes/{id}  -> baja lógica
    public function destroy(Microred $microred)
    {
        $microred->update(['MicroredEstado' => false]);
        return response()->json(['message' => 'Microred desactivada correctamente']);
    }
}