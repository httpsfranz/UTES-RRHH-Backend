<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Revision del tester (2026-10-20): un registro no se desactiva "porque si". El modal unico de baja envia el motivo
 * elegido (MotivoBaja) con el DELETE o el PATCH y la auditoria del cambio lo conserva.
 */
class Nivel10MotivoDeBajaTest extends TestCase
{
    use DatabaseTransactions;

    private function ultimaAuditoria(string $tabla, int $id): object
    {
        return DB::table('Seguridad.Auditoria')->where('AuditoriaTabla', $tabla)->where('AuditoriaRegistroId', (string) $id)->orderByDesc('AuditoriaId')->first();
    }

    public function test_el_motivo_de_la_baja_queda_en_la_auditoria(): void
    {
        $cargo = (int) DB::table('Personal.Cargo')->where('CargoEstado', 1)->value('CargoId');

        $this->deleteJson("/api/cargos/{$cargo}", ['MotivoBaja' => 'Registro duplicado'])->assertOk();

        $auditoria = $this->ultimaAuditoria('Cargo', $cargo);
        $this->assertSame('UPDATE', $auditoria->AuditoriaOperacion);
        $this->assertSame('Motivo de la desactivación: Registro duplicado', $auditoria->AuditoriaDetalle);
    }

    public function test_una_edicion_comun_no_lleva_motivo(): void
    {
        $cargo = DB::table('Personal.Cargo')->where('CargoEstado', 1)->first();

        $this->patchJson("/api/cargos/{$cargo->CargoId}", ['CargoNombre' => $cargo->CargoNombre.' X'])->assertOk();

        $this->assertNull($this->ultimaAuditoria('Cargo', (int) $cargo->CargoId)->AuditoriaDetalle);
    }
}
