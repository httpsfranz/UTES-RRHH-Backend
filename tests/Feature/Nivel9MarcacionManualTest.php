<?php

namespace Tests\Feature;

use App\Support\HoraLocal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Control manual de asistencia desde el calendario (RIT, Art. 21): POST /api/asistencia-diaria/marcar registra la entrada
 * o la salida con la hora del servidor, y el calendario se puede ver por dia, semana o mes (vista + fecha).
 */
class Nivel9MarcacionManualTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function marcar(int $vinculo, string $tipo, array $extra = []): TestResponse
    {
        return $this->postJson('/api/asistencia-diaria/marcar', $extra + ['VinculoLaboralId' => $vinculo, 'MarcacionTipo' => $tipo]);
    }

    public function test_la_marca_usa_la_hora_del_servidor_y_no_la_que_envia_el_cliente(): void
    {
        $v = $this->nuevoVinculo();

        $respuesta = $this->marcar($v, 'ENTRADA', ['MarcacionFechaHora' => '2020-01-01 07:00:00'])->assertCreated();

        $marca = DB::table('Asistencia.Marcacion')->where('MarcacionId', $respuesta->json('marcacion_id'))->first();
        $this->assertSame(HoraLocal::hoy()->toDateString(), substr((string) $marca->MarcacionFechaHora, 0, 10));
        $this->assertSame('MANUAL', $marca->MarcacionOrigen);
        $this->assertSame('MANUAL', DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionId', $marca->MetodoMarcacionId)->value('MetodoMarcacionCodigo'));
        $this->assertStringContainsString('RIT, Art. 21', $marca->MarcacionObservacion);
        $this->assertSame(substr((string) $marca->MarcacionFechaHora, 11, 5), $respuesta->json('hora'));
    }

    public function test_no_se_marca_dos_veces_la_entrada_ni_la_salida_sin_entrada(): void
    {
        $v = $this->nuevoVinculo();

        $this->marcar($v, 'SALIDA')->assertUnprocessable()->assertJsonValidationErrors('MarcacionTipo');
        $this->marcar($v, 'ENTRADA')->assertCreated();
        $this->marcar($v, 'ENTRADA')->assertUnprocessable()->assertJsonValidationErrors('MarcacionTipo');
    }

    public function test_una_marca_posterior_a_ahora_no_cuenta_como_doble_marca(): void
    {
        $v = $this->nuevoVinculo();
        DB::table('Asistencia.Marcacion')->insert(['VinculoLaboralId' => $v, 'MetodoMarcacionId' => DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'MANUAL')->value('MetodoMarcacionId'),
            'MarcacionFechaHora' => HoraLocal::ahora()->addHours(3)->toDateTimeString(), 'MarcacionTipo' => 'ENTRADA', 'MarcacionOrigen' => 'MANUAL']);

        $this->marcar($v, 'ENTRADA')->assertCreated();
    }

    public function test_solo_quien_tiene_el_permiso_de_marcar_puede_hacerlo(): void
    {
        $v = $this->nuevoVinculo();
        $this->actuarComo('mquispe');   // trabajador

        $this->marcar($v, 'ENTRADA')->assertForbidden();
    }

    public function test_el_responsable_no_marca_personal_de_otro_establecimiento(): void
    {
        $otro = $this->nuevoVinculo(['EessId' => DB::table('Organizacion.EstablecimientoSalud')->where('EessCodigo', '<>', 'EESS-LE-01')->value('EessId')]);
        $this->actuarComo('rvargas');   // responsable de EESS-LE-01

        $this->marcar($otro, 'ENTRADA')->assertForbidden();
    }

    public function test_la_vista_dia_trae_las_marcas_y_si_se_puede_marcar(): void
    {
        $v = $this->nuevoVinculo();
        $this->marcar($v, 'ENTRADA')->assertCreated();

        $hoy = HoraLocal::hoy()->toDateString();
        $calendario = $this->getJson('/api/asistencia-diaria/calendario?'.http_build_query(['eess_id' => $this->eessId(), 'vista' => 'dia', 'fecha' => $hoy]))->assertOk()->json();

        $this->assertSame('dia', $calendario['periodo']['vista']);
        $this->assertCount(1, $calendario['periodo']['dias']);
        $this->assertTrue($calendario['puede_marcar']);
        $fila = collect($calendario['trabajadores'])->firstWhere('vinculo_id', $v);
        $this->assertSame('ENTRADA', $fila['marcas'][0]['tipo']);
        $this->assertNotNull($fila['entrada_abierta']);
    }

    public function test_la_vista_semana_va_de_lunes_a_domingo(): void
    {
        $periodo = $this->getJson('/api/asistencia-diaria/calendario?'.http_build_query(['eess_id' => $this->eessId(), 'vista' => 'semana', 'fecha' => '2026-10-15', 'buscar' => 'zzz']))
            ->assertOk()->json('periodo');

        $this->assertSame(['2026-10-12', '2026-10-18'], [$periodo['desde'], $periodo['hasta']]);
        $this->assertCount(7, $periodo['dias']);
        $this->assertSame('Semana del 12 al 18 de octubre de 2026', $periodo['etiqueta']);
    }

    public function test_un_dia_pasado_no_se_puede_marcar(): void
    {
        $calendario = $this->getJson('/api/asistencia-diaria/calendario?'.http_build_query(['eess_id' => $this->eessId(), 'vista' => 'dia', 'fecha' => '2026-10-01', 'buscar' => 'zzz']))->assertOk()->json();

        $this->assertFalse($calendario['puede_marcar']);
    }
}
