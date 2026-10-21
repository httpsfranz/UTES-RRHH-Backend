<?php

namespace App\Services;

use App\Models\Soporte\CalendarioNoLaborable;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;

/**
 * Efectos de registrar, cambiar o desactivar un dia no laborable:
 *   - la norma de sustento se completa sola segun el tipo si no se escribe;
 *   - se informan los periodos de asistencia que abarca (un periodo CERRADO no admite el cambio, ver el Request);
 *   - se recalcula la asistencia de ese dia (el motor trata el dia como NO_LABORABLE para el personal por horario) y se
 *     avisa a los responsables de los establecimientos afectados.
 */
class CalendarioNoLaborableService
{
    /** Norma que sustenta cada tipo cuando no se indica otra. */
    public const NORMAS = [
        'FERIADO' => 'D. Leg. N.° 713, descansos remunerados (feriados nacionales)',
        'DIA_NO_LABORABLE' => 'Decreto Supremo que declara día no laborable en el sector público',
        'ASUETO' => 'Resolución de la entidad que otorga el asueto',
        'DUELO' => 'Decreto Supremo que declara duelo nacional',
    ];

    public function __construct(private CalculoAsistenciaService $calculo, private NotificacionService $notificaciones) {}

    public static function normaPorDefecto(string $tipo): ?string
    {
        // La ultima norma usada para ese tipo (p. ej. el mismo D.S. de varios dias) o la general del tipo.
        return DB::table('Soporte.CalendarioNoLaborable')->where('CalendarioNoLaborableTipo', $tipo)->whereNotNull('CalendarioNoLaborableNormaSustento')
            ->whereDate('CalendarioNoLaborableFecha', '>=', HoraLocal::hoy()->subMonths(2)->toDateString())
            ->orderByDesc('CalendarioNoLaborableId')->value('CalendarioNoLaborableNormaSustento') ?? (self::NORMAS[$tipo] ?? null);
    }

    /** @return list<array{id:int,anio:int,mes:int,estado:string}> periodos de asistencia que contienen la fecha */
    public static function periodosAfectados(string $fecha): array
    {
        return DB::table('Consolidacion.PeriodoAsistencia')->whereDate('PeriodoAsistenciaFechaInicio', '<=', $fecha)->whereDate('PeriodoAsistenciaFechaFin', '>=', $fecha)
            ->get(['PeriodoAsistenciaId', 'PeriodoAsistenciaAnio', 'PeriodoAsistenciaMes', 'PeriodoAsistenciaEstado'])
            ->map(fn ($p) => ['id' => (int) $p->PeriodoAsistenciaId, 'anio' => (int) $p->PeriodoAsistenciaAnio, 'mes' => (int) $p->PeriodoAsistenciaMes, 'estado' => $p->PeriodoAsistenciaEstado])
            ->all();
    }

    /** Recalcula la asistencia de las fechas tocadas (solo las que ya ocurrieron) y avisa a los establecimientos. */
    public function aplicar(CalendarioNoLaborable $dia, ?string $fechaAnterior = null): void
    {
        $hoy = HoraLocal::hoy()->toDateString();
        $eess = DB::table('Organizacion.EstablecimientoSalud')->when($dia->MicroredId, fn ($q) => $q->where('MicroredId', $dia->MicroredId))->pluck('EessId');
        foreach (array_unique(array_filter([$dia->CalendarioNoLaborableFecha?->toDateString(), $fechaAnterior])) as $fecha) {
            if ($fecha <= $hoy) {
                foreach ($eess as $eessId) {
                    $this->calculo->calcular($fecha, $fecha, null, (int) $eessId);
                }
            }
        }

        $fecha = $dia->CalendarioNoLaborableFecha?->format('d/m/Y');
        $estado = $dia->CalendarioNoLaborableEstado ? 'registró' : 'dejó sin efecto';
        foreach ($eess->take(50) as $eessId) {
            $this->notificaciones->emitir('CALENDARIO_NO_LABORABLE', "Día no laborable: {$fecha}",
                "Se {$estado} el {$fecha} como ".strtolower(str_replace('_', ' ', $dia->CalendarioNoLaborableTipo)).($dia->CalendarioNoLaborableDescripcion ? " ({$dia->CalendarioNoLaborableDescripcion})" : '').'. Revisa la programación y la asistencia de ese día.',
                '/configuracion/panel?abrir=calendario', (int) $eessId);
        }
    }
}
