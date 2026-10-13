<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los codigos no se escriben a mano: si no se envian se generan con las dos primeras letras del nombre y un correlativo;
 * uno escrito se respeta y nunca se pierde al editar. Los codigos tecnicos (permisos, parametros) siguen siendo obligatorios.
 */
class Nivel8CodigosTest extends TestCase
{
    use DatabaseTransactions;

    public function test_un_registro_sin_codigo_lo_recibe_automaticamente(): void
    {
        $a = $this->postJson('/api/microredes', ['MicroredNombre' => 'Microred Zeta de Prueba'])->assertCreated()->json('data');
        $b = $this->postJson('/api/microredes', ['MicroredNombre' => 'Microred Zafiro de Prueba'])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^MI-\d{3}$/', $a['codigo']);
        $this->assertMatchesRegularExpression('/^MI-\d{3}$/', $b['codigo']);
        $this->assertNotSame($a['codigo'], $b['codigo']);
    }

    public function test_un_codigo_escrito_se_respeta_y_editar_sin_codigo_no_lo_borra(): void
    {
        $m = $this->postJson('/api/microredes', ['MicroredNombre' => 'Microred Manual', 'MicroredCodigo' => 'MR-ZZ'])->assertCreated()->json('data');
        $this->assertSame('MR-ZZ', $m['codigo']);

        $this->patchJson("/api/microredes/{$m['id']}", ['MicroredNombre' => 'Microred Manual 2'])->assertOk()->assertJsonPath('data.codigo', 'MR-ZZ');
        $this->patchJson("/api/microredes/{$m['id']}", ['MicroredNombre' => 'Microred Manual 3', 'MicroredCodigo' => ''])->assertOk()->assertJsonPath('data.codigo', 'MR-ZZ');
    }

    public function test_los_codigos_tecnicos_siguen_siendo_obligatorios(): void
    {
        $this->postJson('/api/permisos', ['PermisoNombre' => 'Sin codigo'])->assertStatus(422)->assertJsonValidationErrors('PermisoCodigo');
    }
}
