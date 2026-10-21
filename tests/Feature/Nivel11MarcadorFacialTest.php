<?php

namespace Tests\Feature;

use App\Services\MarcacionFacialService;
use App\Support\ClaveDeDispositivo;
use App\Support\HoraLocal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Marcador facial (kiosco aparte): el rostro se registra con el endpoint de plantillas de siempre (con consentimiento) y
 * el kiosco, autenticado con su clave de dispositivo, envia el descriptor; el backend reconoce y registra la marca.
 */
class Nivel11MarcadorFacialTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private const DISPOSITIVO = 'DISP-PRUEBA-FAC';

    private int $vinculo;

    /** @var list<float> */
    private array $rostro;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Biometria.DispositivoMarcacion')->insert([
            'EessId' => $this->idSembrado('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']),
            'DispositivoMarcacionCodigo' => self::DISPOSITIVO, 'DispositivoMarcacionNombre' => 'Kiosco de prueba', 'DispositivoMarcacionTipo' => 'Facial',
        ]);
        $trabajador = $this->nuevoTrabajador();
        $this->vinculo = $this->nuevoVinculo([], $trabajador);
        DB::table('Biometria.ConsentimientoBiometrico')->insert(['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => 1, 'ConsentimientoBiometricoVersion' => 'v1']);
        $this->rostro = $this->vector();

        // Registro del rostro: el mismo endpoint que usan las pantallas de biometria (el kiosco lo llama con sesion de RRHH).
        $this->postJson('/api/plantillas-biometricas', [
            'TrabajadorId' => $trabajador, 'PlantillaBiometricaTipo' => 'ROSTRO', 'PlantillaBiometricaReferencia' => MarcacionFacialService::codificar($this->rostro),
        ])->assertCreated();
    }

    /** @return list<float> */
    private function vector(): array
    {
        return array_map(fn () => mt_rand(-200, 200) / 1000, range(1, MarcacionFacialService::DIMENSION));
    }

    /** El mismo rostro en otra foto: el descriptor cambia un poco. */
    private function otraFoto(array $rostro): array
    {
        return array_map(fn (float $v) => $v + mt_rand(-10, 10) / 1000, $rostro);
    }

    private function marcar(array $descriptor, ?string $clave = null)
    {
        return $this->withHeaders(['X-Dispositivo' => self::DISPOSITIVO, 'X-Clave-Dispositivo' => $clave ?? ClaveDeDispositivo::para(self::DISPOSITIVO)])
            ->postJson('/api/marcador-facial/marcar', ['descriptor' => $descriptor]);
    }

    public function test_el_kiosco_sin_su_clave_no_puede_marcar(): void
    {
        $this->marcar($this->rostro, 'clave-equivocada')->assertUnauthorized();
        $this->withHeaders(['X-Dispositivo' => self::DISPOSITIVO, 'X-Clave-Dispositivo' => ClaveDeDispositivo::para(self::DISPOSITIVO)])
            ->getJson('/api/marcador-facial/estado')->assertOk()->assertJsonPath('dispositivo.nombre', 'Kiosco de prueba');

        DB::table('Biometria.DispositivoMarcacion')->where('DispositivoMarcacionCodigo', self::DISPOSITIVO)->update(['DispositivoMarcacionEstado' => 0]);
        $this->marcar($this->rostro)->assertForbidden();
    }

    public function test_reconoce_el_rostro_y_registra_la_entrada_con_el_dispositivo_y_la_plantilla(): void
    {
        $this->marcar($this->otraFoto($this->rostro))->assertOk()
            ->assertJsonPath('resultado', 'REGISTRADA')->assertJsonPath('tipo', 'ENTRADA');

        $marca = DB::table('Asistencia.Marcacion as m')->join('Biometria.MetodoMarcacion as mm', 'mm.MetodoMarcacionId', '=', 'm.MetodoMarcacionId')
            ->where('m.VinculoLaboralId', $this->vinculo)->first(['mm.MetodoMarcacionCodigo', 'm.DispositivoMarcacionId', 'm.PlantillaBiometricaId', 'm.MarcacionOrigen']);
        $this->assertSame('ROSTRO', $marca->MetodoMarcacionCodigo);
        $this->assertNotNull($marca->DispositivoMarcacionId);
        $this->assertNotNull($marca->PlantillaBiometricaId);
        $this->assertSame('DISPOSITIVO', $marca->MarcacionOrigen);

        // Seguir frente a la camara no marca la salida.
        $this->marcar($this->otraFoto($this->rostro))->assertOk()->assertJsonPath('resultado', 'REPETIDA');
        $this->assertSame(1, DB::table('Asistencia.Marcacion')->where('VinculoLaboralId', $this->vinculo)->count());
    }

    public function test_con_la_entrada_abierta_registra_la_salida(): void
    {
        DB::table('Asistencia.Marcacion')->insert([
            'VinculoLaboralId' => $this->vinculo, 'MetodoMarcacionId' => DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'ROSTRO')->value('MetodoMarcacionId'),
            'MarcacionFechaHora' => HoraLocal::ahora()->subHours(3)->toDateTimeString(), 'MarcacionTipo' => 'ENTRADA', 'MarcacionEsValida' => 1,
        ]);

        $this->marcar($this->otraFoto($this->rostro))->assertOk()->assertJsonPath('tipo', 'SALIDA');
    }

    public function test_un_rostro_desconocido_no_marca_y_queda_en_el_log(): void
    {
        $this->marcar($this->vector())->assertUnprocessable();

        $this->assertSame(0, DB::table('Asistencia.Marcacion')->where('VinculoLaboralId', $this->vinculo)->count());
        $this->assertTrue(DB::table('Soporte.LogIntegracion')->where('LogIntegracionSistemaExterno', 'MARCADOR_FACIAL')->where('LogIntegracionResultado', 'NO_RECONOCIDO')->exists());
    }

    public function test_sin_consentimiento_vigente_o_en_otro_establecimiento_no_se_reconoce(): void
    {
        $trabajador = (int) DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $this->vinculo)->value('TrabajadorId');
        DB::table('Biometria.ConsentimientoBiometrico')->insert(['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => 0, 'ConsentimientoBiometricoVersion' => 'v1']);
        $this->marcar($this->rostro)->assertUnprocessable();

        DB::table('Biometria.ConsentimientoBiometrico')->insert(['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => 1, 'ConsentimientoBiometricoVersion' => 'v1']);
        DB::table('Biometria.DispositivoMarcacion')->where('DispositivoMarcacionCodigo', self::DISPOSITIVO)
            ->update(['EessId' => DB::table('Organizacion.EstablecimientoSalud')->where('EessCodigo', '<>', 'EESS-LE-01')->value('EessId')]);
        $this->marcar($this->rostro)->assertUnprocessable();
    }
}
