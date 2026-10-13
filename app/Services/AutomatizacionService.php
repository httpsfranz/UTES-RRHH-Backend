<?php

namespace App\Services;

use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Procesos automaticos del sistema (se programan en routes/console.php). Cada uno es idempotente: ejecutarlo dos veces
 * seguidas no cambia nada la segunda vez. Ninguno toma decisiones que corresponden a una persona (cerrar un periodo,
 * aprobar, sancionar): solo mantienen al dia lo que las reglas ya determinan.
 *
 *   - abrirPeriodoDelMes():        periodo de asistencia ABIERTO del mes en curso (calendario del mes).
 *   - vencerCompensaciones():      RIT Art. 17: la compensacion se hace como maximo hasta el mes siguiente del trabajo.
 *   - cumplirTurnos():             marca CUMPLIDO el turno publicado cuyo dia ya paso y se asistio (misma regla que "cumplir").
 *   - detectarFaltasReiteradas():  RIT Art. 23: mas de 3 faltas injustificadas consecutivas, mas de 5 en 30 dias o mas de
 *                                  15 en 180 dias llevan al Regimen Disciplinario. Solo AVISA a RRHH; abrir el expediente
 *                                  PAD sigue siendo decision de una persona.
 */
class AutomatizacionService
{
    public const TIPO_ALERTA_FALTAS = 'ALERTA_FALTAS_REITERADAS';

    public const ROLES_QUE_RECIBEN_ALERTAS = ['ADMIN', 'RRHH_RED'];

    public function __construct(private readonly NotificacionService $notificaciones) {}

    /** @return bool true si creo el periodo */
    public function abrirPeriodoDelMes(): bool
    {
        $hoy = HoraLocal::hoy();
        $existe = DB::table('Consolidacion.PeriodoAsistencia')->where(['PeriodoAsistenciaAnio' => $hoy->year, 'PeriodoAsistenciaMes' => $hoy->month])->exists();
        if ($existe) {
            return false;
        }
        DB::table('Consolidacion.PeriodoAsistencia')->insert([
            'PeriodoAsistenciaAnio' => $hoy->year,
            'PeriodoAsistenciaMes' => $hoy->month,
            'PeriodoAsistenciaFechaInicio' => $hoy->startOfMonth()->toDateString(),
            'PeriodoAsistenciaFechaFin' => $hoy->endOfMonth()->toDateString(),
            'PeriodoAsistenciaEstado' => 'ABIERTO',
        ]);

        return true;
    }

    /** @return int compensaciones pasadas a VENCIDO */
    public function vencerCompensaciones(): int
    {
        return DB::table('Compensaciones.CompensacionHoraria')
            ->whereIn('CompensacionHorariaEstado', ['PENDIENTE', 'APROBADO'])
            ->whereNotNull('CompensacionHorariaFechaLimite')
            ->whereDate('CompensacionHorariaFechaLimite', '<', HoraLocal::hoy()->toDateString())
            ->update(['CompensacionHorariaEstado' => 'VENCIDO']);
    }

    /** @return int turnos pasados a CUMPLIDO */
    public function cumplirTurnos(): int
    {
        return DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Programacion.ProgramacionPeriodo as pp', 'pp.ProgramacionPeriodoId', '=', 'pt.ProgramacionPeriodoId')
            ->where('pp.ProgramacionPeriodoEstado', 'PUBLICADA')
            ->whereIn('tp.TurnoProgramadoEstado', ['PROGRAMADO', 'REPROGRAMADO'])
            ->whereDate('tp.TurnoProgramadoFecha', '<', HoraLocal::hoy()->toDateString())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('Asistencia.AsistenciaDiaria as ad')
                ->join('Asistencia.EstadoAsistencia as ea', 'ea.EstadoAsistenciaId', '=', 'ad.EstadoAsistenciaId')
                ->whereColumn('ad.VinculoLaboralId', 'pt.VinculoLaboralId')
                ->whereColumn('ad.AsistenciaDiariaFecha', 'tp.TurnoProgramadoFecha')
                ->where('ea.EstadoAsistenciaEsFalta', 0)->where('ea.EstadoAsistenciaEsLaborable', 1))
            ->update(['tp.TurnoProgramadoEstado' => 'CUMPLIDO']);
    }

    /**
     * Avisa de los trabajadores que alcanzaron el limite del Art. 23. Un aviso por usuario destinatario y por falta mas
     * reciente: si no hay faltas nuevas no se repite.
     *
     * @return int avisos creados
     */
    public function detectarFaltasReiteradas(): int
    {
        $hoy = HoraLocal::hoy();
        $faltas = DB::table('Asistencia.AsistenciaDiaria as ad')
            ->join('Asistencia.EstadoAsistencia as ea', 'ea.EstadoAsistenciaId', '=', 'ad.EstadoAsistenciaId')
            ->join('Personal.VinculoLaboral as vl', 'vl.VinculoLaboralId', '=', 'ad.VinculoLaboralId')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'vl.TrabajadorId')
            ->where('ea.EstadoAsistenciaEsLaborable', 1)
            ->whereDate('ad.AsistenciaDiariaFecha', '>', $hoy->subDays(180)->toDateString())
            ->whereDate('ad.AsistenciaDiariaFecha', '<=', $hoy->toDateString())
            ->orderBy('ad.AsistenciaDiariaFecha')
            ->get(['ad.VinculoLaboralId as vinculo', 'vl.VinculoLaboralCodigo as codigo', 't.TrabajadorNombreCompleto as nombre', 'ad.AsistenciaDiariaFecha as fecha',
                'ea.EstadoAsistenciaEsFalta as es_falta', 'ea.EstadoAsistenciaEsDescontable as es_descontable'])
            ->groupBy('vinculo');

        $destinatarios = $this->destinatarios();
        $creados = 0;
        foreach ($faltas as $filas) {
            $motivo = $this->motivoSiSuperaElLimite($filas, $hoy);
            if ($motivo === null || $destinatarios === []) {
                continue;
            }
            $nombre = trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $filas->first()->nombre)));
            $titulo = "Faltas injustificadas reiteradas: {$nombre} ({$filas->first()->codigo})";
            $mensaje = "{$motivo} Última falta: ".$this->ultimaFalta($filas).'. Evaluar el inicio del procedimiento disciplinario (RIT, Art. 23).';
            foreach ($destinatarios as $usuarioId) {
                $yaAvisado = DB::table('Soporte.Notificacion')->where(['UsuarioId' => $usuarioId, 'NotificacionTipo' => self::TIPO_ALERTA_FALTAS, 'NotificacionTitulo' => $titulo, 'NotificacionMensaje' => $mensaje])->exists();
                if (! $yaAvisado) {
                    $this->notificaciones->notificar($usuarioId, self::TIPO_ALERTA_FALTAS, $titulo, $mensaje, '/disciplina/expedientes-pad');
                    $creados++;
                }
            }
        }

        return $creados;
    }

    /**
     * @param  Collection<int,object>  $filas  dias laborables del trabajador, ordenados por fecha
     */
    private function motivoSiSuperaElLimite($filas, CarbonImmutable $hoy): ?string
    {
        $esInjustificada = fn ($f) => (bool) $f->es_falta && (bool) $f->es_descontable;
        $faltas180 = $filas->filter($esInjustificada);
        $faltas30 = $faltas180->filter(fn ($f) => CarbonImmutable::parse(substr((string) $f->fecha, 0, 10))->gt($hoy->subDays(30)));

        $maxConsecutivas = 0;
        $racha = 0;
        foreach ($filas->filter(fn ($f) => CarbonImmutable::parse(substr((string) $f->fecha, 0, 10))->gt($hoy->subDays(30))) as $fila) {
            $racha = $esInjustificada($fila) ? $racha + 1 : 0;
            $maxConsecutivas = max($maxConsecutivas, $racha);
        }

        return match (true) {
            $maxConsecutivas > 3 => "Registra {$maxConsecutivas} faltas injustificadas consecutivas en los últimos 30 días (límite: 3).",
            $faltas30->count() > 5 => "Registra {$faltas30->count()} faltas injustificadas en los últimos 30 días (límite: 5).",
            $faltas180->count() > 15 => "Registra {$faltas180->count()} faltas injustificadas en los últimos 180 días (límite: 15).",
            default => null,
        };
    }

    private function ultimaFalta($filas): string
    {
        $ultima = $filas->filter(fn ($f) => (bool) $f->es_falta && (bool) $f->es_descontable)->last();

        return CarbonImmutable::parse(substr((string) $ultima->fecha, 0, 10))->format('d/m/Y');
    }

    /** @return list<int> usuarios activos con un rol vigente que recibe las alertas */
    private function destinatarios(): array
    {
        $hoy = HoraLocal::hoy()->toDateString();

        return DB::table('Seguridad.UsuarioRol as ur')
            ->join('Seguridad.Rol as r', 'r.RolId', '=', 'ur.RolId')
            ->join('Seguridad.Usuario as u', 'u.UsuarioId', '=', 'ur.UsuarioId')
            ->whereIn('r.RolCodigo', self::ROLES_QUE_RECIBEN_ALERTAS)->where('ur.UsuarioRolEstado', 1)->where('u.UsuarioEstado', 1)
            ->whereDate('ur.UsuarioRolFechaInicio', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('ur.UsuarioRolFechaFin')->orWhereDate('ur.UsuarioRolFechaFin', '>=', $hoy))
            ->distinct()->pluck('u.UsuarioId')->map(fn ($id) => (int) $id)->all();
    }
}
