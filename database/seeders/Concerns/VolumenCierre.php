<?php

namespace Database\Seeders\Concerns;

use App\Models\Asistencia\AjusteMarcacion;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\PeriodoAsistencia;
use App\Models\Programacion\CambioTurno;
use App\Services\AjusteMarcacionService;
use App\Services\CalculoAsistenciaService;
use App\Services\CambioTurnoService;
use App\Services\ConsolidadoAsistenciaService;
use App\Services\LiquidacionDescuentoService;
use Illuminate\Support\Facades\DB;

/**
 * Expansion de volumen, parte 3: ajustes y cambios de turno, cierre de los meses (consolidado de asistencia y liquidacion de
 * descuentos) y la operacion de la Red alrededor de la asistencia: responsables, informes de guardia comunitaria, procesos
 * disciplinarios, supervisiones, cargas de programacion, porteria, notificaciones, sesiones, auditoria e integraciones.
 *
 * Todo lo que tiene efecto sobre otras tablas (aprobar un ajuste o un cambio de turno, consolidar, liquidar) pasa por los
 * mismos servicios que usa la API. Usa VolumenBase, VolumenPersonal y VolumenAsistencia.
 */
trait VolumenCierre
{
    /** Ingreso mensual de planilla (ficticio) por cargo: de ahi salen el valor del dia y de la hora de cada descuento (RIT, Art. 25). */
    private const INGRESOS = [
        'Médico(a) Cirujano(a)' => 6800, 'Enfermero(a)' => 3300, 'Obstetra' => 3300, 'Odontólogo(a)' => 3300, 'Psicólogo(a)' => 3100, 'Nutricionista' => 3100,
        'Químico(a) Farmacéutico(a)' => 3100, 'Técnico(a) en Enfermería' => 1850, 'Técnico(a) de Laboratorio' => 1850, 'Técnico(a) Administrativo(a)' => 1750,
        'Contador(a)' => 4200, 'Abogado(a)' => 4200, 'Analista de Recursos Humanos' => 3200, 'Responsable de Control de Asistencia y Permanencia' => 3500,
        'Auxiliar Administrativo(a)' => 1450,
    ];

    // ------------------------------------------------------------------------------------------ ajustes y cambios de turno

    /** Solicitudes de correccion de una marcacion de setiembre (el periodo sigue en proceso): pendientes, aprobadas, rechazadas y anuladas. */
    protected function volumenAjustesDeMarcacion(): void
    {
        $porVinculo = collect($this->dotacion)->keyBy('vinculo');
        $marcas = DB::table('Asistencia.Marcacion as m')->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'm.VinculoLaboralId')
            ->where('v.VinculoLaboralCodigo', 'like', 'VL-1%')->where('m.MarcacionTipo', 'ENTRADA')
            ->whereBetween('m.MarcacionFechaHora', ['2026-09-08 00:00:00', '2026-09-29 23:59:59'])
            ->orderBy('m.MarcacionId')->get(['m.MarcacionId', 'm.VinculoLaboralId', 'm.MarcacionFechaHora']);

        $estados = ['PENDIENTE', 'PENDIENTE', 'PENDIENTE', 'PENDIENTE', 'PENDIENTE', 'PENDIENTE', 'APROBADO', 'APROBADO', 'APROBADO', 'RECHAZADO', 'RECHAZADO', 'RECHAZADO', 'ANULADO', 'ANULADO'];
        $resolver = [];
        $paso = max(1, intdiv($marcas->count(), count($estados) + 1));
        foreach ($estados as $k => $estado) {
            $marca = $marcas[($k + 1) * $paso] ?? null;
            if ($marca === null) {
                break;
            }
            $p = $porVinculo[$marca->VinculoLaboralId];
            $anterior = substr((string) $marca->MarcacionFechaHora, 0, 19);
            $nueva = date('Y-m-d H:i:s', strtotime($anterior) - 60 * (7 + $k % 9));
            $solicita = isset($p['usuario']) ? $this->usuarioId($p['usuario']) : $this->usuarioId('rvargas');
            $id = DB::table('Asistencia.AjusteMarcacion')->insertGetId([
                'MarcacionId' => (int) $marca->MarcacionId, 'UsuarioId' => $solicita, 'DocumentoSustentoId' => null,
                'AjusteMarcacionFechaHora' => date('Y-m-d', strtotime($anterior.' +1 day')).' 09:00:00', 'AjusteMarcacionFechaHoraAnterior' => $anterior, 'AjusteMarcacionFechaHoraNueva' => $nueva,
                'AjusteMarcacionMotivo' => $this->elegir("aj{$k}", ['El reloj biométrico tenía la hora adelantada: el trabajador ingresó antes', 'Parte diario consignó una hora distinta a la constatada por el responsable', 'Lectura tardía del reloj por falla de red']),
                'AjusteMarcacionEstado' => in_array($estado, ['APROBADO', 'RECHAZADO'], true) ? 'PENDIENTE' : $estado,
            ], 'AjusteMarcacionId');
            if (in_array($estado, ['APROBADO', 'RECHAZADO'], true)) {
                $resolver[] = [$id, $estado, $marca->VinculoLaboralId, substr($anterior, 0, 10)];
            }
        }
        $servicio = app(AjusteMarcacionService::class);
        $motor = app(CalculoAsistenciaService::class);
        foreach ($resolver as [$id, $estado, $vinculo, $fecha]) {
            $ajuste = AjusteMarcacion::query()->findOrFail($id);
            if ($estado === 'APROBADO') {
                $servicio->aprobar($ajuste, $this->usuarioId('pgutierrez'), 'Constatado con el responsable de control de asistencia');
                $motor->calcular($fecha, $fecha, (int) $vinculo);   // la asistencia del dia refleja la marcacion corregida
            } else {
                $servicio->rechazar($ajuste, $this->usuarioId('pgutierrez'), 'La marcación corresponde a la hora registrada: no procede el cambio');
            }
        }
    }

    /**
     * Cambios de turno sobre la programacion publicada de octubre (RIT, Art. 16 y 20), con mas de 48 horas de anticipacion:
     * un reemplazo pendiente, una reprogramacion rechazada, una anulacion aprobada (se aplica con el servicio) y un reemplazo anulado.
     */
    protected function volumenCambiosDeTurno(): void
    {
        $tipos = DB::table('Programacion.TipoCambioTurno')->pluck('TipoCambioTurnoId', 'TipoCambioTurnoCodigo')->all();
        $turnos = DB::table('Configuracion.Turno')->pluck('TurnoId', 'TurnoCodigo')->all();
        $porEstablecimiento = collect($this->dotacion)->where('modo', 'prog')->filter(fn ($p) => in_array($p['eess'], ['EESS-LE-01', 'EESS-EP-01', 'EESS-VL-01', 'EESS-FM-01', 'EESS-EP-02', 'EESS-HU-01', 'EESS-MO-01'], true))->groupBy('eess');
        $turnoDe = fn (int $vinculo, string $desde) => DB::table('Programacion.TurnoProgramado as t')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 't.ProgramacionTrabajadorId')
            ->join('Programacion.ProgramacionPeriodo as pe', 'pe.ProgramacionPeriodoId', '=', 'pt.ProgramacionPeriodoId')
            ->where('pt.VinculoLaboralId', $vinculo)->where('pe.ProgramacionPeriodoEstado', 'PUBLICADA')->where('t.TurnoProgramadoEstado', 'PROGRAMADO')->where('t.TurnoProgramadoEsGuardia', 0)
            ->whereDate('t.TurnoProgramadoFecha', '>=', $desde)->orderBy('t.TurnoProgramadoFecha')->first(['t.TurnoProgramadoId', 't.TurnoProgramadoFecha']);

        $resolver = [];
        $k = 0;
        foreach ($porEstablecimiento as $personal) {
            $personal = $personal->values();
            if ($personal->count() < 4) {
                continue;
            }
            $enfermeria = $personal->filter(fn ($p) => $p['cargo'] === 'Enfermero(a)' || $p['cargo'] === 'Técnico(a) en Enfermería')->values();
            if ($enfermeria->count() < 3) {
                continue;
            }
            [$a, $b, $c] = [$enfermeria[0], $enfermeria[1], $enfermeria[2]];
            $solicita = fn (array $p) => isset($p['usuario']) ? $this->usuarioId($p['usuario']) : $this->usuarioId('rvargas');
            $casos = [
                // [tipo, solicitante, turno, reemplazante|null, turno nuevo|null, motivo, estado, resolucion]
                ['REEMPLAZO', $a, $turnoDe($a['vinculo'], '2026-10-08'), $b, null, 'Cita médica programada con anticipación', 'PENDIENTE', null],
                ['REPROGRAMACION', $b, $turnoDe($b['vinculo'], '2026-10-12'), null, 'T', 'Trámite personal por la mañana: pide pasar a turno tarde', 'PENDIENTE', 'rechazar'],
                ['ANULACION', $c, $turnoDe($c['vinculo'], '2026-10-14'), null, null, 'Descanso médico programado: el turno queda sin efecto', 'PENDIENTE', 'aprobar'],
                ['REEMPLAZO', $b, $turnoDe($b['vinculo'], '2026-10-19'), $c, null, 'Se desistió del cambio', 'ANULADO', null],
            ];
            foreach ($casos as [$tipo, $solicitante, $turno, $reemplazante, $nuevo, $motivo, $estado, $resolucion]) {
                if ($turno === null) {
                    continue;
                }
                $id = DB::table('Programacion.CambioTurno')->insertGetId([
                    'TipoCambioTurnoId' => $tipos[$tipo], 'TurnoProgramadoId' => (int) $turno->TurnoProgramadoId, 'TurnoProgramadoContraparteId' => null,
                    'TurnoIdNuevo' => $nuevo === null ? null : $turnos[$nuevo], 'VinculoLaboralSolicitanteId' => $solicitante['vinculo'],
                    'VinculoLaboralReemplazanteId' => $reemplazante === null ? null : $reemplazante['vinculo'],
                    'DocumentoSustentoId' => $tipo === 'ANULACION' ? $this->documento('certificado-medico', 900 + $k, '2026-10-01') : null,
                    'UsuarioRegistroId' => $solicita($solicitante), 'CambioTurnoFechaSolicitud' => '2026-10-0'.(1 + $k % 3).' 0'.(8 + $k % 2).':30:00',
                    'CambioTurnoMotivo' => $motivo, 'CambioTurnoEstado' => $estado,
                ], 'CambioTurnoId');
                if ($resolucion !== null) {
                    $resolver[] = [$id, $resolucion];
                }
                $k++;
            }
        }
        $servicio = app(CambioTurnoService::class);
        foreach ($resolver as [$id, $resolucion]) {
            $cambio = CambioTurno::query()->findOrFail($id);
            $resolucion === 'aprobar'
                ? $servicio->aprobar($cambio, $this->usuarioId('pgutierrez'), 'Autorizado por la jefatura del establecimiento')
                : $servicio->rechazar($cambio, $this->usuarioId('pgutierrez'), 'El turno tarde no está disponible ese día');
        }
    }

    // ------------------------------------------------------------------------------------------ cierre de mes

    /**
     * Consolida julio, agosto y setiembre con el servicio de la API. Julio y agosto quedan CERRADOS (con sus liquidaciones, la
     * mayoria ya remitidas a planilla); setiembre queda en proceso: conformes, observados y recien generados.
     */
    protected function volumenCierreDeMes(): void
    {
        $periodos = DB::table('Consolidacion.PeriodoAsistencia')->where('PeriodoAsistenciaAnio', 2026)->pluck('PeriodoAsistenciaId', 'PeriodoAsistenciaMes')->all();
        $servicio = app(ConsolidadoAsistenciaService::class);
        $vinculos = collect($this->dotacion)->where('modo', '!=', 'ninguno')->pluck('vinculo')->all();

        foreach ([7, 8, 9] as $mes) {
            $periodo = PeriodoAsistencia::query()->findOrFail($periodos[$mes]);
            foreach ($vinculos as $vinculo) {
                $servicio->generar($periodo, $vinculo);
            }
        }

        // Setiembre en proceso.
        foreach (ConsolidadoAsistencia::query()->where('PeriodoAsistenciaId', $periodos[9])->whereIn('VinculoLaboralId', $vinculos)->get() as $consolidado) {
            $estado = $this->porcentaje("cons{$consolidado->ConsolidadoAsistenciaId}", ['CONFORME' => 45, 'OBSERVADO' => 8, 'GENERADO' => 47]);
            if ($estado !== 'GENERADO') {
                $consolidado->update(['ConsolidadoAsistenciaEstado' => $estado]);
            }
        }

        // Julio y agosto cerrados; despues sus liquidaciones.
        foreach ([7, 8] as $mes) {
            DB::table('Consolidacion.ConsolidadoAsistencia')->where('PeriodoAsistenciaId', $periodos[$mes])->whereIn('VinculoLaboralId', $vinculos)->update(['ConsolidadoAsistenciaEstado' => 'CERRADO']);
        }
        $this->volumenLiquidaciones($periodos);
        DB::table('Consolidacion.PeriodoAsistencia')->where('PeriodoAsistenciaAnio', 2026)->whereIn('PeriodoAsistenciaMes', [7, 8])->update(['PeriodoAsistenciaEstado' => 'CERRADO']);
    }

    /**
     * Liquidacion de descuentos (RIT, Art. 25): valor del dia = ingreso / 30; valor de la hora = valor del dia / jornada (6 horas
     * en los establecimientos, 8 en las oficinas). Julio, remitido; agosto, remitido, aprobado y por completar; setiembre, algunos conformes.
     *
     * @param  array<int,int>  $periodos
     */
    private function volumenLiquidaciones(array $periodos): void
    {
        $servicio = app(LiquidacionDescuentoService::class);
        $porVinculo = collect($this->dotacion)->keyBy('vinculo');
        $conceptoFalta = (int) DB::table('Compensaciones.ConceptoDescuento')->where('ConceptoDescuentoCodigo', 'DESC_FALTA')->value('ConceptoDescuentoId');

        foreach ([[7, 'julio'], [8, 'agosto'], [9, 'setiembre']] as [$mes, $nombre]) {
            $consolidados = ConsolidadoAsistencia::query()->where('PeriodoAsistenciaId', $periodos[$mes])->whereIn('VinculoLaboralId', array_keys($porVinculo->all()))->orderBy('ConsolidadoAsistenciaId')->get();
            foreach ($consolidados as $consolidado) {
                // Se liquida lo que descuenta: inasistencias, o tardanzas que pasan de una hora en el mes (RIT, Art. 22).
                if (LiquidacionDescuentoService::motivoSiNoSeLiquida($consolidado) !== null
                    || ((float) $consolidado->ConsolidadoAsistenciaDiasFalta <= 0 && $consolidado->ConsolidadoAsistenciaMinutosTardanza <= 60)) {
                    continue;
                }
                $destino = match ($mes) {
                    7 => 'REMITIDO',
                    8 => $this->porcentaje("liq{$consolidado->ConsolidadoAsistenciaId}", ['REMITIDO' => 55, 'APROBADO' => 25, 'GENERADO' => 20]),
                    default => $this->azar("liq{$consolidado->ConsolidadoAsistenciaId}") < 35 ? 'GENERADO' : null,
                };
                if ($destino === null) {
                    continue;
                }
                $p = $porVinculo[$consolidado->VinculoLaboralId];
                $dia = (self::INGRESOS[$p['cargo']] ?? 1500) / 30;
                $hora = $dia / ($p['modo'] === 'prog' ? 6 : 8);
                $liquidacion = $servicio->generar($consolidado);
                if ($destino === 'GENERADO') {
                    continue;   // las lineas esperan el importe de planilla
                }
                foreach ($liquidacion->detalles()->get() as $linea) {
                    $valor = $linea->ConceptoDescuentoId === $conceptoFalta ? $dia : $hora;
                    $linea->update(['DetalleLiquidacionImporte' => max(0.01, round($linea->DetalleLiquidacionCantidad * $valor, 2))]);
                }
                $liquidacion = $servicio->aprobar($liquidacion->fresh());
                if ($destino === 'REMITIDO') {
                    $servicio->remitir($liquidacion);
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------------ operacion de la red

    protected function volumenOperacion(): void
    {
        $this->volumenResponsables();
        $this->volumenInformesDeGuardiaComunitaria();
        $this->volumenDisciplina();
        $this->volumenSupervisiones();
        $this->volumenCargasDeProgramacion();
        $this->volumenPorteria();
        $this->volumenSoporte();
    }

    /** Jefe, responsable de asistencia y de programacion de cada establecimiento (los vigentes que ya existian no se duplican). */
    private function volumenResponsables(): void
    {
        $tipos = DB::table('Organizacion.TipoResponsabilidad')->pluck('TipoResponsabilidadId', 'TipoResponsabilidadCodigo')->all();
        $vigentes = DB::table('Organizacion.ResponsableEess')->whereNull('ResponsableEessFechaFin')->where('ResponsableEessEstado', 1)->get(['EessId', 'TipoResponsabilidadId'])
            ->mapWithKeys(fn ($r) => [$r->EessId.'|'.$r->TipoResponsabilidadId => true])->all();
        $filas = [];
        $n = 0;
        foreach ($this->dotacion as $p) {
            if ($p['rol'] === null) {
                continue;
            }
            $tipo = $tipos[$p['rol']];
            if (isset($vigentes[$p['eess_id'].'|'.$tipo])) {
                continue;
            }
            $vigentes[$p['eess_id'].'|'.$tipo] = true;
            $n++;
            $filas[] = [
                'EessId' => $p['eess_id'], 'VinculoLaboralId' => $p['vinculo'], 'TipoResponsabilidadId' => $tipo,
                'DocumentoSustentoId' => $n % 3 === 0 ? $this->documento('resolucion', 400 + $n, '2026-01-02') : null,
                'ResponsableEessFechaInicio' => '2026-01-01', 'ResponsableEessFechaFin' => null, 'ResponsableEessDocumentoNumero' => sprintf('RD-%04d-2026', 100 + $n),
                'ResponsableEessObservacion' => null, 'ResponsableEessFechaRegistro' => '2026-01-01 08:00:00', 'ResponsableEessEstado' => 1,
            ];
        }
        // Historial: el jefe anterior de algunos establecimientos ya concluyo su designacion.
        foreach (collect($this->dotacion)->where('condicion', 'NOMBRADO')->where('rol', null)->where('cargo', 'Enfermero(a)')->take(6) as $i => $p) {
            $filas[] = [
                'EessId' => $p['eess_id'], 'VinculoLaboralId' => $p['vinculo'], 'TipoResponsabilidadId' => $tipos['JEFE_EESS'], 'DocumentoSustentoId' => null,
                'ResponsableEessFechaInicio' => '2022-01-01', 'ResponsableEessFechaFin' => '2025-12-31', 'ResponsableEessDocumentoNumero' => sprintf('RD-%04d-2022', 20 + $i),
                'ResponsableEessObservacion' => 'Jefe anterior del establecimiento', 'ResponsableEessFechaRegistro' => '2022-01-01 08:00:00', 'ResponsableEessEstado' => 1,
            ];
        }
        $this->insertarPorLotes('Organizacion.ResponsableEess', $filas);
    }

    /** Guardia comunitaria de 12 horas del personal D.L. 276 (RIT, Art. 20), un sabado de julio a setiembre. */
    private function volumenInformesDeGuardiaComunitaria(): void
    {
        $filas = [];
        foreach ($this->dotacion as $p) {
            if ($p['modo'] !== 'prog' || $p['regimen'] !== 'DL276' || $this->azar("{$p['n']}|gc") >= 12) {
                continue;
            }
            $sabado = date('Y-m-d', strtotime('2026-07-11 +'.($this->azarEntre("{$p['n']}|gcs", 0, 11) * 7).' days'));
            $estado = $this->porcentaje("{$p['n']}|gce", ['APROBADO' => 50, 'PENDIENTE' => 25, 'RECHAZADO' => 15, 'ANULADO' => 10]);
            $filas[] = [
                'VinculoLaboralId' => $p['vinculo'], 'DocumentoSustentoId' => $estado === 'APROBADO' ? $this->documento('informe', 300 + $p['n'], $sabado) : null,
                'InformeGuardiaComunitariaFecha' => $sabado, 'InformeGuardiaComunitariaHoraInicio' => $estado === 'RECHAZADO' ? null : '08:00:00',
                'InformeGuardiaComunitariaHoraFin' => $estado === 'RECHAZADO' ? null : '20:00:00',
                'InformeGuardiaComunitariaDescripcion' => $this->elegir("{$p['n']}|gcd", ['Visitas domiciliarias a gestantes del sector y educación sanitaria', 'Campaña de vacunación en el AA.HH.', 'Tamizaje de anemia en niños menores de 3 años', 'Atención preventiva en la comunidad', 'Charlas de nutrición en el comedor popular']),
                'InformeGuardiaComunitariaEstado' => $estado,
            ];
        }
        $this->insertarPorLotes('Programacion.InformeGuardiaComunitaria', $filas);
    }

    /** Procesos disciplinarios (Ley 30057, RIT Art. 100 a 107): los trabajadores con mas inasistencias, y algunos casos resueltos o archivados. */
    private function volumenDisciplina(): void
    {
        $tipos = DB::table('Disciplina.TipoFaltaDisciplinaria')->pluck('TipoFaltaDisciplinariaId', 'TipoFaltaDisciplinariaCodigo')->all();
        $mas = DB::table('Asistencia.AsistenciaDiaria as a')->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'a.VinculoLaboralId')->where('v.VinculoLaboralCodigo', 'like', 'VL-1%')
            ->whereIn('e.EstadoAsistenciaCodigo', ['FALTA', 'OMISION_MARCA'])->groupBy('a.VinculoLaboralId')->orderByRaw('COUNT(*) DESC')->orderBy('a.VinculoLaboralId')->limit(14)
            ->pluck('a.VinculoLaboralId')->map(fn ($v) => (int) $v)->all();

        // [tipo de falta, estado, inicio, fin|null, sancion|null, descripcion]
        $casos = [
            ['INASIST_INJUST', 'INICIADO', '2026-09-28', null, null, 'Inasistencias injustificadas en el mes (RIT, Art. 23)'],
            ['INASIST_INJUST', 'EN_PROCESO', '2026-09-14', null, null, 'Más de 3 días de inasistencia injustificada en 30 días'],
            ['TARDANZA_REIT', 'EN_PROCESO', '2026-09-01', null, null, 'Tardanzas mayores a 60 minutos acumulados en el mes (RIT, Art. 104)'],
            ['INCUMPL_HORARIO', 'INICIADO', '2026-10-01', null, null, 'Incumplimiento reiterado de la programación de turnos'],
            ['TARDANZA_REIT', 'RESUELTO', '2026-06-02', '2026-07-20', 'Amonestación escrita', 'Tardanzas reiteradas durante tres meses'],
            ['INASIST_INJUST', 'RESUELTO', '2026-05-04', '2026-07-03', 'Suspensión sin goce de remuneraciones por 10 días', 'Inasistencias injustificadas reiteradas'],
            ['ABANDONO', 'RESUELTO', '2026-04-13', '2026-06-15', 'Suspensión sin goce de remuneraciones por 5 días', 'Abandono del puesto durante la jornada'],
            ['MARCA_TERCERO', 'ARCHIVADO', '2026-03-09', '2026-05-11', null, 'Presunta marcación por tercero: no se acreditó'],
            ['NEGLIGENCIA', 'ARCHIVADO', '2026-02-02', '2026-04-06', null, 'Presunta negligencia en el desempeño: se archiva por falta de pruebas'],
            ['INCUMPL_HORARIO', 'ANULADO', '2026-08-03', null, null, 'Abierto por error'],
        ];
        $filas = [];
        foreach ($casos as $i => [$tipo, $estado, $inicio, $fin, $sancion, $descripcion]) {
            if (! isset($mas[$i])) {
                break;
            }
            $filas[] = [
                'VinculoLaboralId' => $mas[$i], 'TipoFaltaDisciplinariaId' => $tipos[$tipo], 'DocumentoSustentoId' => null,
                'ExpedientePadNumero' => $estado === 'ANULADO' ? null : sprintf('PAD-2026-%03d', 101 + $i), 'ExpedientePadFechaInicio' => $inicio, 'ExpedientePadFechaFin' => $fin,
                'ExpedientePadDescripcion' => $descripcion, 'ExpedientePadSancion' => $sancion, 'ExpedientePadEstado' => $estado,
            ];
        }
        $this->insertarPorLotes('Disciplina.ExpedientePad', $filas);
    }

    /** Supervisiones inopinadas de Recursos Humanos (RIT, Art. 24 y 29) a los establecimientos, de agosto a inicios de octubre. */
    private function volumenSupervisiones(): void
    {
        $establecimientos = collect($this->dotacion)->pluck('eess_id', 'eess')->all();
        $codigos = array_keys($establecimientos);
        $filas = [];
        for ($i = 0; $i < 26; $i++) {
            $codigo = $codigos[$i % count($codigos)];
            $trabajadores = collect($this->dotacion)->where('eess', $codigo)->where('modo', '!=', 'ninguno')->values();
            $p = $this->azar("sup{$i}") < 45 ? $trabajadores[$this->azarEntre("sup{$i}t", 0, $trabajadores->count() - 1)] : null;
            $estado = $this->porcentaje("sup{$i}e", ['CONFORME' => 55, 'OBSERVADO' => 25, 'REGISTRADO' => 12, 'ANULADO' => 8]);
            $fecha = date('Y-m-d', strtotime('2026-08-03 +'.$this->azarEntre("sup{$i}f", 0, 58).' days'));
            while (date('N', strtotime($fecha)) > 5) {
                $fecha = date('Y-m-d', strtotime($fecha.' +1 day'));
            }
            $filas[] = [
                'EessId' => $establecimientos[$codigo], 'VinculoLaboralId' => $p['vinculo'] ?? null, 'UsuarioId' => $this->usuarioId($i % 3 === 0 ? 'gdangelo' : 'pgutierrez'),
                'DocumentoSustentoId' => $estado === 'OBSERVADO' ? $this->documento('acta', 100 + $i, $fecha) : null,
                'SupervisionInopinadaFechaHora' => $fecha.' '.sprintf('%02d:%02d:00', 8 + $i % 8, ($i * 11) % 60),
                'SupervisionInopinadaResultado' => match ($estado) {
                    'CONFORME' => 'Todo el personal programado estaba en su puesto', 'OBSERVADO' => 'Se encontró personal ausente sin papeleta de salida', default => null,
                },
                'SupervisionInopinadaObservacion' => $estado === 'OBSERVADO' ? 'El jefe del establecimiento responde sobre las observaciones en 3 días (RIT, Art. 29)' : ($estado === 'ANULADO' ? 'Supervisión programada y no realizada' : null),
                'SupervisionInopinadaEstado' => $estado,
            ];
        }
        $this->insertarPorLotes('Disciplina.SupervisionInopinada', $filas);
    }

    /** Documento con el que cada establecimiento remite su programacion (RIT, Art. 16: hasta el dia 5 de cada mes). */
    private function volumenCargasDeProgramacion(): void
    {
        $tipoMensual = (int) DB::table('Programacion.TipoPeriodoProgramacion')->where('TipoPeriodoProgramacionCodigo', 'MENSUAL')->value('TipoPeriodoProgramacionId');
        $periodos = DB::table('Programacion.ProgramacionPeriodo')->where('TipoPeriodoProgramacionId', $tipoMensual)->whereBetween('ProgramacionPeriodoFechaInicio', ['2026-08-01', '2026-11-01'])
            ->get(['ProgramacionPeriodoId', 'EessId', 'ProgramacionPeriodoMes', 'ProgramacionPeriodoEstado']);
        $codigoDe = DB::table('Organizacion.EstablecimientoSalud')->pluck('EessCodigo', 'EessId')->all();
        $existentes = DB::table('Programacion.CargaProgramacion')->pluck('CargaProgramacionCodigo')->flip()->all();
        $filas = [];
        foreach ($periodos as $i => $periodo) {
            $codigo = 'PROG-2026-'.sprintf('%02d', $periodo->ProgramacionPeriodoMes).'-'.str_replace(['EESS-', 'SEDE-'], '', $codigoDe[$periodo->EessId]);
            if (isset($existentes[$codigo])) {
                continue;
            }
            $mes = (int) $periodo->ProgramacionPeriodoMes;
            $estado = $mes === 11 ? 'REGISTRADO' : $this->porcentaje("cp{$i}", ['CONFORME' => 80, 'OBSERVADO' => 12, 'REGISTRADO' => 8]);
            $fecha = date('Y-m-d', strtotime(sprintf('2026-%02d-01', $mes).' -'.$this->azarEntre("cp{$i}f", 2, 8).' days'));
            $filas[] = [
                'EessId' => (int) $periodo->EessId, 'DocumentoSustentoId' => $this->documento('resolucion', 200 + $i, $fecha), 'UsuarioRegistroId' => $this->usuarioId('rvargas'),
                'TipoPeriodoProgramacionId' => $tipoMensual, 'ProgramacionPeriodoId' => (int) $periodo->ProgramacionPeriodoId, 'CargaProgramacionCodigo' => $codigo,
                'CargaProgramacionAnio' => 2026, 'CargaProgramacionMes' => $mes, 'CargaProgramacionNumero' => null, 'CargaProgramacionFechaDocumento' => $fecha,
                'CargaProgramacionDocumentoNumero' => sprintf('OF-%04d-2026-%s', 100 + $i, str_replace(['EESS-', 'SEDE-'], '', $codigoDe[$periodo->EessId])),
                'CargaProgramacionMotivo' => 'Programación de '.['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'][$mes],
                'CargaProgramacionObservacion' => $estado === 'OBSERVADO' ? 'Falta la firma del jefe del establecimiento' : null,
                'CargaProgramacionFechaRegistro' => $fecha.' 15:00:00', 'CargaProgramacionEstado' => $estado,
            ];
            $existentes[$codigo] = true;
        }
        $this->insertarPorLotes('Programacion.CargaProgramacion', $filas);
    }

    /** Cuaderno de ocurrencias del servicio de vigilancia (RIT, Art. 16 y 21): salidas con papeleta, excesos, retiros sin autorizacion... */
    private function volumenPorteria(): void
    {
        $porEstablecimiento = collect($this->dotacion)->where('modo', '!=', 'ninguno')->groupBy('eess');
        $vigilante = $this->usuarioId('vsanchez');
        $filas = [];
        $i = 0;
        foreach ($porEstablecimiento as $codigo => $personal) {
            $personal = $personal->values();
            for ($k = 0; $k < 4; $k++, $i++) {
                $tipo = $this->porcentaje("po{$i}", ['SALIDA_CON_PAPELETA' => 35, 'RETORNO_DE_PAPELETA' => 25, 'SALIDA_SIN_AUTORIZACION' => 15, 'EXCESO_DE_PAPELETA' => 10, 'OTRO' => 15]);
                $p = $tipo === 'OTRO' && $this->azar("po{$i}s") < 50 ? null : $personal[$this->azarEntre("po{$i}p", 0, $personal->count() - 1)];
                $dia = date('Y-m-d', strtotime('2026-08-03 +'.$this->azarEntre("po{$i}d", 0, 59).' days'));
                while (date('N', strtotime($dia)) > 5) {
                    $dia = date('Y-m-d', strtotime($dia.' +1 day'));
                }
                $estado = $tipo === 'SALIDA_SIN_AUTORIZACION' ? $this->porcentaje("po{$i}e", ['REGISTRADO' => 60, 'ATENDIDO' => 30, 'ANULADO' => 10]) : $this->porcentaje("po{$i}e", ['ATENDIDO' => 70, 'REGISTRADO' => 25, 'ANULADO' => 5]);
                $filas[] = [
                    'EessId' => $personal[0]['eess_id'], 'VinculoLaboralId' => $p['vinculo'] ?? null, 'UsuarioId' => $estado === 'ANULADO' ? null : $vigilante,
                    'OcurrenciaPorteriaFechaHora' => $dia.' '.sprintf('%02d:%02d:00', 9 + $i % 8, ($i * 13) % 60), 'OcurrenciaPorteriaTipo' => $tipo,
                    'OcurrenciaPorteriaDescripcion' => match ($tipo) {
                        'SALIDA_CON_PAPELETA' => 'Salida por comisión de servicio con papeleta', 'RETORNO_DE_PAPELETA' => null,
                        'SALIDA_SIN_AUTORIZACION' => 'Se retiró sin presentar papeleta de salida', 'EXCESO_DE_PAPELETA' => 'Retornó después de las 3 horas de la papeleta',
                        default => 'Corte de energía en el acceso principal; se usó el registro manual',
                    },
                    'OcurrenciaPorteriaEstado' => $estado,
                ];
            }
        }
        $this->insertarPorLotes('Solicitudes.OcurrenciaPorteria', $filas);
    }

    /** Avisos, sesiones de acceso, historial de auditoria y bitacora de integraciones: lo que deja el uso diario del sistema. */
    private function volumenSoporte(): void
    {
        $usuarios = collect($this->dotacion)->filter(fn ($p) => isset($p['usuario']))->values();

        $avisos = [];
        foreach ($usuarios as $i => $u) {
            $avisos[] = [
                'UsuarioId' => $this->usuarioId($u['usuario']), 'NotificacionTipo' => 'GENERAL', 'NotificacionTitulo' => 'Programación de octubre',
                'NotificacionMensaje' => 'La programación de turnos de octubre de tu establecimiento fue publicada.', 'NotificacionEnlace' => '/programacion/periodos',
                'NotificacionFecha' => '2026-09-28 09:35:00', 'NotificacionLeida' => $this->azar("nt{$i}") < 70 ? 1 : 0,
            ];
            if ($u['rol'] !== null) {
                $avisos[] = [
                    'UsuarioId' => $this->usuarioId($u['usuario']), 'NotificacionTipo' => 'GENERAL', 'NotificacionTitulo' => 'Cierre de asistencia de setiembre',
                    'NotificacionMensaje' => 'El período de asistencia de setiembre se cierra el 5 de octubre: revisa las justificaciones pendientes de tu establecimiento.',
                    'NotificacionEnlace' => '/asistencia/justificacion-faltas', 'NotificacionFecha' => '2026-10-01 08:00:00', 'NotificacionLeida' => $this->azar("nt{$i}b") < 40 ? 1 : 0,
                ];
            }
        }
        $this->insertarPorLotes('Soporte.Notificacion', $avisos);

        $sesiones = [];
        for ($i = 0; $i < 90 && $usuarios->count() > 0; $i++) {
            $u = $usuarios[$i % $usuarios->count()];
            $inicio = date('Y-m-d', strtotime('2026-07-01 +'.$this->azarEntre("se{$i}", 0, 94).' days')).' '.sprintf('%02d:%02d:00', 7 + $i % 9, ($i * 7) % 60);
            $resultado = $this->porcentaje("se{$i}r", ['EXITOSO' => 86, 'CLAVE_INCORRECTA' => 11, 'USUARIO_INACTIVO' => 3]);
            $sesiones[] = [
                'UsuarioId' => $this->usuarioId($u['usuario']), 'SesionAccesoFechaInicio' => $inicio,
                'SesionAccesoFechaFin' => $resultado === 'EXITOSO' && $this->azar("se{$i}f") < 80 ? date('Y-m-d H:i:s', strtotime($inicio.' +'.$this->azarEntre("se{$i}m", 30, 420).' minutes')) : null,
                'SesionAccesoDireccionIp' => '192.168.'.(10 + $u['n'] % 60).'.'.(20 + $i % 30), 'SesionAccesoResultado' => $resultado,
            ];
        }
        $this->insertarPorLotes('Seguridad.SesionAcceso', $sesiones);

        $tablas = [['Personal', 'Trabajador', 'TrabajadorTelefono'], ['Personal', 'VinculoLaboral', 'VinculoLaboralNumeroPlaza'], ['Programacion', 'ProgramacionPeriodo', 'ProgramacionPeriodoEstado'],
            ['Solicitudes', 'Papeleta', 'PapeletaEstado'], ['Solicitudes', 'Licencia', 'LicenciaEstado'], ['Asistencia', 'JustificacionFalta', 'JustificacionFaltaEstado'], ['Configuracion', 'ParametroSistema', 'ParametroSistemaValor']];
        $auditoria = [];
        for ($i = 0; $i < 140; $i++) {
            [$esquema, $tabla, $columna] = $tablas[$i % count($tablas)];
            $operacion = $this->porcentaje("au{$i}", ['UPDATE' => 60, 'INSERT' => 30, 'DELETE' => 10]);
            $auditoria[] = [
                'AuditoriaEsquema' => $esquema, 'AuditoriaTabla' => $tabla, 'AuditoriaOperacion' => $operacion, 'AuditoriaRegistroId' => (string) (1 + $this->azarEntre("au{$i}r", 0, 400)),
                'AuditoriaDatosAnteriores' => $operacion === 'INSERT' ? null : '{"'.$columna.'":"anterior"}',
                'AuditoriaDatosNuevos' => $operacion === 'DELETE' ? null : '{"'.$columna.'":"nuevo"}',
                'AuditoriaFechaHora' => date('Y-m-d', strtotime('2026-07-01 +'.$this->azarEntre("au{$i}d", 0, 94).' days')).' '.sprintf('%02d:%02d:00', 8 + $i % 10, ($i * 17) % 60),
                'AuditoriaDireccionIp' => '192.168.10.'.(40 + $i % 20),
            ];
        }
        usort($auditoria, fn ($a, $b) => strcmp($a['AuditoriaFechaHora'], $b['AuditoriaFechaHora']));
        $this->insertarPorLotes('Seguridad.Auditoria', $auditoria);

        $dispositivos = DB::table('Biometria.DispositivoMarcacion')->where('DispositivoMarcacionEstado', 1)->whereNotNull('DispositivoMarcacionIp')->get(['DispositivoMarcacionCodigo', 'DispositivoMarcacionIp']);
        $logs = [];
        foreach ($dispositivos as $d => $dispositivo) {
            for ($k = 0; $k < 6; $k++) {
                $falla = $this->azar("lg{$d}{$k}") < 12;
                $logs[] = [
                    'LogIntegracionSistemaExterno' => 'RELOJ_BIOMETRICO', 'LogIntegracionOperacion' => 'SYNC_MARCACIONES', 'LogIntegracionDireccion' => 'ENTRADA',
                    'LogIntegracionPayloadResumen' => $falla ? '0 marcaciones: dispositivo sin respuesta' : (40 + $this->azarEntre("lg{$d}{$k}n", 0, 90)).' marcaciones recibidas de '.$dispositivo->DispositivoMarcacionCodigo,
                    'LogIntegracionResultado' => $falla ? 'ERROR' : 'OK', 'LogIntegracionMensajeError' => $falla ? 'Tiempo de espera agotado al conectar con '.$dispositivo->DispositivoMarcacionIp : null,
                    'LogIntegracionFechaHora' => date('Y-m-d', strtotime('2026-09-07 +'.($k * 4).' days')).' 06:00:'.sprintf('%02d', ($d * 7 + $k) % 60),
                    'LogIntegracionReintentos' => $falla ? 3 : 0,
                ];
            }
        }
        $this->insertarPorLotes('Soporte.LogIntegracion', $logs);
    }
}
