<?php

namespace App\Services;

use App\Models\Vacaciones\GoceVacacional;
use App\Support\DiasHabiles;
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
     * Trabajadores que superan los limites de faltas injustificadas del RIT (Art. 23): mas de 3 consecutivas o mas de 5 en
     * 30 dias, o mas de 15 en 180 dias. Solo lee; lo usan el aviso automatico y el panel de inicio.
     *
     * @param  list<int>|null  $eessIds  limitar a esos establecimientos (null = toda la Red)
     * @return list<array{vinculo:int,codigo:string,nombre:string,eess:string,motivo:string,ultima:string}>
     */
    public function faltasReiteradas(?array $eessIds = null): array
    {
        $hoy = HoraLocal::hoy();
        $faltas = DB::table('Asistencia.AsistenciaDiaria as ad')
            ->join('Asistencia.EstadoAsistencia as ea', 'ea.EstadoAsistenciaId', '=', 'ad.EstadoAsistenciaId')
            ->join('Personal.VinculoLaboral as vl', 'vl.VinculoLaboralId', '=', 'ad.VinculoLaboralId')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'vl.TrabajadorId')
            ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'vl.EessId')
            ->where('ea.EstadoAsistenciaEsLaborable', 1)
            ->when($eessIds !== null, fn ($q) => $q->whereIn('vl.EessId', $eessIds))
            ->whereDate('ad.AsistenciaDiariaFecha', '>', $hoy->subDays(180)->toDateString())
            ->whereDate('ad.AsistenciaDiariaFecha', '<=', $hoy->toDateString())
            ->orderBy('ad.AsistenciaDiariaFecha')
            ->get(['ad.VinculoLaboralId as vinculo', 'vl.VinculoLaboralCodigo as codigo', 't.TrabajadorNombreCompleto as nombre', 'e.EessNombre as eess', 'ad.AsistenciaDiariaFecha as fecha',
                'ea.EstadoAsistenciaEsFalta as es_falta', 'ea.EstadoAsistenciaEsDescontable as es_descontable'])
            ->groupBy('vinculo');

        $resultado = [];
        foreach ($faltas as $filas) {
            $motivo = $this->motivoSiSuperaElLimite($filas, $hoy);
            if ($motivo === null) {
                continue;
            }
            $primera = $filas->first();
            $resultado[] = [
                'vinculo' => (int) $primera->vinculo,
                'codigo' => (string) $primera->codigo,
                'nombre' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $primera->nombre))),
                'eess' => (string) $primera->eess,
                'motivo' => $motivo,
                'ultima' => $this->ultimaFalta($filas),
            ];
        }

        return $resultado;
    }

    /**
     * Avisa a RRHH cuando alguien supera los limites de faltas injustificadas (ver faltasReiteradas()). Una sola vez por caso.
     *
     * @return int avisos creados
     */
    public function detectarFaltasReiteradas(): int
    {
        $destinatarios = $this->destinatarios();
        $creados = 0;
        foreach ($destinatarios === [] ? [] : $this->faltasReiteradas() as $caso) {
            $titulo = "Faltas injustificadas reiteradas: {$caso['nombre']} ({$caso['codigo']})";
            $mensaje = "{$caso['motivo']} Última falta: {$caso['ultima']}. Evaluar el inicio del procedimiento disciplinario (RIT, Art. 23).";
            foreach ($destinatarios as $usuarioId) {
                $yaAvisado = DB::table('Soporte.Notificacion')->where(['UsuarioId' => $usuarioId, 'NotificacionTipo' => self::TIPO_ALERTA_FALTAS, 'NotificacionTitulo' => $titulo, 'NotificacionMensaje' => $mensaje])->exists();
                if (! $yaAvisado) {
                    $this->notificaciones->notificar($usuarioId, self::TIPO_ALERTA_FALTAS, $titulo, $mensaje, '/disciplina/gestion?pestana=pad');
                    $creados++;
                }
            }
        }

        return $creados;
    }

    /** Avisa a RRHH de quien acumula mas de 60 minutos de tardanza en el mes: falta leve (RIT, Art. 104.1). */
    public function detectarTardanzasAcumuladas(): int
    {
        $hoy = HoraLocal::hoy();
        $mes = $hoy->startOfMonth();
        $casos = DB::table('Asistencia.AsistenciaDiaria as a')->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'a.VinculoLaboralId')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->whereDate('a.AsistenciaDiariaFecha', '>=', $mes->toDateString())->whereDate('a.AsistenciaDiariaFecha', '<=', $hoy->toDateString())
            ->groupBy('v.VinculoLaboralCodigo', 't.TrabajadorNombreCompleto')->havingRaw('SUM(a.AsistenciaDiariaMinutosTardanza) > 60')
            ->get(['v.VinculoLaboralCodigo as codigo', 't.TrabajadorNombreCompleto as nombre', DB::raw('SUM(a.AsistenciaDiariaMinutosTardanza) as minutos')]);

        return $this->avisarUnaVez('ALERTA_TARDANZA_MENSUAL', $casos->map(fn ($c) => [
            'titulo' => 'Tardanza acumulada: '.$this->limpio($c->nombre)." ({$c->codigo})",
            'mensaje' => "Acumula {$c->minutos} minutos en {$mes->format('m/Y')}: falta leve (RIT, Art. 104.1).",
        ])->all(), '/asistencia/asistencia-diaria');
    }

    /** Colegiaturas vencidas del personal con vinculo vigente (RIT, Art. 84.37). */
    public function detectarColegiaturasVencidas(): int
    {
        $hoy = HoraLocal::hoy()->toDateString();
        $casos = DB::table('Personal.Colegiatura as c')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'c.TrabajadorId')
            ->join('Personal.VinculoLaboral as v', 'v.TrabajadorId', '=', 't.TrabajadorId')
            ->where('c.ColegiaturaEstado', 1)->whereDate('c.ColegiaturaFechaVencimiento', '<', $hoy)->where('v.VinculoLaboralEstado', 1)
            ->where(fn ($q) => $q->whereNull('v.VinculoLaboralFechaFin')->orWhereDate('v.VinculoLaboralFechaFin', '>=', $hoy))
            ->distinct()->get(['t.TrabajadorNombreCompleto as nombre', 'c.ColegiaturaNumero as numero', 'c.ColegiaturaFechaVencimiento as vence']);

        return $this->avisarUnaVez('ALERTA_COLEGIATURA', $casos->map(fn ($c) => [
            'titulo' => 'Colegiatura vencida: '.$this->limpio($c->nombre),
            'mensaje' => "La colegiatura N° {$c->numero} venció el ".substr((string) $c->vence, 0, 10).': la habilitación debe estar vigente (RIT, Art. 84.37).',
        ])->all(), '/personal/trabajadores');
    }

    /** Personal que cumple 70 anios este anio: cesa el 31 de diciembre (RIT, Art. 129). */
    public function detectarLimiteDeEdad(): int
    {
        $hoy = HoraLocal::hoy();
        $casos = DB::table('Personal.Trabajador as t')->join('Personal.VinculoLaboral as v', 'v.TrabajadorId', '=', 't.TrabajadorId')
            ->where('v.VinculoLaboralEstado', 1)->whereNull('v.VinculoLaboralFechaFin')->whereYear('t.TrabajadorFechaNacimiento', '<=', $hoy->year - 70)
            ->distinct()->get(['t.TrabajadorNombreCompleto as nombre', 't.TrabajadorFechaNacimiento as nacimiento']);

        return $this->avisarUnaVez('ALERTA_LIMITE_EDAD', $casos->map(fn ($c) => [
            'titulo' => 'Límite de edad: '.$this->limpio($c->nombre),
            'mensaje' => 'Cumple 70 años en '.$hoy->year.': puede continuar hasta el 31 de diciembre de ese año (RIT, Art. 129).',
        ])->all(), '/personal/trabajadores');
    }

    /** Quien no volvio tras su licencia, vacaciones o descanso medico: inasistencia al dia siguiente (RIT, Art. 28 c). */
    public function detectarNoReincorporacion(): int
    {
        $hoy = HoraLocal::hoy();
        $ayer = $hoy->subDays(5)->toDateString();
        $origen = DB::table('Solicitudes.Licencia')->where('LicenciaEstado', 'APROBADO')->whereDate('LicenciaFechaFin', '>=', $ayer)->whereDate('LicenciaFechaFin', '<', $hoy->toDateString())->select('VinculoLaboralId as vinculo', 'LicenciaFechaFin as fin')
            ->unionAll(DB::table('Solicitudes.DescansoMedico')->where('DescansoMedicoEstado', 'APROBADO')->whereDate('DescansoMedicoFechaFin', '>=', $ayer)->whereDate('DescansoMedicoFechaFin', '<', $hoy->toDateString())->select('VinculoLaboralId as vinculo', 'DescansoMedicoFechaFin as fin'))
            ->get();

        $casos = [];
        foreach ($origen as $o) {
            $siguiente = date('Y-m-d', strtotime(substr((string) $o->fin, 0, 10).' +1 day'));
            $falto = DB::table('Asistencia.AsistenciaDiaria as a')->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
                ->where('a.VinculoLaboralId', $o->vinculo)->whereDate('a.AsistenciaDiariaFecha', $siguiente)->whereIn('e.EstadoAsistenciaCodigo', ['FALTA', 'FALTA_GUARDIA'])->exists();
            if ($falto) {
                $nombre = DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')->where('v.VinculoLaboralId', $o->vinculo)->value('t.TrabajadorNombreCompleto');
                $casos[] = ['titulo' => 'No se reincorporó: '.$this->limpio($nombre), 'mensaje' => "Terminó su ausencia autorizada y faltó el {$siguiente} (RIT, Art. 28)."];
            }
        }

        return $this->avisarUnaVez('ALERTA_NO_REINCORPORACION', $casos, '/asistencia/asistencia-diaria');
    }

    /** Fraccionamiento sin respuesta en 3 dias habiles: se considera aprobado (RIT, Art. 73). */
    public function aprobarFraccionamientosPorSilencio(): int
    {
        $aprobados = 0;
        foreach (GoceVacacional::query()->where('GoceVacacionalEstado', 'PENDIENTE')->where('GoceVacacionalDias', '<', 7)->get() as $goce) {
            if (DiasHabiles::transcurridosDesde($goce->GoceVacacionalFechaSolicitud->toDateString()) > 3) {
                app(GoceVacacionalService::class)->aprobar($goce, 0, 'Aprobado por silencio: sin respuesta en 3 días hábiles (RIT, Art. 73).');
                $aprobados++;
            }
        }

        return $aprobados;
    }

    /** Dias no laborables compensables: la compensacion se programa sola, hasta 15 dias desde la semana siguiente (RIT, Art. 18). */
    public function programarCompensacionDeDiasNoLaborables(): int
    {
        $hoy = HoraLocal::hoy();
        $tipo = DB::table('Compensaciones.TipoCompensacion')->where('TipoCompensacionCodigo', 'DIA_NO_LABORABLE')->value('TipoCompensacionId');
        if ($tipo === null) {
            return 0;
        }
        $creadas = 0;
        foreach (DB::table('Soporte.CalendarioNoLaborable')->where('CalendarioNoLaborableEstado', 1)->where('CalendarioNoLaborableCompensable', 1)->whereDate('CalendarioNoLaborableFecha', '<=', $hoy->toDateString())->whereDate('CalendarioNoLaborableFecha', '>=', $hoy->subDays(30)->toDateString())->get() as $dia) {
            $fecha = substr((string) $dia->CalendarioNoLaborableFecha, 0, 10);
            $nota = "Compensación del día no laborable {$fecha} (RIT, Art. 18).";
            $limite = CarbonImmutable::parse($fecha)->next(CarbonImmutable::MONDAY)->addDays(15)->toDateString();
            $vinculos = DB::table('Personal.AsignacionHorario as ah')->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'ah.VinculoLaboralId')
                ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')->where('ah.AsignacionHorarioEstado', 1)->where('v.VinculoLaboralEstado', 1)
                ->when($dia->MicroredId, fn ($q, $m) => $q->where('e.MicroredId', $m))->distinct()->pluck('v.VinculoLaboralId');
            foreach ($vinculos as $vinculo) {
                $ya = DB::table('Compensaciones.CompensacionHoraria')->where('VinculoLaboralId', $vinculo)->where('TipoCompensacionId', $tipo)->where('CompensacionHorariaObservacion', $nota)->exists();
                if (! $ya) {
                    DB::table('Compensaciones.CompensacionHoraria')->insert(['VinculoLaboralId' => $vinculo, 'TipoCompensacionId' => $tipo, 'CompensacionHorariaFechaGeneracion' => $hoy->toDateTimeString(), 'CompensacionHorariaHorasGeneradas' => 8,
                        'CompensacionHorariaHorasDevueltas' => 0, 'CompensacionHorariaFechaLimite' => $limite, 'CompensacionHorariaAutorizadoPreviamente' => 1, 'CompensacionHorariaObservacion' => $nota, 'CompensacionHorariaEstado' => 'APROBADO']);
                    $creadas++;
                }
            }
        }

        return $creadas;
    }

    /** @param  list<array{titulo:string,mensaje:string}>  $casos */
    private function avisarUnaVez(string $tipo, array $casos, string $enlace): int
    {
        $creados = 0;
        foreach ($this->destinatarios() as $usuarioId) {
            foreach ($casos as $caso) {
                $ya = DB::table('Soporte.Notificacion')->where(['UsuarioId' => $usuarioId, 'NotificacionTipo' => $tipo, 'NotificacionTitulo' => $caso['titulo'], 'NotificacionMensaje' => $caso['mensaje']])->exists();
                if (! $ya) {
                    $this->notificaciones->notificar($usuarioId, $tipo, $caso['titulo'], $caso['mensaje'], $enlace);
                    $creados++;
                }
            }
        }

        return $creados;
    }

    private function limpio(?string $nombre): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $nombre)));
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
