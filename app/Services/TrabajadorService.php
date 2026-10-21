<?php

namespace App\Services;

use App\Models\Personal\Colegiatura;
use App\Models\Personal\ColegiaturaTipo;
use App\Models\Personal\Profesion;
use App\Models\Personal\Trabajador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta y edicion del trabajador en UN solo formulario: los datos personales, la profesion y sus colegiaturas.
 *
 *   - Profesion "Otro": si se escribe una profesion que no existe (ProfesionNueva), se crea en Personal.Profesion y queda
 *     disponible para los demas trabajadores.
 *   - Colegiaturas: la lista enviada es la vigente del trabajador. Las que traen ColegiaturaId se actualizan, las nuevas se
 *     crean y las que ya no vienen se dan de baja (baja logica, se conserva el historial). Un colegio que no existe
 *     (ColegiaturaTipoNuevo) se crea ligado a la profesion del trabajador.
 *
 * Todo ocurre en una transaccion: o se guarda el trabajador con su profesion y colegiaturas, o nada.
 */
class TrabajadorService
{
    /**
     * @param  array<string,mixed>  $datos  columnas de Personal.Trabajador
     * @param  list<array<string,mixed>>|null  $colegiaturas  null = no se tocan las colegiaturas
     */
    public function guardar(?Trabajador $trabajador, array $datos, ?string $profesionNueva, ?array $colegiaturas): Trabajador
    {
        return DB::transaction(function () use ($trabajador, $datos, $profesionNueva, $colegiaturas) {
            if (filled($profesionNueva)) {
                $datos['ProfesionId'] = $this->profesion($profesionNueva, $colegiaturas !== null && $colegiaturas !== [])->ProfesionId;
            }

            if ($trabajador === null) {
                $trabajador = Trabajador::create($datos);
            } else {
                $trabajador->update($datos);
            }

            if ($colegiaturas !== null) {
                $this->sincronizarColegiaturas($trabajador->fresh(), $colegiaturas);
            }

            return $trabajador->fresh();
        });
    }

    /** Profesion escrita a mano: la existente con ese nombre (sin distinguir mayusculas) o una nueva. */
    public function profesion(string $nombre, bool $requiereColegiatura = false): Profesion
    {
        $nombre = Str::of($nombre)->squish()->toString();
        $existente = Profesion::query()->whereRaw('LOWER(ProfesionNombre) = ?', [mb_strtolower($nombre)])->first();
        if ($existente !== null) {
            if (! $existente->ProfesionEstado) {
                $existente->update(['ProfesionEstado' => 1]);
            }

            return $existente;
        }

        return Profesion::create(['ProfesionNombre' => $nombre, 'ProfesionRequiereColegiatura' => $requiereColegiatura]);
    }

    /** Colegio escrito a mano: el existente con ese nombre o uno nuevo, de la profesion del trabajador. */
    public function colegio(string $nombre, ?int $profesionId): ColegiaturaTipo
    {
        $nombre = Str::of($nombre)->squish()->toString();
        $existente = ColegiaturaTipo::query()->whereRaw('LOWER(ColegiaturaTipoNombre) = ?', [mb_strtolower($nombre)])->first();
        if ($existente !== null) {
            return $existente;
        }

        return ColegiaturaTipo::create([
            'ProfesionId' => $profesionId,
            'ColegiaturaTipoCodigo' => $this->siglas($nombre),
            'ColegiaturaTipoNombre' => $nombre,
            'ColegiaturaTipoEntidad' => $nombre,
        ]);
    }

    /** @param  list<array<string,mixed>>  $filas */
    private function sincronizarColegiaturas(Trabajador $trabajador, array $filas): void
    {
        $vigentes = [];
        foreach ($filas as $fila) {
            $tipoId = filled($fila['ColegiaturaTipoNuevo'] ?? null)
                ? $this->colegio($fila['ColegiaturaTipoNuevo'], $trabajador->ProfesionId)->ColegiaturaTipoId
                : (int) $fila['ColegiaturaTipoId'];
            $columnas = [
                'TrabajadorId' => $trabajador->TrabajadorId,
                'ColegiaturaTipoId' => $tipoId,
                'ColegiaturaNumero' => strtoupper((string) $fila['ColegiaturaNumero']),
                'ColegiaturaFechaColegiatura' => $fila['ColegiaturaFechaColegiatura'] ?? null,
                'ColegiaturaFechaVencimiento' => $fila['ColegiaturaFechaVencimiento'] ?? null,
                'ColegiaturaEsHabilitado' => (bool) ($fila['ColegiaturaEsHabilitado'] ?? true),
                'ColegiaturaEsPrincipal' => (bool) ($fila['ColegiaturaEsPrincipal'] ?? false),
                'ColegiaturaEstado' => true,
            ];

            // La misma colegiatura (por id, o por colegio si se dio de baja antes) se reutiliza: UQ (trabajador, colegio).
            $colegiatura = filled($fila['ColegiaturaId'] ?? null)
                ? Colegiatura::query()->where('TrabajadorId', $trabajador->TrabajadorId)->find($fila['ColegiaturaId'])
                : Colegiatura::query()->where('TrabajadorId', $trabajador->TrabajadorId)->where('ColegiaturaTipoId', $tipoId)->first();

            if ($colegiatura !== null) {
                $colegiatura->update($columnas);
            } else {
                $colegiatura = Colegiatura::create($columnas);
            }
            $vigentes[] = $colegiatura->ColegiaturaId;
        }

        Colegiatura::query()->where('TrabajadorId', $trabajador->TrabajadorId)->where('ColegiaturaEstado', 1)
            ->whereNotIn('ColegiaturaId', $vigentes ?: [0])
            ->get()->each->update(['ColegiaturaEstado' => false, 'ColegiaturaEsPrincipal' => false]);
    }

    /** Siglas unicas del colegio: "Colegio de Biologos del Peru" -> "CBP" (CBP2 si ya existe). */
    private function siglas(string $nombre): string
    {
        $palabras = preg_split('/\s+/', Str::ascii($nombre)) ?: [];
        $siglas = strtoupper(implode('', array_map(fn ($p) => $p[0] ?? '', array_filter($palabras, fn ($p) => ! in_array(strtolower($p), ['de', 'del', 'la', 'las', 'los', 'el', 'y'], true)))));
        $siglas = substr($siglas !== '' ? $siglas : 'COL', 0, 15);
        $codigo = $siglas;
        for ($n = 2; ColegiaturaTipo::query()->where('ColegiaturaTipoCodigo', $codigo)->exists(); $n++) {
            $codigo = $siglas.$n;
        }

        return $codigo;
    }
}
