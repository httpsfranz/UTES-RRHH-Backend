<?php

namespace App\Services;

use App\Models\Compensaciones\ConceptoDescuento;
use App\Models\Compensaciones\DetalleLiquidacion;
use App\Models\Compensaciones\LiquidacionDescuento;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Support\DiasHabiles;
use App\Support\Parametro;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Liquidacion de los descuentos por inasistencias y tardanzas de un consolidado de asistencia (RIT, Art. 25: se ejecutan
 * en la planilla unica de pagos, previa disposicion en la Resolucion Directoral). Ciclo de vida:
 * GENERADO -> APROBADO -> REMITIDO (a planilla), o ANULADO mientras no se haya remitido.
 *
 * Importes (RIT, Art. 25): valor del dia = ingreso total / 30; valor de la hora = valor del dia / jornada diaria, de 6 u
 * 8 horas. El ingreso sale del vinculo laboral; sin ese dato la linea nace con importe cero y la completa quien liquida.
 *
 * Tardanzas (RIT, Art. 22, parametro TARDANZA_MODO_DESCUENTO): UMBRAL_MENSUAL descuenta solo al superar una hora al mes y
 * computa como hora la fraccion mayor a 30 minutos; PROPORCIONAL (Art. 25) descuenta todos los minutos.
 */
class LiquidacionDescuentoService
{
    /** Consolidados que ya se pueden liquidar: los observados o recien generados aun pueden corregirse. */
    public const CONSOLIDADOS_LIQUIDABLES = ['CONFORME', 'CERRADO'];

    /** Horas de tardanza del mes que se descuentan (ver la cabecera de la clase). */
    public static function horasDeTardanza(int $minutos): float
    {
        if (Parametro::texto('TARDANZA_MODO_DESCUENTO', 'UMBRAL_MENSUAL') === 'PROPORCIONAL') {
            return round($minutos / 60, 2);
        }
        if ($minutos <= 60) {
            return 0.0;
        }

        return (float) (intdiv($minutos, 60) + ($minutos % 60 > 30 ? 1 : 0));
    }

    /** Motivo por el que el consolidado no se puede liquidar (null = se puede). */
    public static function motivoSiNoSeLiquida(ConsolidadoAsistencia $consolidado): ?string
    {
        if (! in_array($consolidado->ConsolidadoAsistenciaEstado, self::CONSOLIDADOS_LIQUIDABLES, true)) {
            return 'Solo se liquida un consolidado conforme o cerrado: el de este trabajador está '.strtolower($consolidado->ConsolidadoAsistenciaEstado).'.';
        }
        if ((float) $consolidado->ConsolidadoAsistenciaDiasFalta <= 0 && self::horasDeTardanza((int) $consolidado->ConsolidadoAsistenciaMinutosTardanza) <= 0 && (int) $consolidado->ConsolidadoAsistenciaMinutosSinGoce <= 0) {
            return 'El consolidado no tiene inasistencias injustificadas, tardanzas ni permisos sin goce que descontar.';
        }

        return null;
    }

    /**
     * Genera la liquidacion del consolidado con sus lineas. Si la anterior fue anulada se reutiliza (el consolidado admite
     * una sola liquidacion).
     */
    public function generar(ConsolidadoAsistencia $consolidado): LiquidacionDescuento
    {
        if ($motivo = self::motivoSiNoSeLiquida($consolidado)) {
            throw new DomainException($motivo);
        }

        return DB::transaction(function () use ($consolidado) {
            $liquidacion = LiquidacionDescuento::query()->where('ConsolidadoAsistenciaId', $consolidado->ConsolidadoAsistenciaId)->first();
            if ($liquidacion && $liquidacion->LiquidacionDescuentoEstado !== 'ANULADO') {
                throw new DomainException('El consolidado ya tiene su liquidación de descuentos.');
            }

            if ($liquidacion) {
                DetalleLiquidacion::query()->where('LiquidacionDescuentoId', $liquidacion->LiquidacionDescuentoId)->delete();
                $liquidacion->update(['LiquidacionDescuentoEstado' => 'GENERADO', 'LiquidacionDescuentoFechaGeneracion' => now(), 'LiquidacionDescuentoImporteTotal' => 0]);
            } else {
                $liquidacion = LiquidacionDescuento::create(['ConsolidadoAsistenciaId' => $consolidado->ConsolidadoAsistenciaId]);
            }

            foreach ($this->lineasDelConsolidado($consolidado) as $linea) {
                DetalleLiquidacion::create($linea + ['LiquidacionDescuentoId' => $liquidacion->LiquidacionDescuentoId]);
            }

            return $liquidacion->fresh();
        });
    }

    /** Aprobar exige que cada descuento tenga su importe: sin la remuneracion no se puede descontar. */
    public function aprobar(LiquidacionDescuento $liquidacion): LiquidacionDescuento
    {
        if ($liquidacion->LiquidacionDescuentoEstado !== 'GENERADO') {
            throw new DomainException('Solo se pueden aprobar liquidaciones generadas.');
        }
        $lineas = $liquidacion->detalles()->with('concepto')->get();
        if ($lineas->isEmpty()) {
            throw new DomainException('La liquidación no tiene líneas que aprobar.');
        }
        $sinImporte = $lineas->first(fn (DetalleLiquidacion $l) => (float) $l->DetalleLiquidacionCantidad > 0 && (float) $l->DetalleLiquidacionImporte <= 0);
        if ($sinImporte) {
            throw new DomainException("No se puede aprobar: la línea «{$sinImporte->concepto->ConceptoDescuentoNombre}» no tiene importe.");
        }
        $liquidacion->update(['LiquidacionDescuentoEstado' => 'APROBADO']);

        return $liquidacion->fresh();
    }

    /** Remitir = enviar a la planilla unica de pagos; ya no se modifica ni se anula. */
    public function remitir(LiquidacionDescuento $liquidacion): LiquidacionDescuento
    {
        if ($liquidacion->LiquidacionDescuentoEstado !== 'APROBADO') {
            throw new DomainException('Solo se pueden remitir a planilla liquidaciones aprobadas.');
        }
        $liquidacion->update(['LiquidacionDescuentoEstado' => 'REMITIDO']);

        return $liquidacion->fresh();
    }

    /** Anular es idempotente; una liquidacion ya remitida a planilla no se anula. */
    public function anular(LiquidacionDescuento $liquidacion): LiquidacionDescuento
    {
        if ($liquidacion->LiquidacionDescuentoEstado === 'REMITIDO') {
            throw new DomainException('No se puede anular una liquidación ya remitida a planilla.');
        }
        if ($liquidacion->LiquidacionDescuentoEstado !== 'ANULADO') {
            $liquidacion->update(['LiquidacionDescuentoEstado' => 'ANULADO']);
        }

        return $liquidacion->fresh();
    }

    /** Las lineas se editan y se quitan solo mientras la liquidacion esta GENERADA. */
    public static function motivoDeBloqueo(LiquidacionDescuento $liquidacion): ?string
    {
        return $liquidacion->LiquidacionDescuentoEstado === 'GENERADO' ? null
            : 'La liquidación está '.strtolower(match ($liquidacion->LiquidacionDescuentoEstado) {
                'APROBADO' => 'aprobada', 'REMITIDO' => 'remitida', default => 'anulada',
            }).' y sus líneas ya no se pueden modificar.';
    }

    public function eliminarLinea(DetalleLiquidacion $linea): void
    {
        if ($motivo = self::motivoDeBloqueo($linea->liquidacion)) {
            throw new DomainException($motivo);
        }
        $linea->delete();
    }

    /**
     * Lineas sugeridas por el consolidado: dias de falta injustificada y horas de tardanza (las tardanzas se acumulan en
     * horas, RIT Art. 25).
     *
     * @return list<array<string,mixed>>
     */
    private function lineasDelConsolidado(ConsolidadoAsistencia $consolidado): array
    {
        $vinculo = DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $consolidado->VinculoLaboralId)->first(['VinculoLaboralId', 'VinculoLaboralIngresoMensual']);
        $ingreso = (float) ($vinculo->VinculoLaboralIngresoMensual ?? 0);
        $valorDia = $ingreso / 30;
        $valorHora = $valorDia / (DiasHabiles::esSede((int) $consolidado->VinculoLaboralId) ? 8 : 6);
        $nota = $ingreso > 0 ? '' : ' Sin ingreso registrado: completar el importe.';

        $lineas = [];
        $dias = (float) $consolidado->ConsolidadoAsistenciaDiasFalta;
        if ($dias > 0) {
            $lineas[] = [
                'ConceptoDescuentoId' => $this->concepto('DESC_FALTA')->ConceptoDescuentoId,
                'DetalleLiquidacionCantidad' => $dias,
                'DetalleLiquidacionImporte' => round($dias * $valorDia, 2),
                'DetalleLiquidacionObservacion' => 'Días de falta injustificada. Valor del día = ingreso total / 30 (RIT, Art. 25).'.$nota,
            ];
        }
        $minutos = (int) $consolidado->ConsolidadoAsistenciaMinutosTardanza;
        $horas = self::horasDeTardanza($minutos);
        if ($horas > 0) {
            $lineas[] = [
                'ConceptoDescuentoId' => $this->concepto('DESC_TARDANZA')->ConceptoDescuentoId,
                'DetalleLiquidacionCantidad' => $horas,
                'DetalleLiquidacionImporte' => round($horas * $valorHora, 2),
                'DetalleLiquidacionObservacion' => "Horas de tardanza ({$minutos} minutos). Valor de la hora = valor del día / jornada diaria (RIT, Art. 22 y 25).".$nota,
            ];
        }
        $sinGoce = (int) $consolidado->ConsolidadoAsistenciaMinutosSinGoce;
        if ($sinGoce > 0) {
            $lineas[] = [
                'ConceptoDescuentoId' => $this->concepto('DESC_PERMISO')->ConceptoDescuentoId,
                'DetalleLiquidacionCantidad' => round($sinGoce / 60, 2),
                'DetalleLiquidacionImporte' => round($sinGoce / 60 * $valorHora, 2),
                'DetalleLiquidacionObservacion' => "Permisos particulares sin goce ({$sinGoce} minutos, RIT, Art. 62).".$nota,
            ];
        }

        return $lineas;
    }

    private function concepto(string $codigo): ConceptoDescuento
    {
        return ConceptoDescuento::query()->where('ConceptoDescuentoCodigo', $codigo)->first()
            ?? throw new DomainException("Falta el concepto de descuento {$codigo} en el catálogo.");
    }
}
