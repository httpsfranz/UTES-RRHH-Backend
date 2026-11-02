<?php

namespace App\Support;

use App\Models\Asistencia\AjusteMarcacion;
use App\Models\Compensaciones\DetalleLiquidacion;
use App\Models\Compensaciones\LiquidacionDescuento;
use App\Models\Consolidacion\DetalleConsolidado;
use App\Models\Programacion\CambioTurno;
use App\Models\Programacion\TurnoProgramado;
use App\Models\Seguridad\Usuario;
use App\Models\Vacaciones\GoceVacacional;
use App\Models\Vacaciones\RolVacacional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Alcance por registro (RIT, Art. 24, 28 y 103: cada jefe responde por su personal; Art. 83.19: reserva de la informacion
 * del servidor). AlcanceDeEess filtra los listados; esta clase protege lo demas: abrir, editar, aprobar o borrar un
 * registro por su id, y enviar en una peticion un trabajador o establecimiento que no le corresponde al usuario.
 *
 * Para cada modelo se deduce a que vinculo o establecimiento pertenece: por sus columnas (VinculoLaboralId, EessId,
 * TrabajadorId) o por el camino de RELACIONES. Un modelo sin dueno (catalogos) no se restringe.
 */
class AlcanceDeRegistro
{
    /** Modelos que llegan a su dueno por una relacion: clase => relacion a seguir. */
    private const RELACIONES = [
        AjusteMarcacion::class => 'marcacion',
        GoceVacacional::class => 'rolVacacional',
        RolVacacional::class => 'periodoVacacional',
        TurnoProgramado::class => 'programacionTrabajador',
        DetalleConsolidado::class => 'consolidado',
        DetalleLiquidacion::class => 'liquidacion',
        LiquidacionDescuento::class => 'consolidado',
    ];

    /** Campos de la peticion que apuntan a una persona o a un establecimiento. */
    private const CAMPOS_VINCULO = ['VinculoLaboralId', 'VinculoLaboralSolicitanteId', 'VinculoLaboralReemplazanteId', 'VinculoLaboralRecibeId'];

    public static function puedeVer(Model $registro, Usuario $usuario): bool
    {
        $acceso = AccesoUsuario::de($usuario);
        if ($acceso->eessIds === null && ! $acceso->propio) {
            return true;
        }
        [$vinculos, $eess, $trabajador] = self::duenoDe($registro);
        if ($vinculos === [] && $eess === [] && $trabajador === null) {
            return true;
        }
        if ($acceso->propio) {
            if ($vinculos !== []) {
                return array_intersect($vinculos, $acceso->vinculoIds) !== [];
            }

            return $trabajador !== null && (int) $trabajador === (int) $usuario->TrabajadorId;
        }

        if ($trabajador !== null && $vinculos === []) {
            $vinculos = self::vinculosDeTrabajador((int) $trabajador);
            if ($vinculos === []) {
                return true; // Recien dado de alta, aun sin vinculo: todavia no pertenece a ningun establecimiento.
            }
        }

        return array_intersect(array_merge($eess, self::eessDeVinculos($vinculos)), $acceso->eessIds ?? []) !== [];
    }

    /** Campos de la peticion que apuntan fuera del alcance del usuario (vacio = todo en orden). */
    public static function camposFueraDeAlcance(Request $request, Usuario $usuario): array
    {
        $acceso = AccesoUsuario::de($usuario);
        if ($acceso->eessIds === null) {
            return [];
        }
        $fuera = [];
        foreach (self::CAMPOS_VINCULO as $campo) {
            $id = $request->input($campo);
            if (is_numeric($id) && array_intersect(self::eessDeVinculos([(int) $id]), $acceso->eessIds) === []) {
                $fuera[] = $campo;
            }
        }
        $eess = $request->input('EessId');
        if (is_numeric($eess) && ! in_array((int) $eess, $acceso->eessIds, true)) {
            $fuera[] = 'EessId';
        }

        return $fuera;
    }

    /** @return array{0:list<int>,1:list<int>,2:int|null} vinculos, establecimientos y trabajador dueno del registro */
    private static function duenoDe(Model $registro): array
    {
        $visitados = 0;
        while (isset(self::RELACIONES[$registro::class]) && $visitados++ < 4) {
            $siguiente = $registro->getRelationValue(self::RELACIONES[$registro::class]);
            if (! $siguiente instanceof Model) {
                return [[], [], null];
            }
            $registro = $siguiente;
        }

        if ($registro instanceof CambioTurno) {
            return [array_values(array_filter([(int) $registro->VinculoLaboralSolicitanteId, (int) $registro->VinculoLaboralReemplazanteId])), [], null];
        }
        $vinculo = $registro->getAttribute('VinculoLaboralId');
        $eess = $registro->getAttribute('EessId');
        $trabajador = $registro->getAttribute('TrabajadorId');

        return [$vinculo ? [(int) $vinculo] : [], $eess ? [(int) $eess] : [], $trabajador ? (int) $trabajador : null];
    }

    /** @return list<int> */
    private static function vinculosDeTrabajador(int $trabajadorId): array
    {
        return DB::table('Personal.VinculoLaboral')->where('TrabajadorId', $trabajadorId)->pluck('VinculoLaboralId')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Establecimientos de esos vinculos: el suyo y, si esta desplazado temporalmente, el de destino.
     *
     * @param  list<int>  $vinculos
     * @return list<int>
     */
    private static function eessDeVinculos(array $vinculos): array
    {
        if ($vinculos === []) {
            return [];
        }
        $hoy = HoraLocal::hoy()->toDateString();
        $propios = DB::table('Personal.VinculoLaboral')->whereIn('VinculoLaboralId', $vinculos)->pluck('EessId');
        $destinos = DB::table('Personal.DesplazamientoTemporal')->whereIn('VinculoLaboralId', $vinculos)->where('DesplazamientoTemporalEstado', 'VIGENTE')
            ->whereDate('DesplazamientoTemporalFechaInicio', '<=', $hoy)->whereDate('DesplazamientoTemporalFechaFin', '>=', $hoy)->pluck('EessDestinoId');

        return $propios->merge($destinos)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
