<?php

namespace App\Services;

use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\PeriodoAsistencia;
use App\Models\Seguridad\Usuario;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Revision del consolidado mensual de asistencia (RIT, Art. 16): el consolidado no se digita ni se edita; se REVISA.
 *
 *   GENERADO  -> (incidencias revisadas)  -> CONFORME (aprobado)            -> CERRADO al cerrar el periodo
 *             -> OBSERVADO (se pide corregir la asistencia de origen; al regenerar vuelve a GENERADO)
 *
 * Plazos del RIT para consolidar: dia 1 internos y SERUMS remunerados, dia 3 destacados, dia 5 personal con vinculo; el
 * formato fisico se presenta hasta el dia 7. El Excel sigue los anexos del RIT: una hoja por condicion laboral.
 */
class RevisionConsolidadoService
{
    /** Dia del mes siguiente hasta el que se consolida cada condicion laboral (RIT, Art. 16). */
    public const PLAZOS = ['INTERNO' => 1, 'SERUMS_EQUIV' => 1, 'SERUMS' => 1, 'DESTACADO' => 3];

    public const PLAZO_GENERAL = 5;

    public function __construct(private NotificacionService $notificaciones) {}

    /** Fecha limite de consolidacion de una condicion en un periodo (mes siguiente al periodo). */
    public static function fechaLimite(int $anio, int $mes, ?string $condicion): string
    {
        $dia = self::PLAZOS[$condicion ?? ''] ?? self::PLAZO_GENERAL;

        return CarbonImmutable::create($anio, $mes, 1)->addMonthNoOverflow()->setDay($dia)->toDateString();
    }

    /**
     * Dias con incidencia del consolidado (faltas, tardanzas, omisiones, salidas): lo que el revisor debe mirar.
     *
     * @return list<array<string,mixed>>
     */
    public function incidencias(ConsolidadoAsistencia $consolidado): array
    {
        $periodo = $consolidado->periodo;

        return DB::table('Asistencia.AsistenciaDiaria as a')
            ->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
            ->where('a.VinculoLaboralId', $consolidado->VinculoLaboralId)
            ->whereDate('a.AsistenciaDiariaFecha', '>=', $periodo->PeriodoAsistenciaFechaInicio->toDateString())
            ->whereDate('a.AsistenciaDiariaFecha', '<=', $periodo->PeriodoAsistenciaFechaFin->toDateString())
            ->where(fn ($q) => $q->where('e.EstadoAsistenciaEsFalta', 1)->orWhere('a.AsistenciaDiariaMinutosTardanza', '>', 0)->orWhere('e.EstadoAsistenciaCodigo', 'SALIDA_ANTIC'))
            ->orderBy('a.AsistenciaDiariaFecha')
            ->get(['a.AsistenciaDiariaFecha as fecha', 'e.EstadoAsistenciaCodigo as codigo', 'e.EstadoAsistenciaNombre as estado', 'a.AsistenciaDiariaMinutosTardanza as tardanza',
                'a.AsistenciaDiariaMinutosFalta as falta', 'a.AsistenciaDiariaObservacion as observacion', 'a.AsistenciaDiariaOrigen as origen'])
            ->map(fn ($f) => ['fecha' => substr((string) $f->fecha, 0, 10), 'codigo' => $f->codigo, 'estado' => $f->estado, 'minutos_tardanza' => (int) $f->tardanza,
                'minutos_falta' => (int) $f->falta, 'observacion' => $f->observacion, 'origen' => $f->origen])
            ->all();
    }

    /** Pide corregir la asistencia de origen: queda OBSERVADO con el motivo y se avisa al establecimiento. */
    public function observar(ConsolidadoAsistencia $consolidado, Usuario $revisor, string $observacion): ConsolidadoAsistencia
    {
        $this->exigirRevisable($consolidado);
        $consolidado->update([
            'ConsolidadoAsistenciaEstado' => 'OBSERVADO',
            'ConsolidadoAsistenciaObservacion' => mb_substr($observacion, 0, 1000),
            'ConsolidadoAsistenciaRevisadoPor' => $revisor->UsuarioId,
            'ConsolidadoAsistenciaFechaRevision' => HoraLocal::ahora(),
        ]);
        $v = $consolidado->vinculoLaboral()->with('trabajador:TrabajadorId,TrabajadorNombreCompleto')->first();
        $this->notificaciones->emitir('CONSOLIDADO_OBSERVADO', 'Consolidado observado',
            'El consolidado de '.trim((string) $v?->trabajador?->TrabajadorNombreCompleto).' requiere corrección: '.$observacion,
            '/consolidacion/gestion?pestana=consolidado', $v?->EessId);

        return $consolidado->fresh();
    }

    /** Conformidad del revisor: el consolidado queda CONFORME (ya no se regenera). */
    public function aprobar(ConsolidadoAsistencia $consolidado, Usuario $revisor, ?string $nota = null): ConsolidadoAsistencia
    {
        $this->exigirRevisable($consolidado);
        $consolidado->update([
            'ConsolidadoAsistenciaEstado' => 'CONFORME',
            'ConsolidadoAsistenciaObservacion' => $nota !== null ? mb_substr($nota, 0, 1000) : $consolidado->ConsolidadoAsistenciaObservacion,
            'ConsolidadoAsistenciaRevisadoPor' => $revisor->UsuarioId,
            'ConsolidadoAsistenciaFechaRevision' => HoraLocal::ahora(),
        ]);

        return $consolidado->fresh();
    }

    /**
     * Excel del consolidado de un periodo (y opcionalmente un establecimiento): una hoja por condicion laboral, como los
     * anexos del RIT. Devuelve la ruta del archivo temporal.
     *
     * @param  list<int>|null  $eessIds
     */
    public function exportar(PeriodoAsistencia $periodo, ?array $eessIds): string
    {
        $filas = $this->filasParaExportar($periodo, $eessIds);
        $ruta = tempnam(sys_get_temp_dir(), 'cons').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($ruta);
        $titulo = (new Style)->withFontBold(true)->withFontSize(12);
        $encabezado = (new Style)->withFontBold(true)->withBackgroundColor('D9EEF0');
        $columnas = ['N°', 'DNI', 'Apellidos y nombres', 'Establecimiento', 'Cargo', 'Días trabajados', 'Faltas (días)', 'Faltas justificadas (días)', 'Tardanza (min)', 'Sobretiempo (min)', 'Estado', 'Revisado por', 'Fecha de consolidación', 'Observación'];

        $porCondicion = $filas->groupBy('condicion');
        if ($porCondicion->isEmpty()) {
            $porCondicion = collect(['Sin datos' => collect()]);
        }
        $primera = true;
        foreach ($porCondicion as $condicion => $grupo) {
            $hoja = $primera ? $escritor->getCurrentSheet() : $escritor->addNewSheetAndMakeItCurrent();
            $primera = false;
            $hoja->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string) $condicion), 0, 31));
            $escritor->addRow(Row::fromValuesWithStyle(['CONSOLIDADO DE ASISTENCIA - '.mb_strtoupper((string) $condicion)], $titulo));
            $escritor->addRow(Row::fromValues(['Período: '.str_pad((string) $periodo->PeriodoAsistenciaMes, 2, '0', STR_PAD_LEFT).'/'.$periodo->PeriodoAsistenciaAnio.
                ' · Plazo para consolidar (RIT, Art. 16): '.CarbonImmutable::parse(self::fechaLimite($periodo->PeriodoAsistenciaAnio, $periodo->PeriodoAsistenciaMes, $grupo->first()['codigo_condicion'] ?? null))->format('d/m/Y')]));
            $escritor->addRow(Row::fromValues([]));
            $escritor->addRow(Row::fromValuesWithStyle($columnas, $encabezado));
            foreach ($grupo->values() as $i => $f) {
                $escritor->addRow(Row::fromValues([$i + 1, $f['dni'], $f['nombre'], $f['eess'], $f['cargo'], $f['dias_trabajados'], $f['dias_falta'], $f['dias_falta_justificada'],
                    $f['minutos_tardanza'], $f['minutos_extra'], $f['estado'], $f['revisado_por'], $f['fecha_generacion'], $f['observacion']]));
            }
        }
        $escritor->close();

        return $ruta;
    }

    /** @return Collection<int,array<string,mixed>> */
    private function filasParaExportar(PeriodoAsistencia $periodo, ?array $eessIds): Collection
    {
        return DB::table('Consolidacion.ConsolidadoAsistencia as c')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'c.VinculoLaboralId')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->join('Personal.CondicionLaboral as cl', 'cl.CondicionLaboralId', '=', 'v.CondicionLaboralId')
            ->join('Personal.Cargo as cg', 'cg.CargoId', '=', 'v.CargoId')
            ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
            ->leftJoin('Seguridad.Usuario as u', 'u.UsuarioId', '=', 'c.ConsolidadoAsistenciaRevisadoPor')
            ->where('c.PeriodoAsistenciaId', $periodo->PeriodoAsistenciaId)
            ->when($eessIds !== null, fn ($q) => $q->whereIn('v.EessId', $eessIds))
            ->orderBy('cl.CondicionLaboralNombre')->orderBy('e.EessNombre')->orderBy('t.TrabajadorNombreCompleto')
            ->get(['t.TrabajadorNumeroDocumento as dni', 't.TrabajadorNombreCompleto as nombre', 'e.EessNombre as eess', 'cg.CargoNombre as cargo', 'cl.CondicionLaboralNombre as condicion',
                'cl.CondicionLaboralCodigo as codigo_condicion', 'c.ConsolidadoAsistenciaDiasTrabajados as dias_trabajados', 'c.ConsolidadoAsistenciaDiasFalta as dias_falta',
                'c.ConsolidadoAsistenciaDiasFaltaJustificada as dias_falta_justificada', 'c.ConsolidadoAsistenciaMinutosTardanza as minutos_tardanza', 'c.ConsolidadoAsistenciaMinutosExtra as minutos_extra',
                'c.ConsolidadoAsistenciaEstado as estado', 'u.UsuarioNombre as revisado_por', 'c.ConsolidadoAsistenciaFechaGeneracion as fecha_generacion', 'c.ConsolidadoAsistenciaObservacion as observacion'])
            ->map(fn ($f) => [
                'dni' => $f->dni, 'nombre' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $f->nombre))), 'eess' => $f->eess, 'cargo' => $f->cargo,
                'condicion' => $f->condicion, 'codigo_condicion' => $f->codigo_condicion, 'dias_trabajados' => (float) $f->dias_trabajados, 'dias_falta' => (float) $f->dias_falta,
                'dias_falta_justificada' => (float) $f->dias_falta_justificada, 'minutos_tardanza' => (int) $f->minutos_tardanza, 'minutos_extra' => (int) $f->minutos_extra,
                'estado' => $f->estado, 'revisado_por' => $f->revisado_por, 'fecha_generacion' => substr((string) $f->fecha_generacion, 0, 16), 'observacion' => $f->observacion,
            ]);
    }

    private function exigirRevisable(ConsolidadoAsistencia $consolidado): void
    {
        if ($consolidado->periodo?->PeriodoAsistenciaEstado === 'CERRADO' || $consolidado->ConsolidadoAsistenciaEstado === 'CERRADO') {
            throw new DomainException('El período está cerrado: el consolidado ya no se revisa.');
        }
    }
}
