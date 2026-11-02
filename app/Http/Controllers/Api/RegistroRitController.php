<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccesoUsuario;
use App\Support\AlcanceDeEess;
use App\Support\RegistrosRit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CRUD de los registros sencillos del RIT (ver App\Support\RegistrosRit). Mismo contrato que el resto de la API: la
 * lectura sale en snake_case sin el prefijo de la tabla y la escritura entra con los nombres de columna.
 */
class RegistroRitController extends Controller
{
    private function recurso(Request $request): string
    {
        return (string) $request->segment(2);
    }

    // GET /api/{recurso}?vinculo_laboral_id=&trabajador_id=&eess_id=&por_pagina=15
    public function index(Request $request)
    {
        [$modelo, , $orden] = RegistrosRit::RECURSOS[$this->recurso($request)];
        $ejemplo = new $modelo;
        $columnas = $ejemplo->getFillable();

        $registros = $modelo::query()
            ->with($this->relaciones($ejemplo))
            ->when($request->filled('vinculo_laboral_id') && in_array('VinculoLaboralId', $columnas, true), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('trabajador_id') && in_array('TrabajadorId', $columnas, true), fn ($q) => $q->where('TrabajadorId', $request->integer('trabajador_id')))
            ->tap(fn (Builder $q) => $this->aplicarAlcance($q, $request, $columnas))
            ->orderByDesc($orden)
            ->orderByDesc($ejemplo->getKeyName())
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return response()->json([
            'data' => $registros->getCollection()->map(fn (Model $m) => $this->aArreglo($m))->values(),
            'meta' => ['total' => $registros->total(), 'current_page' => $registros->currentPage(), 'last_page' => $registros->lastPage(), 'per_page' => $registros->perPage()],
        ]);
    }

    // POST /api/{recurso}
    public function store(Request $request): JsonResponse
    {
        $recurso = $this->recurso($request);
        [$modelo] = RegistrosRit::RECURSOS[$recurso];
        $datos = $request->validate(RegistrosRit::reglas($recurso, true));
        $this->exigirReglas($recurso, $datos, null);
        $registro = $modelo::create(RegistrosRit::completar($recurso, $datos, $request));

        return response()->json(['data' => $this->aArreglo($registro->fresh()->load($this->relaciones($registro)))], 201);
    }

    // GET /api/{recurso}/{registro}
    public function show(Request $request): JsonResponse
    {
        $registro = $request->route('registro');

        return response()->json(['data' => $this->aArreglo($registro->load($this->relaciones($registro)))]);
    }

    // PUT|PATCH /api/{recurso}/{registro}
    public function update(Request $request): JsonResponse
    {
        $recurso = $this->recurso($request);
        $registro = $request->route('registro');
        $datos = $request->validate(RegistrosRit::reglas($recurso, false));
        $this->exigirReglas($recurso, $datos + $this->valoresActuales($registro), (int) $registro->getKey());
        $registro->update($datos);

        return response()->json(['data' => $this->aArreglo($registro->fresh()->load($this->relaciones($registro)))]);
    }

    // DELETE /api/{recurso}/{registro}   desactiva o anula: el registro queda como constancia
    public function destroy(Request $request): JsonResponse
    {
        [, , , $estado, $valor] = RegistrosRit::RECURSOS[$this->recurso($request)];
        $request->route('registro')->update([$estado => $valor]);

        return response()->json(['mensaje' => 'Registro desactivado.']);
    }

    /** @param  array<string,mixed>  $datos */
    private function exigirReglas(string $recurso, array $datos, ?int $id): void
    {
        $errores = RegistrosRit::problemas($recurso, $datos, $id);
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** @return array<string,mixed> columnas actuales como texto AAAA-MM-DD, para validar una edicion parcial */
    private function valoresActuales(Model $registro): array
    {
        return collect($registro->getAttributes())->map(fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : $v)->all();
    }

    /** @param  list<string>  $columnas */
    private function aplicarAlcance(Builder $q, Request $request, array $columnas): void
    {
        $acceso = AccesoUsuario::de($request->user());
        if (in_array('EessId', $columnas, true)) {
            $q->when(AlcanceDeEess::restringe($request), fn ($s) => $s->whereIn('EessId', AlcanceDeEess::ids($request)));

            return;
        }
        if ($acceso->eessIds === null && ! $acceso->propio) {
            return;
        }
        $vinculos = DB::table('Personal.VinculoLaboral')->when($acceso->propio, fn ($s) => $s->whereIn('VinculoLaboralId', $acceso->vinculoIds), fn ($s) => $s->whereIn('EessId', $acceso->eessIds));
        if (in_array('VinculoLaboralId', $columnas, true)) {
            $q->whereIn('VinculoLaboralId', $vinculos->select('VinculoLaboralId'));
        } elseif (in_array('TrabajadorId', $columnas, true)) {
            $q->whereIn('TrabajadorId', $vinculos->select('TrabajadorId'));
        }
    }

    /** @return list<string> */
    private function relaciones(Model $modelo): array
    {
        $relaciones = [];
        foreach (['vinculoLaboral.trabajador', 'vinculoLaboral.eess', 'trabajador', 'eess', 'destino', 'documento', 'usuario', 'recibe.trabajador'] as $ruta) {
            if (method_exists($modelo, explode('.', $ruta)[0])) {
                $relaciones[] = $ruta;
            }
        }

        return $relaciones;
    }

    /** @return array<string,mixed> */
    private function aArreglo(Model $m): array
    {
        $prefijo = RegistrosRit::RECURSOS[collect(RegistrosRit::RECURSOS)->search(fn ($c) => $c[0] === $m::class)][1];
        $salida = ['id' => $m->getKey()];
        foreach ($m->getFillable() as $columna) {
            $clave = str_starts_with($columna, $prefijo) ? Str::snake(substr($columna, strlen($prefijo))) : Str::snake($columna);
            $valor = $m->getAttribute($columna);
            $salida[$clave] = $valor instanceof \DateTimeInterface ? $valor->format(str_contains($columna, 'FechaHora') || str_contains($columna, 'Registro') ? 'Y-m-d H:i:s' : 'Y-m-d') : $valor;
        }
        $estado = $salida['estado'] ?? true;
        $salida['activo'] = is_bool($estado) ? $estado : (in_array($estado, ['ANULADO'], true) ? false : true);

        $persona = $m->relationLoaded('vinculoLaboral') ? $m->vinculoLaboral?->trabajador : ($m->relationLoaded('trabajador') ? $m->trabajador : null);
        $salida['trabajador'] = $persona ? ['id' => $persona->TrabajadorId, 'numero_documento' => $persona->TrabajadorNumeroDocumento,
            'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $persona->TrabajadorNombreCompleto)))] : null;
        $eess = $m->relationLoaded('eess') ? $m->eess : ($m->relationLoaded('vinculoLaboral') ? $m->vinculoLaboral?->eess : null);
        $salida['eess'] = $eess ? ['id' => $eess->EessId, 'nombre' => $eess->EessNombre] : null;
        if ($m->relationLoaded('destino')) {
            $salida['destino'] = $m->destino ? ['id' => $m->destino->EessId, 'nombre' => $m->destino->EessNombre] : null;
        }
        if ($m->relationLoaded('recibe')) {
            $salida['recibe'] = $m->recibe?->trabajador?->TrabajadorNombreCompleto;
        }
        if ($m->relationLoaded('documento')) {
            $salida['documento'] = $m->documento ? ['id' => $m->documento->DocumentoSustentoId, 'nombre' => $m->documento->DocumentoSustentoNombre] : null;
        }
        if ($m->relationLoaded('usuario')) {
            $salida['usuario'] = $m->usuario?->UsuarioNombre;
        }

        return $salida;
    }
}
