<?php

namespace Database\Seeders\Concerns;

use App\Models\Programacion\ProgramacionTrabajador;
use App\Services\CalculoAsistenciaService;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Expansion de volumen, parte 2: lo que ocurre en la Red entre julio y el 20 de octubre de 2026. Cada trabajador tiene su
 * programacion mensual de turnos (o su horario fijo), sus vacaciones, licencias, descansos medicos y papeletas, y marca
 * entrada y salida cada dia con la variacion normal de una operacion real (tardanzas, omisiones, faltas, salidas
 * anticipadas, sobretiempo). La asistencia diaria NO se escribe a mano: se calcula con el mismo motor que usa el sistema
 * (CalculoAsistenciaService), de modo que los estados salen de las reglas del RIT y no de una lista de ejemplo.
 *
 * Usa VolumenBase y VolumenPersonal (necesita $this->dotacion).
 */
trait VolumenAsistencia
{
    /** @var array<int,array<string,array{turno:string,guardia:bool}>> turnos esperados: vinculo => fecha => turno */
    protected array $turnosPlan = [];

    /** @var array<int,array<string,string>> dias cubiertos por una solicitud aprobada: vinculo => fecha => tipo */
    protected array $cobertura = [];

    /** @var list<array{vinculo:int,fecha:string,tipo:string}> dias sin marca completa, candidatos a justificarse */
    protected array $faltasPlan = [];

    /** @var list<array{vinculo:int,fecha:string,minutos:int}> dias en que el trabajador se quedo despues de su hora */
    protected array $sobretiempoPlan = [];

    /** @var array<string,list<string>> horas de entrada y salida de cada turno */
    private const HORAS_TURNO = [
        'M' => ['07:30', '13:30'], 'T' => ['13:30', '19:30'], 'N' => ['19:30', '07:30'], 'G12-D' => ['07:30', '19:30'], 'ADM-D' => ['07:30', '15:30'],
    ];

    private const PERIODOS_VOLUMEN = [
        ['2026-07-01', '2026-07-31', 'CERRADA'], ['2026-08-01', '2026-08-31', 'CERRADA'], ['2026-09-01', '2026-09-30', 'CERRADA'],
        ['2026-10-01', '2026-10-31', 'PUBLICADA'], ['2026-11-01', '2026-11-30', 'BORRADOR'],
    ];

    protected function volumenAsistencia(): void
    {
        $this->volumenAsignacionesDeHorario();
        $this->volumenProgramacion();
        $this->volumenAusencias();
        $this->volumenMarcaciones();
        $this->volumenJustificaciones();
    }

    // ------------------------------------------------------------------------------------------ horarios y programacion

    private function volumenAsignacionesDeHorario(): void
    {
        $horarios = DB::table('Configuracion.Horario')->pluck('HorarioId', 'HorarioCodigo')->all();
        $filas = [];
        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'prog' || $p['modo'] === 'ninguno') {
                continue;
            }
            $inicio = max($p['inicio'], '2026-01-01');
            $filas[] = [
                'VinculoLaboralId' => $p['vinculo'], 'HorarioId' => $horarios[$p['modo']], 'AsignacionHorarioFechaInicio' => $inicio,
                'AsignacionHorarioFechaFin' => null, 'AsignacionHorarioObservacion' => null, 'AsignacionHorarioFechaRegistro' => $inicio.' 08:00:00', 'AsignacionHorarioEstado' => 1,
            ];
        }
        $this->insertarPorLotes('Personal.AsignacionHorario', $filas);
    }

    /** Cuando el trabajador hace guardias: personal D.L. 276 asistencial de los C.S. con emergencia (RIT, Art. 20). */
    private function hacerGuardias(array $p): bool
    {
        return $p['modo'] === 'prog' && $p['regimen'] === 'DL276' && in_array($p['eess'], ['EESS-LE-01', 'EESS-EP-01', 'EESS-VL-01'], true)
            && in_array($p['cargo'], ['Médico(a) Cirujano(a)', 'Enfermero(a)', 'Obstetra', 'Técnico(a) en Enfermería'], true);
    }

    /**
     * Turno que cumple el trabajador cada dia de un mes (null = libre): rotacion semanal de manana y tarde, algunos dias
     * libres, el sabado a medias, y dos guardias de 12 horas al mes con su descanso al dia siguiente. Nunca pasa de 150 horas.
     *
     * @return array<string,array{turno:string,guardia:bool}>
     */
    private function turnosDelMes(array $p, CarbonImmutable $primero, int $dias): array
    {
        $ym = $primero->format('Y-m');
        $fijoManana = in_array($p['cargo'], ['Médico(a) Cirujano(a)', 'Odontólogo(a)', 'Nutricionista', 'Psicólogo(a)', 'Químico(a) Farmacéutico(a)', 'Técnico(a) de Laboratorio'], true);

        $guardias = [];
        if ($this->hacerGuardias($p)) {
            $d1 = $this->azarEntre("{$p['n']}|{$ym}|g1", 2, 14);
            $d2 = $this->azarEntre("{$p['n']}|{$ym}|g2", 18, min(27, $dias - 1));
            foreach ([$d1, $d2] as $k => $dia) {
                $guardias[$dia] = $this->azar("{$p['n']}|{$ym}|gt{$k}") < 50 ? 'N' : 'G12-D';
            }
        }

        $mes = [];
        $libreTrasGuardia = [];
        for ($d = 1; $d <= $dias; $d++) {
            $fecha = $primero->addDays($d - 1);
            $f = $fecha->toDateString();
            if (isset($guardias[$d])) {
                $mes[$f] = ['turno' => $guardias[$d], 'guardia' => true];
                $libreTrasGuardia[$d + 1] = true;

                continue;
            }
            if (isset($libreTrasGuardia[$d]) || $fecha->dayOfWeekIso === 7) {
                continue;
            }
            $librePor = $fecha->dayOfWeekIso === 6 ? 45 : 8;
            if ($this->azar("{$p['n']}|{$f}|libre") < $librePor) {
                continue;
            }
            $manana = $fijoManana ? $this->azar("{$p['n']}|{$f}|mt") < 88 : (($fecha->isoWeek + $p['n']) % 2 === 0) !== ($this->azar("{$p['n']}|{$f}|flip") < 12);
            $mes[$f] = ['turno' => $manana ? 'M' : 'T', 'guardia' => false];
        }

        // Tope de 150 horas mensuales (RIT, Art. 16): se quitan los ultimos turnos ordinarios que sobren.
        $horas = fn (array $turnos) => array_sum(array_map(fn ($t) => $t['guardia'] ? 12 : 6, $turnos));
        while ($horas($mes) > 150) {
            $ordinarios = array_filter($mes, fn ($t) => ! $t['guardia']);
            if ($ordinarios === []) {
                break;
            }
            unset($mes[array_key_last($ordinarios)]);
        }

        return $mes;
    }

    private function volumenProgramacion(): void
    {
        $tipoMensual = (int) DB::table('Programacion.TipoPeriodoProgramacion')->where('TipoPeriodoProgramacionCodigo', 'MENSUAL')->value('TipoPeriodoProgramacionId');
        $usuario = $this->usuarioId('rvargas');
        $turnoIds = DB::table('Configuracion.Turno')->pluck('TurnoId', 'TurnoCodigo')->all();

        // Un periodo mensual por establecimiento y mes (se reusa el que ya existia, p. ej. setiembre y octubre de La Esperanza).
        $periodos = [];
        $eessConProgramacion = collect($this->dotacion)->where('modo', 'prog')->pluck('eess_id', 'eess')->all();
        foreach ($eessConProgramacion as $codigo => $eessId) {
            foreach (self::PERIODOS_VOLUMEN as [$inicio, $fin, $estado]) {
                $existente = DB::table('Programacion.ProgramacionPeriodo')->where(['EessId' => $eessId, 'TipoPeriodoProgramacionId' => $tipoMensual])->whereDate('ProgramacionPeriodoFechaInicio', $inicio)->first();
                if ($existente) {
                    $periodos[$eessId][$inicio] = ['id' => (int) $existente->ProgramacionPeriodoId, 'estado' => $existente->ProgramacionPeriodoEstado];

                    continue;
                }
                $abreviatura = str_replace(['EESS-', '-'], '', $codigo);
                $id = DB::table('Programacion.ProgramacionPeriodo')->insertGetId([
                    'EessId' => $eessId, 'TipoPeriodoProgramacionId' => $tipoMensual, 'UsuarioRegistroId' => $usuario,
                    'ProgramacionPeriodoCodigo' => 'PGM-'.$abreviatura.'-'.substr($inicio, 0, 7),
                    'ProgramacionPeriodoAnio' => 2026, 'ProgramacionPeriodoMes' => (int) substr($inicio, 5, 2), 'ProgramacionPeriodoNumero' => null,
                    'ProgramacionPeriodoFechaInicio' => $inicio, 'ProgramacionPeriodoFechaFin' => $fin,
                    'ProgramacionPeriodoObservacion' => $estado === 'BORRADOR' ? 'Se sigue armando: faltan los turnos de la segunda quincena' : null,
                    'ProgramacionPeriodoFechaRegistro' => date('Y-m-d H:i:s', strtotime($inicio.' 08:00:00 -10 days')),
                    'ProgramacionPeriodoFechaPublicacion' => $estado === 'BORRADOR' ? null : date('Y-m-d H:i:s', strtotime($inicio.' 10:00:00 -4 days')),
                    'ProgramacionPeriodoEstado' => $estado,
                ], 'ProgramacionPeriodoId');
                $periodos[$eessId][$inicio] = ['id' => (int) $id, 'estado' => $estado];
            }
        }

        // Programacion de cada trabajador, mes por mes.
        $filasPt = [];
        foreach ($this->dotacion as $p) {
            if ($p['modo'] !== 'prog') {
                continue;
            }
            foreach (self::PERIODOS_VOLUMEN as [$inicio, $fin]) {
                if ($p['inicio'] > $fin) {
                    continue;
                }
                $periodo = $periodos[$p['eess_id']][$inicio];
                $filasPt[] = ['ProgramacionPeriodoId' => $periodo['id'], 'VinculoLaboralId' => $p['vinculo'], 'ProgramacionTrabajadorObservacion' => null, 'ProgramacionTrabajadorEstado' => $periodo['estado'] === 'CERRADA' ? 'CERRADA' : ($periodo['estado'] === 'PUBLICADA' ? 'PUBLICADA' : 'BORRADOR')];
            }
        }
        $this->insertarPorLotes('Programacion.ProgramacionTrabajador', $filasPt);

        $idsPeriodos = collect($periodos)->flatten(1)->pluck('id')->all();
        $ptPorClave = [];
        foreach (array_chunk($idsPeriodos, 500) as $parte) {
            foreach (DB::table('Programacion.ProgramacionTrabajador')->whereIn('ProgramacionPeriodoId', $parte)->get(['ProgramacionTrabajadorId', 'ProgramacionPeriodoId', 'VinculoLaboralId']) as $pt) {
                $ptPorClave[$pt->ProgramacionPeriodoId.'|'.$pt->VinculoLaboralId] = (int) $pt->ProgramacionTrabajadorId;
            }
        }

        $filasTurnos = [];
        $ptNuevos = [];
        foreach ($this->dotacion as $p) {
            if ($p['modo'] !== 'prog') {
                continue;
            }
            foreach (self::PERIODOS_VOLUMEN as [$inicio, $fin, $estadoPeriodo]) {
                if ($p['inicio'] > $fin) {
                    continue;
                }
                $primero = CarbonImmutable::parse($inicio);
                $periodo = $periodos[$p['eess_id']][$inicio];
                $pt = $ptPorClave[$periodo['id'].'|'.$p['vinculo']];
                $ptNuevos[] = $pt;
                $turnos = $this->turnosDelMes($p, $primero, $primero->daysInMonth);
                foreach ($turnos as $fecha => $t) {
                    if ($periodo['estado'] === 'BORRADOR' && $fecha > '2026-11-15') {
                        continue;   // el borrador de noviembre solo llega a la primera quincena
                    }
                    $cumplido = $periodo['estado'] !== 'BORRADOR' && $fecha <= self::VOLUMEN_HASTA;
                    $filasTurnos[] = [
                        'ProgramacionTrabajadorId' => $pt, 'TurnoId' => $turnoIds[$t['turno']], 'TurnoProgramadoFecha' => $fecha,
                        'TurnoProgramadoEsGuardia' => $t['guardia'] ? 1 : 0, 'TurnoProgramadoEstado' => $cumplido ? 'CUMPLIDO' : 'PROGRAMADO',
                    ];
                    if ($periodo['estado'] !== 'BORRADOR') {
                        $this->turnosPlan[$p['vinculo']][$fecha] = $t;
                    }
                }
            }
        }
        $this->insertarPorLotes('Programacion.TurnoProgramado', $filasTurnos);
        ProgramacionTrabajador::query()->whereIn('ProgramacionTrabajadorId', $ptNuevos)->get()->each->recalcularHoras();

        // Quienes trabajan con horario fijo tambien tienen turno esperado cada dia laborable (el motor lo toma de su horario).
        $inicio = CarbonImmutable::parse(self::VOLUMEN_DESDE);
        $dias = (int) $inicio->diffInDays(CarbonImmutable::parse(self::VOLUMEN_HASTA)) + 1;
        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'prog' || $p['modo'] === 'ninguno') {
                continue;
            }
            for ($d = 0; $d < $dias; $d++) {
                $fecha = $inicio->addDays($d);
                $laborable = $p['modo'] === 'HOR-ESS-M' ? $fecha->dayOfWeekIso <= 6 : $fecha->dayOfWeekIso <= 5;
                if ($laborable && $fecha->toDateString() >= $p['inicio']) {
                    $this->turnosPlan[$p['vinculo']][$fecha->toDateString()] = ['turno' => $p['modo'] === 'HOR-ESS-M' ? 'M' : 'ADM-D', 'guardia' => false];
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------------ ausencias aprobadas

    /** Marca los dias como cubiertos; false si alguno ya lo estaba (no se superponen dos ausencias). */
    private function ocupar(int $vinculo, string $inicio, int $dias, string $tipo): bool
    {
        for ($d = 0; $d < $dias; $d++) {
            if (isset($this->cobertura[$vinculo][date('Y-m-d', strtotime("{$inicio} +{$d} days"))])) {
                return false;
            }
        }
        for ($d = 0; $d < $dias; $d++) {
            $this->cobertura[$vinculo][date('Y-m-d', strtotime("{$inicio} +{$d} days"))] = $tipo;
        }

        return true;
    }

    /** Un lunes de la ventana de simulacion (entre las semanas $desde y $hasta contadas desde el 6 de julio). */
    private function lunesDe(string $clave, int $desdeSemana, int $hastaSemana): string
    {
        return date('Y-m-d', strtotime('2026-07-06 +'.($this->azarEntre($clave, $desdeSemana, $hastaSemana) * 7).' days'));
    }

    private function volumenAusencias(): void
    {
        $this->volumenVacaciones();
        $this->volumenLicencias();
        $this->volumenDescansosMedicos();
        $this->volumenPapeletas();
    }

    /**
     * Periodo vacacional de 30 dias (RIT, Art. 68) para quien ya cumplio el anio de servicios, con sus descansos programados
     * (tramos de 7 dias o mas, Art. 72): los de julio a setiembre ya gozados y aprobados, otros programados para fin de anio.
     */
    private function volumenVacaciones(): void
    {
        $periodos = [];
        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'ninguno' || $p['inicio'] > '2024-12-31' || in_array($p['condicion'], ['SERUMS_REM', 'SERUMS_EQUIV', 'DESTACADO'], true)) {
                continue;
            }
            $aniversario = '2025-'.substr($p['inicio'], 5, 5);
            if (substr($aniversario, 5) === '02-29') {
                $aniversario = '2025-02-28';
            }
            $periodos[] = [$p, $aniversario];
        }

        $filas = array_map(fn (array $x) => [
            'VinculoLaboralId' => $x[0]['vinculo'], 'PeriodoVacacionalAnio' => 2025, 'PeriodoVacacionalFechaInicio' => $x[1],
            'PeriodoVacacionalFechaFin' => date('Y-m-d', strtotime($x[1].' +1 year -1 day')), 'PeriodoVacacionalDiasGanados' => 30,
            'PeriodoVacacionalDiasDisponibles' => 30, 'PeriodoVacacionalEstado' => 'ABIERTO',
        ], $periodos);
        $this->insertarPorLotes('Vacaciones.PeriodoVacacional', $filas);
        $periodoDe = DB::table('Vacaciones.PeriodoVacacional')->where('PeriodoVacacionalAnio', 2025)->pluck('PeriodoVacacionalId', 'VinculoLaboralId')->all();

        $roles = [];     // [vinculo, inicio, dias, estado del rol, estado del goce|null]
        $consumidos = [];
        foreach ($periodos as [$p]) {
            $r = $this->azar("{$p['n']}|vac");
            $primero = 0;
            if ($r < 30) {
                $dias = $this->elegir("{$p['n']}|vacd", [7, 10, 15, 15]);
                $inicio = $this->lunesDe("{$p['n']}|vacl", 0, 10);
                if ($this->ocupar($p['vinculo'], $inicio, $dias, 'vacaciones')) {
                    $roles[] = [$p['vinculo'], $inicio, $dias, 'GOZADO', 'APROBADO'];
                    $consumidos[$p['vinculo']] = $dias;
                    $primero = $dias;
                }
                if ($this->azar("{$p['n']}|vac2") < 45) {
                    $segundo = min(15, 30 - $primero);
                    $roles[] = [$p['vinculo'], date('Y-m-d', strtotime('2026-11-02 +'.($this->azarEntre("{$p['n']}|vac2l", 0, 6) * 7).' days')), $segundo, 'PROGRAMADO', $this->azar("{$p['n']}|vac2g") < 30 ? 'PENDIENTE' : null];
                }
            } elseif ($r < 42) {
                $roles[] = [$p['vinculo'], date('Y-m-d', strtotime('2026-11-02 +'.($this->azarEntre("{$p['n']}|vac3l", 0, 6) * 7).' days')), 15, 'PROGRAMADO', null];
            }
        }

        foreach ($roles as [$vinculo, $inicio, $dias, $estadoRol]) {
            DB::table('Vacaciones.RolVacacional')->insert([
                'PeriodoVacacionalId' => $periodoDe[$vinculo], 'RolVacacionalFechaProgramada' => $inicio,
                'RolVacacionalFechaFinProgramada' => date('Y-m-d', strtotime("{$inicio} +".($dias - 1).' days')), 'RolVacacionalDias' => $dias,
                'RolVacacionalEstado' => $estadoRol,
            ]);
        }
        $rolPorClave = DB::table('Vacaciones.RolVacacional as r')->join('Vacaciones.PeriodoVacacional as p', 'p.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
            ->where('p.PeriodoVacacionalAnio', 2025)->get(['r.RolVacacionalId', 'p.VinculoLaboralId', 'r.RolVacacionalFechaProgramada'])
            ->mapWithKeys(fn ($r) => [$r->VinculoLaboralId.'|'.substr((string) $r->RolVacacionalFechaProgramada, 0, 10) => (int) $r->RolVacacionalId])->all();
        foreach ($roles as [$vinculo, $inicio, $dias, , $estadoGoce]) {
            if ($estadoGoce === null) {
                continue;
            }
            DB::table('Vacaciones.GoceVacacional')->insert([
                'RolVacacionalId' => $rolPorClave[$vinculo.'|'.$inicio], 'DocumentoSustentoId' => null, 'GoceVacacionalFechaInicio' => $inicio,
                'GoceVacacionalFechaFin' => date('Y-m-d', strtotime("{$inicio} +".($dias - 1).' days')), 'GoceVacacionalDias' => $dias, 'GoceVacacionalEstado' => $estadoGoce,
            ]);
        }
        foreach ($consumidos as $vinculo => $dias) {
            DB::table('Vacaciones.PeriodoVacacional')->where('PeriodoVacacionalId', $periodoDe[$vinculo])->update(['PeriodoVacacionalDiasDisponibles' => 30 - $dias]);
        }
    }

    private function volumenLicencias(): void
    {
        $tipos = DB::table('Solicitudes.TipoLicencia')->pluck('TipoLicenciaId', 'TipoLicenciaCodigo')->all();
        $usuarioRegistra = $this->usuarioId('rvargas');
        $filas = [];
        $docs = 0;
        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'ninguno') {
                continue;
            }
            $maternidad = $p['sexo'] === 'F' && $p['n'] % 53 === 0;
            if (! $maternidad && $this->azar("{$p['n']}|lic") >= 6) {
                continue;
            }
            if ($maternidad) {
                $tipo = 'MATERNIDAD';
                $inicio = '2026-06-15';
                $dias = 98;
            } else {
                $tipo = $this->elegir("{$p['n']}|lict", $p['sexo'] === 'M' ? ['FAMILIAR', 'FALLECIMIENTO', 'ENFERMEDAD', 'CAPACITACION', 'SIN_GOCE', 'PATERNIDAD', 'ESTUDIOS'] : ['FAMILIAR', 'FALLECIMIENTO', 'ENFERMEDAD', 'CAPACITACION', 'SIN_GOCE', 'ESTUDIOS']);
                $dias = match ($tipo) {
                    'FAMILIAR' => $this->azarEntre("{$p['n']}|licd", 3, 7),
                    'FALLECIMIENTO' => 5,
                    'ENFERMEDAD' => $this->azarEntre("{$p['n']}|licd", 3, 10),
                    'CAPACITACION' => $this->azarEntre("{$p['n']}|licd", 3, 5),
                    'SIN_GOCE' => $this->azarEntre("{$p['n']}|licd", 5, 15),
                    'PATERNIDAD' => 10,
                    default => $this->azarEntre("{$p['n']}|licd", 10, 20),
                };
                $inicio = date('Y-m-d', strtotime($this->lunesDe("{$p['n']}|licl", 1, 12).' +'.$this->azarEntre("{$p['n']}|licdia", 0, 3).' days'));
            }
            $estado = $maternidad ? 'APROBADO' : $this->porcentaje("{$p['n']}|lice", ['APROBADO' => 78, 'PENDIENTE' => 10, 'RECHAZADO' => 6, 'ANULADO' => 6]);
            if ($estado === 'APROBADO' && ! $this->ocupar($p['vinculo'], $inicio, $dias, 'licencia')) {
                $estado = 'RECHAZADO';   // se superponia con otra ausencia aprobada
            }
            $fin = date('Y-m-d', strtotime("{$inicio} +".($dias - 1).' days'));
            $filas[] = [
                'VinculoLaboralId' => $p['vinculo'], 'TipoLicenciaId' => $tipos[$tipo],
                'DocumentoSustentoId' => in_array($tipo, ['ENFERMEDAD', 'FAMILIAR', 'MATERNIDAD', 'CAPACITACION'], true) ? $this->documento($tipo === 'CAPACITACION' ? 'constancia' : 'certificado-medico', 600 + ++$docs, $inicio) : null,
                'UsuarioRegistroId' => $usuarioRegistra,
                'LicenciaNumeroResolucion' => $estado === 'APROBADO' ? sprintf('RD-%04d-2026', 500 + $p['n']) : null,
                'LicenciaFechaInicio' => $inicio, 'LicenciaFechaFin' => $fin,
                'LicenciaMotivo' => match ($tipo) {
                    'MATERNIDAD' => 'Descanso pre y postnatal (RIT, Art. 34)', 'PATERNIDAD' => 'Nacimiento de su hijo: 10 días corridos (RIT, Art. 35)',
                    'FALLECIMIENTO' => 'Fallecimiento de familiar directo', 'FAMILIAR' => 'Enfermedad grave de familiar directo', 'ENFERMEDAD' => 'Descanso por enfermedad comprobada',
                    'CAPACITACION' => 'Capacitación autorizada por la Red', 'SIN_GOCE' => 'Motivos particulares', default => 'Estudios de posgrado',
                },
                'LicenciaFechaRegistro' => date('Y-m-d H:i:s', strtotime($inicio.' 09:00:00 -'.$this->azarEntre("{$p['n']}|licr", 2, 12).' days')),
                'LicenciaEstado' => $estado,
            ];
        }
        $this->insertarPorLotes('Solicitudes.Licencia', $filas);
    }

    private function volumenDescansosMedicos(): void
    {
        $filas = [];
        $docs = 0;
        $diagnosticos = ['Lumbalgia aguda', 'Infección respiratoria aguda', 'Gastroenteritis', 'Faringoamigdalitis', 'Cefalea migrañosa', 'Esguince de tobillo', 'Lumbociatalgia', 'Conjuntivitis aguda', 'Síndrome gripal'];
        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'ninguno' || $this->azar("{$p['n']}|dm") >= 9) {
                continue;
            }
            $dias = $this->azarEntre("{$p['n']}|dmd", 2, 7);
            $inicio = date('Y-m-d', strtotime($this->lunesDe("{$p['n']}|dml", 0, 12).' +'.$this->azarEntre("{$p['n']}|dmdia", 0, 3).' days'));
            $estado = $this->porcentaje("{$p['n']}|dme", ['APROBADO' => 75, 'PENDIENTE' => 10, 'RECHAZADO' => 8, 'ANULADO' => 7]);
            if ($estado === 'APROBADO' && ! $this->ocupar($p['vinculo'], $inicio, $dias, 'descanso')) {
                $estado = 'ANULADO';
            }
            $citt = sprintf('CITT-5%03d', $p['n']);
            $filas[] = [
                'VinculoLaboralId' => $p['vinculo'],
                'DocumentoSustentoId' => $this->azar("{$p['n']}|dmdoc") < 70 ? $this->documento('citt', 600 + ++$docs, $inicio) : null,
                'DescansoMedicoNumeroCitt' => $estado === 'RECHAZADO' ? null : $citt,
                'DescansoMedicoDiagnostico' => $this->elegir("{$p['n']}|dmdx", $diagnosticos),
                'DescansoMedicoFechaInicio' => $inicio,
                'DescansoMedicoFechaFin' => date('Y-m-d', strtotime("{$inicio} +".($dias - 1).' days')),
                'DescansoMedicoObservacion' => $estado === 'RECHAZADO' ? 'Sin CITT ni certificado: no se pudo sustentar' : ($estado === 'ANULADO' ? 'Registrado por error' : null),
                'DescansoMedicoFechaRegistro' => $inicio.' 08:00:00',
                'DescansoMedicoEstado' => $estado,
            ];
        }
        $this->insertarPorLotes('Solicitudes.DescansoMedico', $filas);

        // Constatacion domiciliaria de los descansos aprobados de 3 dias o mas (RIT, Art. 23 d).
        $descansos = DB::table('Solicitudes.DescansoMedico as d')->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'd.VinculoLaboralId')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->where('d.DescansoMedicoEstado', 'APROBADO')->where('v.VinculoLaboralCodigo', 'like', 'VL-1%')
            ->get(['d.DescansoMedicoId', 'd.VinculoLaboralId', 'd.DescansoMedicoFechaInicio', 'd.DescansoMedicoFechaFin', 't.TrabajadorDireccion']);
        $constataciones = [];
        foreach ($descansos as $d) {
            $inicio = substr((string) $d->DescansoMedicoFechaInicio, 0, 10);
            $dias = (strtotime(substr((string) $d->DescansoMedicoFechaFin, 0, 10)) - strtotime($inicio)) / 86400 + 1;
            if ($dias < 3 || $this->azar("{$d->DescansoMedicoId}|cons") >= 50) {
                continue;
            }
            $estado = $this->porcentaje("{$d->DescansoMedicoId}|conse", ['CONFORME' => 60, 'NO_CONFORME' => 15, 'PENDIENTE' => 20, 'ANULADO' => 5]);
            $constataciones[] = [
                'VinculoLaboralId' => (int) $d->VinculoLaboralId, 'DescansoMedicoId' => (int) $d->DescansoMedicoId, 'UsuarioRegistroId' => $this->usuarioId('gdangelo'),
                'ConstatacionDomiciliariaFecha' => date('Y-m-d', strtotime("{$inicio} +1 day")), 'ConstatacionDomiciliariaDireccion' => $d->TrabajadorDireccion,
                'ConstatacionDomiciliariaResultado' => match ($estado) {
                    'CONFORME' => 'Se encontró al servidor en su domicilio, en reposo', 'NO_CONFORME' => 'No se encontró al servidor; los vecinos indican que salió', default => null,
                },
                'ConstatacionDomiciliariaEstado' => $estado,
            ];
        }
        $this->insertarPorLotes('Solicitudes.ConstatacionDomiciliaria', $constataciones);
    }

    /** Papeletas de salida y permisos (RIT, Art. 14 y 57 a 63): la mayoria aprobadas, con su porcentaje de pendientes, rechazadas y anuladas. */
    private function volumenPapeletas(): void
    {
        $tipos = DB::table('Solicitudes.TipoPapeleta')->pluck('TipoPapeletaId', 'TipoPapeletaCodigo')->all();
        $registra = $this->usuarioId('rvargas');
        $jefes = [];
        foreach ($this->dotacion as $p) {
            if ($p['rol'] === 'JEFE_EESS' && isset($p['usuario'])) {
                $jefes[$p['eess']] = $this->usuarioId($p['usuario']);
            }
        }
        $filas = [];
        $numero = 0;
        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'ninguno') {
                continue;
            }
            $r = $this->azar("{$p['n']}|pap");
            $cantidad = $r < 20 ? 1 : ($r < 32 ? 2 : ($r < 36 ? 3 : 0));
            for ($k = 0; $k < $cantidad; $k++) {
                $clave = "{$p['n']}|pap{$k}";
                $tipo = $this->porcentaje($clave.'t', ['COMISION' => 26, 'PERM_SALUD' => 20, 'PERM_PARTIC' => 18, 'PERM_OFICIAL' => 12, 'ONOMASTICO' => 8, 'CAPACITACION' => 9, 'ESTUDIOS' => 7]);
                // Un dia habil entre el 6 de julio y el 2 de octubre; una de cada doce es una solicitud futura (aun pendiente).
                $dia = date('Y-m-d', strtotime('2026-07-06 +'.$this->azarEntre($clave.'d', 0, 88).' days'));
                if ($this->azar($clave.'f') < 8) {
                    $dia = date('Y-m-d', strtotime('2026-10-05 +'.$this->azarEntre($clave.'fd', 0, 9).' days'));
                }
                while (date('N', strtotime($dia)) > 5) {
                    $dia = date('Y-m-d', strtotime($dia.' +1 day'));
                }
                $diaCompleto = in_array($tipo, ['ONOMASTICO', 'CAPACITACION'], true);
                $estado = $dia > self::VOLUMEN_HASTA ? 'PENDIENTE' : $this->porcentaje($clave.'e', ['APROBADO' => 68, 'PENDIENTE' => 12, 'RECHAZADO' => 10, 'ANULADO' => 10]);
                $turno = $this->turnosPlan[$p['vinculo']][$dia]['turno'] ?? 'M';
                $base = $turno === 'T' ? 15 : 9;                       // hora de salida: 09:00 en la manana, 15:00 en la tarde
                $salidaMin = $base * 60 + 30 * $this->azarEntre($clave.'h', 0, 4);
                $duracion = $tipo === 'COMISION' ? 30 * $this->azarEntre($clave.'dur', 2, 6) : 30 * $this->azarEntre($clave.'dur', 2, 5);
                $resuelve = in_array($estado, ['APROBADO', 'RECHAZADO'], true) ? ($jefes[$p['eess']] ?? $this->usuarioId('pgutierrez')) : null;
                $numero++;
                if ($estado === 'APROBADO' && $diaCompleto && ! $this->ocupar($p['vinculo'], $dia, 1, 'papeleta')) {
                    $estado = 'ANULADO';
                    $resuelve = null;
                }
                $filas[] = [
                    'VinculoLaboralId' => $p['vinculo'], 'TipoPapeletaId' => $tipos[$tipo], 'MotivoPapeletaId' => null,
                    'DocumentoSustentoId' => $tipo === 'CAPACITACION' ? $this->documento('constancia', 700 + $numero, $dia) : null,
                    'UsuarioRegistroId' => $registra, 'UsuarioAutorizacionId' => $resuelve,
                    'PapeletaNumero' => sprintf('PS-2%04d', $numero), 'PapeletaFecha' => $dia,
                    'PapeletaHoraSalida' => $diaCompleto ? null : sprintf('%02d:%02d:00', intdiv($salidaMin, 60), $salidaMin % 60),
                    'PapeletaHoraRetorno' => $diaCompleto ? null : sprintf('%02d:%02d:00', intdiv($salidaMin + $duracion, 60), ($salidaMin + $duracion) % 60),
                    'PapeletaEsDiaCompleto' => $diaCompleto ? 1 : 0, 'PapeletaMinutosUtilizados' => $diaCompleto ? null : $duracion,
                    'PapeletaMotivo' => match ($tipo) {
                        'COMISION' => 'Trámite documentario en la sede', 'PERM_SALUD' => 'Cita médica programada', 'PERM_PARTIC' => 'Trámite personal', 'PERM_OFICIAL' => 'Reunión convocada por la Dirección',
                        'ONOMASTICO' => 'Día de onomástico (RIT, Art. 44)', 'CAPACITACION' => 'Curso de actualización', default => 'Clases programadas',
                    },
                    'PapeletaObservacion' => $estado === 'RECHAZADO' ? 'No se pidió con 24 horas de anticipación' : ($estado === 'ANULADO' ? 'Registrada por error' : null),
                    'PapeletaFechaRegistro' => date('Y-m-d H:i:s', strtotime($dia.' 07:00:00 -1 day')),
                    'PapeletaFechaResolucion' => $resuelve === null ? null : $dia.' 07:20:00',
                    'PapeletaEstado' => $estado,
                ];
            }
        }
        $this->insertarPorLotes('Solicitudes.Papeleta', $filas);
    }

    // ------------------------------------------------------------------------------------------ marcaciones

    /** @return array<string,array{0:string,1:string}> fecha => [hora de ingreso, hora de salida] de cada turno, con la fecha de la salida */
    private function marcasDelTurno(string $fecha, string $turno): array
    {
        [$entrada, $salida] = self::HORAS_TURNO[$turno];
        $fechaSalida = $salida <= $entrada ? date('Y-m-d', strtotime($fecha.' +1 day')) : $fecha;

        return ["{$fecha} {$entrada}:00", "{$fechaSalida} {$salida}:00"];
    }

    private function volumenMarcaciones(): void
    {
        // Las marcas se arman como hora local de Lima convertida con strtotime: "ahora" se compara en la misma escala.
        $ahora = strtotime(HoraLocal::ahora()->toDateTimeString());
        $metodoRostro = (int) DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'ROSTRO')->value('MetodoMarcacionId');
        $metodoManual = (int) DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'MANUAL')->value('MetodoMarcacionId');
        $dispositivos = DB::table('Biometria.DispositivoMarcacion')->where('DispositivoMarcacionEstado', 1)->whereNotNull('EessId')->orderBy('DispositivoMarcacionId')->get(['DispositivoMarcacionId', 'EessId'])->groupBy('EessId');
        $plantillas = DB::table('Biometria.PlantillaBiometrica')->where('PlantillaBiometricaEstado', 1)->where('PlantillaBiometricaTipo', 'ROSTRO')->pluck('PlantillaBiometricaId', 'TrabajadorId')->all();
        $feriados = DB::table('Soporte.CalendarioNoLaborable')->whereNull('MicroredId')->pluck('CalendarioNoLaborableFecha')->map(fn ($f) => substr((string) $f, 0, 10))->flip()->all();

        // Los establecimientos sin reloj registran con el parte diario: una carga por mes y establecimiento (RIT, Art. 21).
        $cargas = [];
        foreach (collect($this->dotacion)->pluck('eess_id', 'eess')->all() as $codigo => $eessId) {
            if (isset($dispositivos[$eessId])) {
                continue;
            }
            foreach (['2026-07', '2026-08', '2026-09', '2026-10'] as $mes) {
                $cargas[$eessId][$mes] = (int) DB::table('Asistencia.CargaAsistenciaManual')->insertGetId([
                    'UsuarioId' => $this->usuarioId($mes === '2026-10' ? 'gdangelo' : 'rvargas'), 'EessId' => $eessId, 'DocumentoSustentoId' => null,
                    'CargaAsistenciaManualFecha' => date('Y-m-d', strtotime($mes.'-01 +1 month')).' 09:00:00',
                    'CargaAsistenciaManualNombreArchivo' => 'parte-diario-'.strtolower(str_replace('EESS-', '', $codigo)).'-'.substr($mes, 5).'.xlsx',
                    'CargaAsistenciaManualRegistros' => null,
                    'CargaAsistenciaManualObservacion' => 'Parte diario del establecimiento (sin reloj biométrico)',
                    'CargaAsistenciaManualEstado' => $mes === '2026-10' ? 'REGISTRADO' : 'PROCESADO',
                ], 'CargaAsistenciaManualId');
            }
        }

        $filas = [];
        $conteoCarga = [];
        $inicio = CarbonImmutable::parse(self::VOLUMEN_DESDE);
        $dias = (int) $inicio->diffInDays(CarbonImmutable::parse(self::VOLUMEN_HASTA)) + 1;
        $cuenta = 0;
        foreach ($this->dotacion as $p) {
            if ($p['modo'] === 'ninguno') {
                continue;
            }
            $lectores = $dispositivos->get($p['eess_id']);
            $dispositivo = $lectores ? (int) $lectores[$p['n'] % count($lectores)]->DispositivoMarcacionId : null;
            for ($d = 0; $d < $dias; $d++) {
                $f = $inicio->addDays($d)->toDateString();
                $plan = $this->turnosPlan[$p['vinculo']][$f] ?? null;
                if ($plan === null || isset($this->cobertura[$p['vinculo']][$f]) || ($p['modo'] !== 'prog' && isset($feriados[$f]))) {
                    continue;
                }
                $mes = substr($f, 0, 7);
                $r = $this->azar("{$p['vinculo']}|{$f}|m");
                [$horaEntrada, $horaSalida] = $this->marcasDelTurno($f, $plan['turno']);
                $entrada = strtotime($horaEntrada);
                $salida = strtotime($horaSalida);
                $jitter = fn (string $k, int $a, int $b) => $this->azarEntre("{$p['vinculo']}|{$f}|{$k}", $a, $b);

                $marcaEntrada = null;
                $marcaSalida = null;
                if ($r < 2) {                                   // no marco nada: falta
                    $this->faltasPlan[] = ['vinculo' => $p['vinculo'], 'fecha' => $f, 'tipo' => 'sin_marcas'];
                } elseif ($r === 2) {                           // olvido una de las dos marcas
                    $soloEntrada = ($p['n'] + (int) substr($f, 8)) % 2 === 0;
                    $soloEntrada ? $marcaEntrada = $entrada + 60 * $jitter('e', -5, 3) : $marcaSalida = $salida + 60 * $jitter('s', 0, 10);
                    $this->faltasPlan[] = ['vinculo' => $p['vinculo'], 'fecha' => $f, 'tipo' => 'omision'];
                } else {
                    $retraso = match (true) {
                        $r <= 7 => $jitter('t', 6, 10), $r <= 10 => $jitter('t', 11, 20), $r <= 12 => $jitter('t', 21, 30), $r === 13 => $jitter('t', 31, 55),
                        default => 0,
                    };
                    $marcaEntrada = $retraso > 0 ? $entrada + 60 * $retraso + $jitter('seg', 0, 40) : ($this->azar("{$p['vinculo']}|{$f}|tol") < 30 ? $entrada + 60 * $jitter('tol', 1, 5) : $entrada - 60 * $jitter('ant', 0, 9));
                    if ($r === 14) {                            // se retiro antes de hora sin papeleta
                        $marcaSalida = $salida - 60 * $jitter('an', 15, 70);
                    } else {
                        $queda = ! $plan['guardia'] && $this->azar("{$p['vinculo']}|{$f}|ext") < 6;
                        $marcaSalida = $salida + 60 * ($queda ? $jitter('x', 62, 110) : $jitter('sal', 0, 12));
                        if ($queda) {
                            $this->sobretiempoPlan[] = ['vinculo' => $p['vinculo'], 'fecha' => $f, 'minutos' => intdiv($marcaSalida - $salida, 60)];
                        }
                    }
                }

                $manual = $dispositivo === null;
                foreach ([['ENTRADA', $marcaEntrada], ['SALIDA', $marcaSalida]] as [$tipo, $momento]) {
                    // Ninguna marca "del futuro": lo que aun no ocurrio se marcara desde el control de asistencia.
                    if ($momento === null || $momento > $ahora) {
                        continue;
                    }
                    $cargaId = $manual ? ($cargas[$p['eess_id']][$mes] ?? null) : null;
                    $filas[] = [
                        'VinculoLaboralId' => $p['vinculo'], 'MetodoMarcacionId' => $manual ? $metodoManual : $metodoRostro, 'DispositivoMarcacionId' => $dispositivo,
                        'PlantillaBiometricaId' => $manual ? null : ($plantillas[$p['trabajador']] ?? null), 'CargaAsistenciaManualId' => $cargaId,
                        'MarcacionFechaHora' => date('Y-m-d H:i:s', $momento), 'MarcacionTipo' => $tipo, 'MarcacionGeolocalizacion' => null, 'MarcacionObservacion' => null,
                        'MarcacionOrigen' => $manual ? 'CARGA_MANUAL' : 'DISPOSITIVO', 'MarcacionEsValida' => 1,
                    ];
                    if ($cargaId) {
                        $conteoCarga[$cargaId] = ($conteoCarga[$cargaId] ?? 0) + 1;
                    }
                    if (++$cuenta % 5000 === 0) {
                        $this->insertarPorLotes('Asistencia.Marcacion', $filas);
                        $filas = [];
                    }
                }
            }
        }
        $this->insertarPorLotes('Asistencia.Marcacion', $filas);
        foreach ($conteoCarga as $carga => $registros) {
            DB::table('Asistencia.CargaAsistenciaManual')->where('CargaAsistenciaManualId', $carga)->update(['CargaAsistenciaManualRegistros' => $registros]);
        }
    }

    /**
     * Justificaciones de buena parte de las faltas: la mayoria aprobadas (el motor las deja como "falta justificada"), el resto
     * pendientes, rechazadas o anuladas. Las que exige el concepto llevan su documento de sustento.
     */
    private function volumenJustificaciones(): void
    {
        $conceptos = DB::table('Asistencia.ConceptoJustificacion')->get()->keyBy('ConceptoJustificacionCodigo');
        $porVinculo = collect($this->dotacion)->keyBy('vinculo');
        $filas = [];
        $docs = 0;
        foreach ($this->faltasPlan as $i => $falta) {
            if ($this->azar("just{$i}") >= 45) {
                continue;
            }
            $p = $porVinculo[$falta['vinculo']];
            $codigo = $falta['tipo'] === 'omision' ? $this->elegir("jc{$i}", ['OLVIDO_MARCA', 'FALLA_EQUIPO', 'OLVIDO_MARCA']) : $this->elegir("jc{$i}", ['EMERGENCIA', 'DESCANSO_MED', 'ENFERMEDAD_FAM', 'FALLA_EQUIPO', 'EMERGENCIA']);
            $estado = $falta['fecha'] > '2026-09-30' ? 'PENDIENTE' : $this->porcentaje("je{$i}", ['APROBADO' => 55, 'PENDIENTE' => 25, 'RECHAZADO' => 15, 'ANULADO' => 5]);
            $requiereDocumento = (bool) $conceptos[$codigo]->ConceptoJustificacionRequiereDocumento;
            $resuelve = in_array($estado, ['APROBADO', 'RECHAZADO'], true) ? $this->usuarioId('pgutierrez') : null;
            $filas[] = [
                'VinculoLaboralId' => $falta['vinculo'], 'ConceptoJustificacionId' => (int) $conceptos[$codigo]->ConceptoJustificacionId,
                'DocumentoSustentoId' => $requiereDocumento ? $this->documento('certificado-medico', 800 + ++$docs, $falta['fecha']) : null,
                'UsuarioRegistroId' => $this->usuarioId($p['rol'] !== null && isset($p['usuario']) ? $p['usuario'] : 'rvargas'), 'UsuarioResolucionId' => $resuelve,
                'JustificacionFaltaFechaInicio' => $falta['fecha'], 'JustificacionFaltaFechaFin' => $falta['fecha'],
                'JustificacionFaltaDocumentoNumero' => $requiereDocumento ? sprintf('CITT-6%04d', $i) : null,
                'JustificacionFaltaObservacion' => $falta['tipo'] === 'omision' ? 'Olvidó registrar una de sus marcas' : 'Presenta su justificación dentro del plazo',
                'JustificacionFaltaMotivoRechazo' => $estado === 'RECHAZADO' ? 'El sustento presentado no corresponde a la fecha de la inasistencia' : null,
                'JustificacionFaltaFechaRegistro' => date('Y-m-d H:i:s', strtotime($falta['fecha'].' 12:00:00 +1 day')),
                'JustificacionFaltaFechaResolucion' => $resuelve === null ? null : date('Y-m-d H:i:s', strtotime($falta['fecha'].' 10:00:00 +3 days')),
                'JustificacionFaltaEstado' => $estado,
            ];
        }
        $this->insertarPorLotes('Asistencia.JustificacionFalta', $filas);
    }

    // ------------------------------------------------------------------------------------------ calculo

    /**
     * Corre el motor de calculo de asistencia sobre los trabajadores de volumen (uno por uno: los casos puntuales de los otros
     * seeders no se tocan). Julio y agosto estan CERRADOS en el catalogo; se abren solo mientras se calculan y se consolidan
     * (VolumenCierre los vuelve a cerrar).
     */
    protected function volumenCalcularAsistencia(): void
    {
        DB::table('Consolidacion.PeriodoAsistencia')->where('PeriodoAsistenciaAnio', 2026)->whereIn('PeriodoAsistenciaMes', [7, 8])->update(['PeriodoAsistenciaEstado' => 'EN_PROCESO']);

        $motor = app(CalculoAsistenciaService::class);
        foreach ($this->dotacion as $p) {
            if ($p['modo'] !== 'ninguno') {
                $motor->calcular(self::VOLUMEN_DESDE, self::VOLUMEN_HASTA, $p['vinculo']);
            }
        }
    }

    /**
     * Sobretiempo autorizado (RIT, Art. 17) de parte de quienes se quedaron despues de su hora: la compensacion aprobada o
     * consumida queda ligada al dia y el motor la reconoce al recalcular; el resto de estados no suma minutos extra.
     */
    protected function volumenCompensaciones(): void
    {
        $tipos = DB::table('Compensaciones.TipoCompensacion')->pluck('TipoCompensacionId', 'TipoCompensacionCodigo')->all();
        $porVinculo = collect($this->dotacion)->keyBy('vinculo');
        $motor = app(CalculoAsistenciaService::class);
        foreach ($this->sobretiempoPlan as $i => $s) {
            if ($this->azar("comp{$i}") >= 22) {
                continue;
            }
            $asistencia = DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $s['vinculo'])->whereDate('AsistenciaDiariaFecha', $s['fecha'])->value('AsistenciaDiariaId');
            if ($asistencia === null) {
                continue;
            }
            $p = $porVinculo[$s['vinculo']];
            $estado = $this->porcentaje("compe{$i}", ['APROBADO' => 45, 'CONSUMIDO' => 25, 'PENDIENTE' => 15, 'VENCIDO' => 8, 'ANULADO' => 7]);
            $horas = $s['minutos'] >= 100 ? 2.0 : 1.0;
            $autoriza = isset($p['usuario']) ? $this->usuarioId($p['usuario']) : $this->usuarioId('pgutierrez');
            DB::table('Compensaciones.CompensacionHoraria')->insert([
                'VinculoLaboralId' => $s['vinculo'], 'TipoCompensacionId' => $tipos['HORA_EXTRA'], 'AsistenciaDiariaId' => (int) $asistencia,
                'CompensacionHorariaAutorizadoPor' => $estado === 'ANULADO' ? null : $autoriza, 'CompensacionHorariaFechaGeneracion' => $s['fecha'].' 19:00:00',
                'CompensacionHorariaHorasGeneradas' => $horas, 'CompensacionHorariaHorasDevueltas' => $estado === 'CONSUMIDO' ? $horas : 0,
                'CompensacionHorariaFechaLimite' => date('Y-m-t', strtotime($s['fecha'].' +1 month')),
                'CompensacionHorariaAutorizadoPreviamente' => $estado === 'ANULADO' ? 0 : 1,
                'CompensacionHorariaObservacion' => $estado === 'ANULADO' ? 'Registrada por error' : 'Trabajo fuera de la jornada, autorizado por el jefe inmediato',
                'CompensacionHorariaEstado' => $estado,
            ]);
            if (in_array($estado, ['APROBADO', 'CONSUMIDO'], true)) {
                $motor->calcular($s['fecha'], $s['fecha'], $s['vinculo']);
            }
        }
    }
}
