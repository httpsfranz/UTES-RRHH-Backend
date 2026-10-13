<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Consulta de DNI para autocompletar el alta de un trabajador: el proveedor externo (Decolecta) se simula con Http::fake.
 * Lo que importa: si falla o no esta configurado, el formulario no se rompe (`disponible: false`).
 */
class Nivel8ConsultaDniTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.consulta_dni.proveedor' => 'decolecta', 'services.consulta_dni.decolecta.token' => 'token-de-prueba']);
    }

    private function dni(): string
    {
        $dni = (string) random_int(70000000, 79999999);
        Cache::forget("consulta-dni:{$dni}");

        return $dni;
    }

    public function test_devuelve_nombres_y_apellidos_del_proveedor(): void
    {
        $dni = $this->dni();
        Http::fake(['api.decolecta.com/*' => Http::response(['first_name' => 'ROXANA KARINA', 'first_last_name' => 'DELGADO', 'second_last_name' => 'HUAMANI', 'full_name' => 'DELGADO HUAMANI ROXANA KARINA', 'document_number' => $dni])]);

        $r = $this->getJson("/api/consulta-dni/{$dni}")->assertOk()->json();

        $this->assertTrue($r['disponible']);
        $this->assertTrue($r['encontrado']);
        $this->assertSame(['nombres' => 'ROXANA KARINA', 'apellido_paterno' => 'DELGADO', 'apellido_materno' => 'HUAMANI'], $r['datos']);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer token-de-prueba') && $req['numero'] === $dni);
    }

    public function test_el_resultado_se_guarda_y_no_repite_la_consulta(): void
    {
        $dni = $this->dni();
        Http::fake(['api.decolecta.com/*' => Http::response(['first_name' => 'ANA', 'first_last_name' => 'RAMOS', 'second_last_name' => ''])]);

        $this->getJson("/api/consulta-dni/{$dni}")->assertOk();
        $this->getJson("/api/consulta-dni/{$dni}")->assertOk();

        Http::assertSentCount(1);
    }

    public function test_un_dni_inexistente_se_informa_sin_error(): void
    {
        $dni = $this->dni();
        Http::fake(['api.decolecta.com/*' => Http::response(['error' => 'not found'], 404)]);

        $r = $this->getJson("/api/consulta-dni/{$dni}")->assertOk()->json();

        $this->assertTrue($r['disponible']);
        $this->assertFalse($r['encontrado']);
    }

    public function test_si_el_proveedor_falla_el_formulario_sigue_funcionando(): void
    {
        foreach ([401, 429, 500] as $codigo) {
            $dni = $this->dni();
            Http::fake(['api.decolecta.com/*' => Http::response(['error' => 'x'], $codigo)]);
            $r = $this->getJson("/api/consulta-dni/{$dni}")->assertOk()->json();
            $this->assertFalse($r['disponible'], "codigo {$codigo}");
            $this->assertNull($r['datos']);
        }

        Http::fake(['api.decolecta.com/*' => fn () => throw new ConnectionException('sin red')]);
        $this->assertFalse($this->getJson('/api/consulta-dni/'.$this->dni())->assertOk()->json('disponible'));
    }

    public function test_sin_token_o_sin_proveedor_no_se_consulta_nada(): void
    {
        Http::fake();
        config(['services.consulta_dni.decolecta.token' => null]);
        $this->assertFalse($this->getJson('/api/consulta-dni/'.$this->dni())->assertOk()->json('disponible'));

        config(['services.consulta_dni.proveedor' => 'ninguno', 'services.consulta_dni.decolecta.token' => 'x']);
        $this->assertFalse($this->getJson('/api/consulta-dni/'.$this->dni())->assertOk()->json('disponible'));
        Http::assertNothingSent();
    }

    public function test_avisa_si_el_dni_ya_esta_registrado_como_trabajador(): void
    {
        Http::fake(['api.decolecta.com/*' => Http::response(['first_name' => 'JUAN', 'first_last_name' => 'PEREZ', 'second_last_name' => 'RUIZ'])]);
        $existente = (string) DB::table('Personal.Trabajador as t')->join('Personal.TipoDocumentoIdentidad as d', 'd.TipoDocumentoIdentidadId', '=', 't.TipoDocumentoIdentidadId')
            ->where('d.TipoDocumentoIdentidadCodigo', 'DNI')->value('t.TrabajadorNumeroDocumento');
        Cache::forget("consulta-dni:{$existente}");

        $this->assertTrue($this->getJson("/api/consulta-dni/{$existente}")->assertOk()->json('existe_en_sistema'));
    }

    public function test_rechaza_un_dni_mal_formado_y_exige_permiso(): void
    {
        $this->getJson('/api/consulta-dni/123')->assertStatus(422);
        $this->actuarComo('mquispe');
        $this->getJson('/api/consulta-dni/'.$this->dni())->assertForbidden();
    }
}
