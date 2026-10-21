<?php

namespace App\Services;

use App\Models\Asistencia\Marcacion;
use App\Models\Seguridad\Usuario;
use App\Support\AlcanceDeEess;
use App\Support\HoraLocal;
use App\Support\PeriodosDeAsistencia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Control manual de la asistencia desde el sistema (RIT, Art. 21): donde no hay reloj facial integrado, el jefe del
 * establecimiento o el responsable de control de asistencia registra la entrada y la salida de su personal. Es el parte
 * diario en version digital, con dos garantias:
 *
 *   - La hora NO se digita: es la del servidor (America/Lima) en el instante en que se pulsa "Marcar". Nadie puede
 *     anotar una hora distinta ni cambiar el reloj de su computadora para hacerlo.
 *   - Solo se marca el dia de hoy y solo personal del alcance del usuario.
 *
 * Despues de cada marca se recalcula la asistencia del trabajador (CalculoAsistenciaService): el calendario muestra el
 * resultado sin pulsar ningun boton de calculo.
 */
class MarcacionManualService
{
    /** Una entrada sin salida se considera abierta durante este tiempo (cubre la guardia nocturna de 12 h y su margen). */
    private const HORAS_ENTRADA_ABIERTA = 16;

    /** Dos marcas del mismo tipo en menos de estos minutos son un doble clic, no dos marcas. */
    private const MINUTOS_DOBLE_MARCA = 2;

    public function __construct(private CalculoAsistenciaService $calculo) {}

    /**
     * @return array{marcacion_id:int, tipo:string, hora:string, mensaje:string}
     */
    public function marcar(int $vinculoId, string $tipo, Usuario $operador, ?string $observacion = null): array
    {
        $ahora = HoraLocal::ahora();
        $hoy = $ahora->toDateString();

        $vinculo = DB::table('Personal.VinculoLaboral as vl')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'vl.TrabajadorId')
            ->where('vl.VinculoLaboralId', $vinculoId)
            ->first(['vl.VinculoLaboralId', 'vl.EessId', 'vl.VinculoLaboralEstado', 'vl.VinculoLaboralFechaInicio', 'vl.VinculoLaboralFechaFin', 't.TrabajadorNombreCompleto']);
        if ($vinculo === null) {
            throw ValidationException::withMessages(['VinculoLaboralId' => 'El trabajador no existe.']);
        }
        AlcanceDeEess::exigir((int) $vinculo->EessId, $operador);

        $vigente = $vinculo->VinculoLaboralEstado
            && substr((string) $vinculo->VinculoLaboralFechaInicio, 0, 10) <= $hoy
            && ($vinculo->VinculoLaboralFechaFin === null || substr((string) $vinculo->VinculoLaboralFechaFin, 0, 10) >= $hoy);
        if (! $vigente) {
            throw ValidationException::withMessages(['VinculoLaboralId' => 'El vínculo laboral del trabajador no está vigente hoy.']);
        }
        if (PeriodosDeAsistencia::cerradoEn($hoy)) {
            throw ValidationException::withMessages(['VinculoLaboralId' => 'El período de asistencia de hoy está cerrado: ya no admite marcaciones.']);
        }

        $abierta = $this->entradaAbierta($vinculoId, $ahora);
        if ($tipo === 'ENTRADA' && $abierta !== null) {
            throw ValidationException::withMessages(['MarcacionTipo' => 'Ya tiene una entrada registrada a las '.$abierta->format('H:i').' sin salida: marca su salida.']);
        }
        if ($tipo === 'SALIDA' && $abierta === null) {
            throw ValidationException::withMessages(['MarcacionTipo' => 'No tiene una entrada registrada: primero marca su entrada.']);
        }
        $reciente = Marcacion::query()->where('VinculoLaboralId', $vinculoId)->where('MarcacionTipo', $tipo)
            ->where('MarcacionFechaHora', '>=', $ahora->subMinutes(self::MINUTOS_DOBLE_MARCA)->toDateTimeString())
            ->where('MarcacionFechaHora', '<=', $ahora->toDateTimeString())->exists();
        if ($reciente) {
            throw ValidationException::withMessages(['MarcacionTipo' => 'Esa marca ya se registró hace un momento.']);
        }

        $metodo = (int) DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'MANUAL')->value('MetodoMarcacionId');
        $nota = 'Registro manual por '.$operador->UsuarioNombre.' (RIT, Art. 21).';
        $marcacion = Marcacion::create([
            'VinculoLaboralId' => $vinculoId,
            'MetodoMarcacionId' => $metodo,
            'MarcacionFechaHora' => $ahora->toDateTimeString(),
            'MarcacionTipo' => $tipo,
            'MarcacionObservacion' => mb_substr(trim($nota.' '.($observacion ?? '')), 0, 500),
            'MarcacionOrigen' => 'MANUAL',
            'MarcacionEsValida' => true,
        ]);

        // La salida de una guardia nocturna pertenece al dia anterior: se recalculan ayer y hoy.
        $this->calculo->calcular($ahora->subDay()->toDateString(), $hoy, $vinculoId);

        $nombre = trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $vinculo->TrabajadorNombreCompleto)));

        return [
            'marcacion_id' => (int) $marcacion->MarcacionId,
            'tipo' => $tipo,
            'hora' => $ahora->format('H:i'),
            'mensaje' => ($tipo === 'ENTRADA' ? 'Entrada' : 'Salida').' de '.$nombre.' registrada a las '.$ahora->format('H:i').'.',
        ];
    }

    /** Hora de la ultima ENTRADA valida sin una SALIDA posterior, si ocurrio en las ultimas horas. */
    public function entradaAbierta(int $vinculoId, CarbonImmutable $ahora): ?CarbonImmutable
    {
        $ultima = Marcacion::query()->where('VinculoLaboralId', $vinculoId)->where('MarcacionEsValida', 1)
            ->whereIn('MarcacionTipo', ['ENTRADA', 'SALIDA'])
            ->where('MarcacionFechaHora', '>=', $ahora->subHours(self::HORAS_ENTRADA_ABIERTA)->toDateTimeString())
            ->where('MarcacionFechaHora', '<=', $ahora->toDateTimeString())
            ->orderByDesc('MarcacionFechaHora')->first(['MarcacionTipo', 'MarcacionFechaHora']);

        return $ultima !== null && $ultima->MarcacionTipo === 'ENTRADA' ? CarbonImmutable::parse($ultima->MarcacionFechaHora) : null;
    }

    /**
     * Marcas de entrada y salida de cada vinculo en una fecha.
     *
     * @param  list<int>  $vinculos
     * @return Collection<int,list<array{tipo:string,hora:string,fecha:string,origen:?string}>>
     */
    public function marcasDelDia(array $vinculos, string $fecha): Collection
    {
        if ($vinculos === []) {
            return collect();
        }
        $dia = CarbonImmutable::parse($fecha);

        return DB::table('Asistencia.Marcacion')->whereIn('VinculoLaboralId', $vinculos)->where('MarcacionEsValida', 1)
            ->whereIn('MarcacionTipo', ['ENTRADA', 'SALIDA'])
            ->where('MarcacionFechaHora', '>=', $dia->toDateTimeString())->where('MarcacionFechaHora', '<', $dia->addDay()->toDateTimeString())
            ->orderBy('MarcacionFechaHora')
            ->get(['VinculoLaboralId', 'MarcacionTipo', 'MarcacionFechaHora', 'MarcacionOrigen'])
            ->groupBy('VinculoLaboralId')
            ->map(fn ($g) => $g->map(fn ($m) => [
                'tipo' => $m->MarcacionTipo,
                'hora' => substr((string) $m->MarcacionFechaHora, 11, 5),
                'fecha' => substr((string) $m->MarcacionFechaHora, 0, 10),
                'origen' => $m->MarcacionOrigen,
            ])->values()->all());
    }
}
