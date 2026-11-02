<?php

namespace App\Services;

use App\Models\Consolidacion\PeriodoAsistencia;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;

/**
 * Formatos oficiales del RIT: consolidados de asistencia por condicion laboral (Anexos 012 a 016) y registro manual de
 * asistencia (Anexo 017), con las columnas y las firmas del reglamento. Salen de la asistencia diaria, de modo que
 * reflejan lo mismo que el consolidado del periodo.
 */
class AnexosOficialesService
{
    /** Anexo => titulo, condiciones laborales y si lleva las columnas de guardias. */
    public const ANEXOS = [
        '012' => ['CONSOLIDADO DE ASISTENCIA DE TRABAJADORES NOMBRADOS Y PLAZO FIJO', ['NOMBRADO', 'CONTRATADO'], true],
        '013' => ['CONSOLIDADO DE ASISTENCIA DE TRABAJADORES DESTACADOS', ['DESTACADO'], true],
        '014' => ['CONSOLIDADO DE ASISTENCIA DE TRABAJADORES CONTRATADOS CAS', ['CAS', 'CAS_INDETERMINADO'], false],
        '015' => ['CONSOLIDADO DE ASISTENCIA DE MÉDICOS RESIDENTES', ['RESIDENTE'], true],
        '016' => ['CONSOLIDADO DE ASISTENCIA DE TRABAJADORES SERUMS - INTERNOS', ['SERUMS_REM', 'SERUMS_EQUIV', 'INTERNO'], false],
    ];

    /** Filas minimas del formato impreso. */
    private const FILAS_DEL_FORMATO = 21;

    /** @return array{eess:object,filas:list<array<string,mixed>>} */
    public function datosDelConsolidado(string $anexo, PeriodoAsistencia $periodo, int $eessId): array
    {
        [, $condiciones] = self::ANEXOS[$anexo];
        $desde = $periodo->PeriodoAsistenciaFechaInicio->toDateString();
        $hasta = $periodo->PeriodoAsistenciaFechaFin->toDateString();

        $vinculos = DB::table('Personal.VinculoLaboral as v')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->join('Personal.Cargo as c', 'c.CargoId', '=', 'v.CargoId')
            ->join('Personal.CondicionLaboral as cl', 'cl.CondicionLaboralId', '=', 'v.CondicionLaboralId')
            ->where('v.EessId', $eessId)->whereIn('cl.CondicionLaboralCodigo', $condiciones)
            ->whereDate('v.VinculoLaboralFechaInicio', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('v.VinculoLaboralFechaFin')->orWhereDate('v.VinculoLaboralFechaFin', '>=', $desde))
            ->orderBy('t.TrabajadorNombreCompleto')
            ->get(['v.VinculoLaboralId as id', 't.TrabajadorNombreCompleto as nombre', 'c.CargoNombre as cargo']);

        $ids = $vinculos->pluck('id')->all();
        $dias = DB::table('Asistencia.AsistenciaDiaria as a')->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
            ->whereIn('a.VinculoLaboralId', $ids ?: [0])->whereDate('a.AsistenciaDiariaFecha', '>=', $desde)->whereDate('a.AsistenciaDiariaFecha', '<=', $hasta)
            ->get(['a.VinculoLaboralId as vinculo', 'a.AsistenciaDiariaFecha as fecha', 'a.AsistenciaDiariaMinutosTardanza as tardanza', 'a.AsistenciaDiariaMinutosSinGoce as sin_goce',
                'e.EstadoAsistenciaCodigo as estado', 'e.EstadoAsistenciaEsFalta as es_falta', 'e.EstadoAsistenciaEsDescontable as descontable'])
            ->groupBy('vinculo');
        $licencias = DB::table('Solicitudes.Licencia as l')->join('Solicitudes.TipoLicencia as t', 't.TipoLicenciaId', '=', 'l.TipoLicenciaId')
            ->whereIn('l.VinculoLaboralId', $ids ?: [0])->where('l.LicenciaEstado', 'APROBADO')
            ->whereDate('l.LicenciaFechaInicio', '<=', $hasta)->whereDate('l.LicenciaFechaFin', '>=', $desde)
            ->get(['l.VinculoLaboralId as vinculo', 'l.LicenciaFechaInicio as ini', 'l.LicenciaFechaFin as fin', 't.TipoLicenciaConGoce as con_goce', 't.TipoLicenciaACuentaVacaciones as a_cuenta'])->groupBy('vinculo');
        $guardias = DB::table('Programacion.TurnoProgramado as tp')->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'tp.TurnoId')
            ->whereIn('pt.VinculoLaboralId', $ids ?: [0])->whereDate('tp.TurnoProgramadoFecha', '>=', $desde)->whereDate('tp.TurnoProgramadoFecha', '<=', $hasta)
            ->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')->where(fn ($q) => $q->where('tp.TurnoProgramadoEsGuardia', 1)->orWhere('t.TurnoEsGuardia', 1))
            ->get(['pt.VinculoLaboralId as vinculo', 'tp.TurnoProgramadoFecha as fecha'])->groupBy('vinculo');

        $filas = $vinculos->values()->map(function (object $v, int $i) use ($dias, $licencias, $guardias, $desde, $hasta) {
            $delVinculo = $dias->get($v->id, collect());
            $faltasGuardia = $delVinculo->where('estado', 'FALTA_GUARDIA')->count();
            $conGoce = 0;
            $sinGoce = 0;
            $aCuenta = $delVinculo->where('estado', 'VACACIONES')->count();
            foreach ($licencias->get($v->id, collect()) as $l) {
                $n = self::diasEnElPeriodo(substr((string) $l->ini, 0, 10), substr((string) $l->fin, 0, 10), $desde, $hasta);
                if ($l->a_cuenta) {
                    $aCuenta += $n;
                } elseif ($l->con_goce) {
                    $conGoce += $n;
                } else {
                    $sinGoce += $n;
                }
            }
            $programadas = $guardias->get($v->id, collect());
            $conFalta = $delVinculo->filter(fn ($d) => (bool) $d->es_falta)->map(fn ($d) => substr((string) $d->fecha, 0, 10))->all();

            return [
                'numero' => $i + 1,
                'nombre' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $v->nombre))),
                'cargo' => $v->cargo,
                'tardanza' => (int) $delVinculo->sum('tardanza'),
                'faltas' => $delVinculo->filter(fn ($d) => $d->es_falta && $d->descontable)->count() + $faltasGuardia,
                'enfermedad' => $delVinculo->where('estado', 'DESCANSO_MED')->count(),
                'sin_goce' => $sinGoce,
                'horas_personales' => round($delVinculo->sum('sin_goce') / 60, 2),
                'con_goce' => $conGoce,
                'a_cuenta' => $aCuenta,
                'guardias_programadas' => $programadas->count(),
                'guardias_realizadas' => $programadas->reject(fn ($g) => in_array(substr((string) $g->fecha, 0, 10), $conFalta, true))->count(),
                'observaciones' => $faltasGuardia > 0 ? "{$faltasGuardia} guardia(s) no realizada(s)" : '',
            ];
        })->all();

        return ['eess' => $this->establecimiento($eessId), 'filas' => $filas];
    }

    /** PDF del consolidado (Anexos 012 a 016). */
    public function consolidadoPdf(string $anexo, PeriodoAsistencia $periodo, int $eessId): string
    {
        [$titulo, , $conGuardias] = self::ANEXOS[$anexo];
        ['eess' => $eess, 'filas' => $filas] = $this->datosDelConsolidado($anexo, $periodo, $eessId);
        $mes = CalendarioAsistenciaService::MESES[(int) $periodo->PeriodoAsistenciaMes].' '.$periodo->PeriodoAsistenciaAnio;

        $cuerpo = '';
        for ($i = 0; $i < max(self::FILAS_DEL_FORMATO, count($filas)); $i++) {
            $f = $filas[$i] ?? null;
            $celda = fn (string $clave) => '<td class="c">'.($f !== null ? e((string) $f[$clave]) : '').'</td>';
            $cuerpo .= '<tr><td class="c">'.($i + 1).'</td><td>'.e($f['nombre'] ?? '').'</td><td>'.e($f['cargo'] ?? '').'</td>'
                .$celda('tardanza').$celda('faltas').$celda('enfermedad').$celda('sin_goce').$celda('horas_personales').$celda('con_goce').$celda('a_cuenta')
                .($conGuardias ? $celda('guardias_programadas').$celda('guardias_realizadas') : '').'<td>'.e($f['observaciones'] ?? '').'</td></tr>';
        }

        $cabecera = '<tr><th rowspan="2" style="width:3%">N°</th><th rowspan="2" style="width:20%">APELLIDOS Y NOMBRES</th><th rowspan="2" style="width:12%">CARGO</th>'
            .'<th rowspan="2">MINUTOS DE TARDANZAS</th><th rowspan="2">N° DÍAS FALTAS INJUSTIF.</th><th colspan="5">LICENCIAS Y/O PERMISOS</th>'
            .($conGuardias ? '<th colspan="2">NÚMERO DE GUARDIAS</th>' : '').'<th rowspan="2" style="width:14%">OBSERVACIONES</th></tr>'
            .'<tr><th>N° DÍAS FALTAS X ENFERMEDAD</th><th>N° DÍAS SIN GOCE DE HABER</th><th>HORAS X ASUNTOS PERSONALES</th><th>N° DÍAS CON GOCE DE HABER</th><th>N° DÍAS A CUENTA DE VACACIONES</th>'
            .($conGuardias ? '<th>GUARDIAS PROGRAMADAS</th><th>GUARDIAS REALIZADAS</th>' : '').'</tr>';

        $html = $this->marco("ANEXO N° {$anexo}", $titulo, '<p class="dato"><b>ESTABLECIMIENTO:</b> '.e($eess->nombre).'</p><p class="dato"><b>MICRO RED DE SALUD:</b> '.e($eess->microred).'</p><p class="dato"><b>MES Y AÑO:</b> '.e(mb_strtoupper($mes)).'</p>',
            "<table><thead>{$cabecera}</thead><tbody>{$cuerpo}</tbody></table>", 'landscape')
            .'<table class="firmas"><tr><td>RESPONSABLE DE PERSONAL</td><td>JEFE DEL ESTABLECIMIENTO</td></tr></table></body></html>';

        return $this->pdf($html, 'landscape');
    }

    /** PDF del registro de asistencia manual de un dia (Anexo 017), con el personal programado o de horario. */
    public function registroManualPdf(int $eessId, string $fecha): string
    {
        $eess = $this->establecimiento($eessId);
        $dia = CarbonImmutable::parse($fecha);
        $personal = DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->leftJoin('Programacion.ProgramacionTrabajador as pt', 'pt.VinculoLaboralId', '=', 'v.VinculoLaboralId')
            ->leftJoin('Programacion.TurnoProgramado as tp', fn ($j) => $j->on('tp.ProgramacionTrabajadorId', '=', 'pt.ProgramacionTrabajadorId')->whereDate('tp.TurnoProgramadoFecha', $fecha)->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO'))
            ->leftJoin('Configuracion.Turno as tu', 'tu.TurnoId', '=', 'tp.TurnoId')
            ->where('v.EessId', $eessId)->where('v.VinculoLaboralEstado', 1)->whereDate('v.VinculoLaboralFechaInicio', '<=', $fecha)
            ->where(fn ($q) => $q->whereNull('v.VinculoLaboralFechaFin')->orWhereDate('v.VinculoLaboralFechaFin', '>=', $fecha))
            ->orderByRaw('CASE WHEN tu.TurnoCodigo IS NULL THEN 1 ELSE 0 END')->orderBy('t.TrabajadorNombreCompleto')
            ->get(['t.TrabajadorNombreCompleto as nombre', 'tu.TurnoCodigo as turno'])->unique(fn ($f) => $f->nombre.$f->turno)->values();

        $filas = '';
        for ($i = 0; $i < max(29, $personal->count()); $i++) {
            $p = $personal[$i] ?? null;
            $filas .= '<tr><td class="c">'.e($p->turno ?? '').'</td><td class="c">'.($p ? $dia->format('d/m/Y') : '').'</td><td>'.e($p ? trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $p->nombre))) : '').'</td><td></td><td></td><td></td><td></td><td></td></tr>';
        }
        $html = $this->marco('ANEXO N° 017', 'REGISTRO DE ASISTENCIA DEL PERSONAL', '<p class="dato"><b>ESTABLECIMIENTO DE SALUD:</b> '.e($eess->nombre).'</p><p class="dato"><b>MES:</b> '.e(mb_strtoupper(CalendarioAsistenciaService::MESES[$dia->month])).' &nbsp; <b>AÑO:</b> '.$dia->year.'</p>',
            '<table><thead><tr><th>TURNO</th><th>FECHA</th><th style="width:34%">APELLIDOS Y NOMBRES</th><th>FIRMA</th><th>HORA DE ENTRADA</th><th>FIRMA</th><th>HORA DE SALIDA</th><th>OBSERVACIONES</th></tr></thead><tbody>'.$filas.'</tbody></table>', 'portrait').'</body></html>';

        return $this->pdf($html, 'portrait');
    }

    /** Planilla de descuentos del periodo (RIT, Art. 25). */
    public function planillaDeDescuentosPdf(PeriodoAsistencia $periodo, ?int $eessId): string
    {
        $filas = DB::table('Compensaciones.LiquidacionDescuento as l')
            ->join('Consolidacion.ConsolidadoAsistencia as c', 'c.ConsolidadoAsistenciaId', '=', 'l.ConsolidadoAsistenciaId')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'c.VinculoLaboralId')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
            ->where('c.PeriodoAsistenciaId', $periodo->PeriodoAsistenciaId)->where('l.LiquidacionDescuentoEstado', '<>', 'ANULADO')
            ->when($eessId, fn ($q) => $q->where('v.EessId', $eessId))->orderBy('e.EessNombre')->orderBy('t.TrabajadorNombreCompleto')
            ->get(['t.TrabajadorNumeroDocumento as dni', 't.TrabajadorNombreCompleto as nombre', 'e.EessNombre as eess', 'c.ConsolidadoAsistenciaDiasFalta as faltas',
                'c.ConsolidadoAsistenciaMinutosTardanza as tardanza', 'l.LiquidacionDescuentoImporteTotal as importe', 'l.LiquidacionDescuentoEstado as estado']);

        $cuerpo = $filas->map(fn ($f, $i) => '<tr><td class="c">'.($i + 1).'</td><td class="c">'.e($f->dni).'</td><td>'.e(trim(preg_replace('/\s+/', ' ', (string) $f->nombre))).'</td><td>'.e($f->eess).'</td><td class="c">'.(float) $f->faltas.'</td><td class="c">'.(int) $f->tardanza.'</td><td class="c">S/ '.number_format((float) $f->importe, 2).'</td><td class="c">'.e($f->estado).'</td></tr>')->implode('');
        $total = number_format((float) $filas->sum('importe'), 2);
        $mes = CalendarioAsistenciaService::MESES[(int) $periodo->PeriodoAsistenciaMes].' '.$periodo->PeriodoAsistenciaAnio;
        $html = $this->marco('', 'PLANILLA DE DESCUENTOS POR INASISTENCIAS Y TARDANZAS', '<p class="dato"><b>PERÍODO:</b> '.e(mb_strtoupper($mes)).'</p>',
            '<table><thead><tr><th>N°</th><th>DNI</th><th>APELLIDOS Y NOMBRES</th><th>ESTABLECIMIENTO</th><th>DÍAS DE FALTA</th><th>MIN. TARDANZA</th><th>IMPORTE</th><th>ESTADO</th></tr></thead><tbody>'.$cuerpo.'<tr><td colspan="6" class="c"><b>TOTAL</b></td><td class="c"><b>S/ '.$total.'</b></td><td></td></tr></tbody></table>', 'landscape').'</body></html>';

        return $this->pdf($html, 'landscape');
    }

    /** Constancia de trabajo o certificado de trabajo (RIT, Art. 83.7 y 131). */
    public function documentoDeTrabajoPdf(int $vinculoId, bool $certificado): string
    {
        $v = DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->join('Personal.Cargo as c', 'c.CargoId', '=', 'v.CargoId')->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
            ->join('Personal.CondicionLaboral as cl', 'cl.CondicionLaboralId', '=', 'v.CondicionLaboralId')
            ->where('v.VinculoLaboralId', $vinculoId)
            ->first(['t.TrabajadorNombreCompleto as nombre', 't.TrabajadorNumeroDocumento as dni', 'c.CargoNombre as cargo', 'e.EessNombre as eess', 'cl.CondicionLaboralNombre as condicion',
                'v.VinculoLaboralFechaInicio as inicio', 'v.VinculoLaboralFechaFin as fin']);
        $nombre = trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $v->nombre)));
        $inicio = CarbonImmutable::parse($v->inicio)->format('d/m/Y');
        $hasta = $v->fin ? 'hasta el '.CarbonImmutable::parse($v->fin)->format('d/m/Y') : 'hasta la fecha';
        $titulo = $certificado ? 'CERTIFICADO DE TRABAJO' : 'CONSTANCIA DE TRABAJO';
        $verbo = $certificado ? 'CERTIFICA' : 'HACE CONSTAR';

        $html = $this->marco('', $titulo, '', '<p style="font-size:12pt;line-height:1.7;text-align:justify;margin-top:30px">La Unidad Funcional de Gestión de Recursos Humanos de la Unidad Ejecutora 409 Salud Trujillo Este '.$verbo.' que <b>'.e($nombre).'</b>, identificado(a) con DNI N° '.e((string) $v->dni).', ha prestado servicios como <b>'.e($v->cargo).'</b> en '.e($v->eess).', bajo la condición de '.e($v->condicion).", desde el {$inicio} {$hasta}.</p>"
            .'<p style="font-size:12pt;margin-top:24px">Se expide a solicitud del interesado para los fines que estime conveniente.</p><p style="font-size:12pt;text-align:right;margin-top:40px">Trujillo, '.now('America/Lima')->format('d/m/Y').'</p>', 'portrait')
            .'<table class="firmas"><tr><td>JEFE DE RECURSOS HUMANOS</td></tr></table></body></html>';

        return $this->pdf($html, 'portrait');
    }

    private static function diasEnElPeriodo(string $inicio, string $fin, string $desde, string $hasta): int
    {
        return (int) ((strtotime(min($fin, $hasta)) - strtotime(max($inicio, $desde))) / 86400) + 1;
    }

    private function establecimiento(int $eessId): object
    {
        return DB::table('Organizacion.EstablecimientoSalud as e')->join('Organizacion.Microred as m', 'm.MicroredId', '=', 'e.MicroredId')
            ->where('e.EessId', $eessId)->first(['e.EessNombre as nombre', 'm.MicroredNombre as microred']);
    }

    private function marco(string $anexo, string $titulo, string $datos, string $contenido, string $orientacion): string
    {
        $logo = CalendarioAsistenciaPdf::logo();
        $imagen = $logo ? '<img src="'.$logo.'" style="height:52px;float:left">' : '';
        $letra = $orientacion === 'landscape' ? '7.5pt' : '8pt';

        return '<html><head><meta charset="utf-8"><style>@page{margin:24px 30px}body{font-family:Helvetica;font-size:'.$letra.'}h1{font-size:11pt;text-align:center;margin:0 0 6px;text-decoration:underline}h2{font-size:10pt;text-align:center;margin:0 0 6px}'
            .'.dato{text-align:center;margin:1px 0}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #333;padding:3px}th{font-size:6.5pt;background:#f0f0f0}.c{text-align:center}'
            .'.firmas{margin-top:44px;border:0}.firmas td{border:0;border-top:1px solid #333;text-align:center;font-size:7.5pt;width:40%}</style></head><body>'
            .($anexo !== '' ? "<h1>{$anexo}</h1>" : '').$imagen."<h2>{$titulo}</h2>{$datos}<div style=\"clear:both\"></div>{$contenido}";
    }

    private function pdf(string $html, string $orientacion): string
    {
        ini_set('memory_limit', '512M');
        $opciones = new Options;
        $opciones->set('defaultFont', 'Helvetica');
        $opciones->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientacion);
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
