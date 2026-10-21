<?php

namespace Tests\Feature;

use App\Services\ImportacionHojaAsistenciaService;
use App\Support\HojaDeCalculo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Hojas de control de asistencia (RIT, disposiciones complementarias): los establecimientos sin reloj facial siguen usando
 * hojas en papel; se cargan desde Excel con la hoja firmada como sustento, quedan como una carga de asistencia manual y
 * anular la carga retira sus marcas.
 */
class Nivel12ImportacionHojaAsistenciaTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    /** Establecimiento sin reloj facial (EESS-LE-01 si lo tiene). */
    private const EESS = 'EESS-EP-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 18:00:00', 'America/Lima'));
    }

    private function vinculoConHorario(): int
    {
        $v = $this->nuevoVinculo(['EessId' => $this->eessId(self::EESS)]);
        DB::table('Personal.AsignacionHorario')->insert([
            'VinculoLaboralId' => $v, 'HorarioId' => $this->idPorCodigo('Configuracion.Horario', 'HorarioId', 'HorarioCodigo', 'HOR-ADM-LV'),
            'AsignacionHorarioFechaInicio' => '2026-01-01', 'AsignacionHorarioEstado' => 1,
        ]);

        return $v;
    }

    private function dni(int $vinculo): string
    {
        return (string) DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->where('v.VinculoLaboralId', $vinculo)->value('t.TrabajadorNumeroDocumento');
    }

    /** @param  list<list<string>>  $filas  [dni, fecha, entrada, salida] */
    private function excel(array $filas): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'hja').'.xlsx';
        $w = new Writer;
        $w->openToFile($ruta);
        $w->addRow(Row::fromValues(array_values(ImportacionHojaAsistenciaService::COLUMNAS)));
        foreach ($filas as [$dni, $fecha, $entrada, $salida]) {
            $w->addRow(Row::fromValues([$dni, '', $fecha, '', $entrada, $salida, '']));
        }
        $w->close();

        return new UploadedFile($ruta, 'hoja-octubre.xlsx', null, null, true);
    }

    private function subir(UploadedFile $archivo, array $extra = [], string $eess = self::EESS)
    {
        return $this->post('/api/cargas-asistencia-manual/importar', $extra + [
            'eess_id' => $this->eessId($eess), 'anio' => 2026, 'mes' => 10, 'archivo' => $archivo, 'DocumentoSustentoId' => $this->documentoId(),
        ], ['Accept' => 'application/json']);
    }

    private function marcas(int $vinculo)
    {
        return DB::table('Asistencia.Marcacion')->where('VinculoLaboralId', $vinculo)->where('MarcacionEsValida', 1)->orderBy('MarcacionFechaHora')
            ->get(['MarcacionTipo', 'MarcacionFechaHora', 'MarcacionOrigen', 'CargaAsistenciaManualId']);
    }

    public function test_solo_los_establecimientos_sin_reloj_facial_llevan_hojas(): void
    {
        $query = http_build_query(['anio' => 2026, 'mes' => 10]);

        $this->getJson("/api/cargas-asistencia-manual/plantilla?eess_id={$this->eessId('EESS-LE-01')}&{$query}")
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'reloj de reconocimiento facial'));
        $this->subir($this->excel([]), [], 'EESS-LE-01')->assertStatus(422);

        $v = $this->vinculoConHorario();
        $respuesta = $this->get("/api/cargas-asistencia-manual/plantilla?eess_id={$this->eessId(self::EESS)}&{$query}")->assertOk();
        $filas = collect(iterator_to_array(HojaDeCalculo::filas($respuesta->getFile()->getPathname(), 'xlsx'), false));
        $delTrabajador = $filas->filter(fn ($f) => HojaDeCalculo::texto($f[0]) === $this->dni($v));
        $this->assertCount(21, $delTrabajador, 'un dia por fila, del 1 al 21 (hoy)');
        $this->assertSame('05/10/2026', $delTrabajador->values()[4][2]);
    }

    public function test_validar_revisa_cada_fila_y_no_guarda_nada(): void
    {
        $v = $this->vinculoConHorario();
        $ajeno = $this->nuevoVinculo();   // de EESS-LE-01
        $dni = $this->dni($v);
        DB::table('Asistencia.Marcacion')->insert([
            'VinculoLaboralId' => $v, 'MetodoMarcacionId' => DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'MANUAL')->value('MetodoMarcacionId'),
            'MarcacionFechaHora' => '2026-10-07 07:31:00', 'MarcacionTipo' => 'ENTRADA', 'MarcacionOrigen' => 'MANUAL', 'MarcacionEsValida' => 1,
        ]);

        $r = $this->subir($this->excel([
            [$dni, '05/10/2026', '07:35', '15:40'],          // fila 2: valida (2 marcas)
            [$dni, '06/10/2026', '19:30', '07:30'],          // fila 3: guardia, la salida es del dia siguiente (2 marcas)
            [$dni, '07/10/2026', '07:40', ''],               // fila 4: ya tiene entrada ese dia
            [$dni, '08/10/2026', '7h', ''],                  // fila 5: hora no valida
            [$dni, '22/10/2026', '07:30', ''],               // fila 6: dia que aun no llega
            [$dni, '03/09/2026', '07:30', ''],               // fila 7: otro mes
            [$this->dni($ajeno), '05/10/2026', '07:30', ''], // fila 8: no es del establecimiento
            [$dni, '05/10/2026', '07:30', ''],               // fila 9: dia repetido en el archivo
            [$dni, '09/10/2026', '', ''],                    // sin marcas: no cuenta
        ]))->assertOk()->json();

        $this->assertSame([8, 4], [$r['filas'], $r['marcas']]);
        $this->assertSame(
            [4 => 'Hora de entrada', 5 => 'Hora de entrada', 6 => 'Fecha', 7 => 'Fecha', 8 => 'DNI', 9 => 'Fecha'],
            collect($r['errores'])->mapWithKeys(fn ($e) => [$e['fila'] => $e['campo']])->all(),
        );
        $this->assertCount(1, $this->marcas($v), 'validar no guarda');
        $this->assertFalse(DB::table('Asistencia.CargaAsistenciaManual')->where('CargaAsistenciaManualNombreArchivo', 'hoja-octubre.xlsx')->exists());
    }

    public function test_confirmar_registra_la_carga_sus_marcas_y_recalcula_la_asistencia_y_anularla_las_retira(): void
    {
        $v = $this->vinculoConHorario();
        $dni = $this->dni($v);

        $r = $this->subir($this->excel([[$dni, '05/10/2026', '07:35', '15:40'], [$dni, '06/10/2026', '19:30', '07:30']]), ['confirmar' => 1])->assertCreated()->json();

        $this->assertSame(4, $r['registradas']);
        $carga = DB::table('Asistencia.CargaAsistenciaManual')->where('CargaAsistenciaManualId', $r['carga_id'])->first();
        $this->assertSame(['PROCESADO', 4, $this->documentoId()], [$carga->CargaAsistenciaManualEstado, (int) $carga->CargaAsistenciaManualRegistros, (int) $carga->DocumentoSustentoId]);
        $marcas = $this->marcas($v);
        $this->assertSame(['2026-10-05 07:35:00', '2026-10-05 15:40:00', '2026-10-06 19:30:00', '2026-10-07 07:30:00'], $marcas->map(fn ($m) => substr((string) $m->MarcacionFechaHora, 0, 19))->all());
        $this->assertSame([ImportacionHojaAsistenciaService::ORIGEN], $marcas->pluck('MarcacionOrigen')->unique()->values()->all());
        $dia = DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $v)->whereDate('AsistenciaDiariaFecha', '2026-10-05')->first();
        $this->assertSame('2026-10-05 07:35:00', substr((string) $dia->AsistenciaDiariaHoraEntrada, 0, 19), 'la asistencia se recalcula sola');

        $this->deleteJson("/api/cargas-asistencia-manual/{$r['carga_id']}")->assertOk();

        $this->assertCount(0, $this->marcas($v));
        $dia = DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $v)->whereDate('AsistenciaDiariaFecha', '2026-10-05')->first();
        $this->assertNull($dia->AsistenciaDiariaHoraEntrada, 'anular la carga recalcula el dia sin sus marcas');
    }

    public function test_con_errores_hay_que_omitirlos_y_exige_el_sustento(): void
    {
        $dni = $this->dni($this->vinculoConHorario());
        $archivo = fn () => $this->excel([[$dni, '05/10/2026', '07:35', ''], [$dni, '06/10/2026', '25:00', '']]);

        $this->subir($archivo(), ['confirmar' => 1])->assertStatus(422);
        $this->subir($archivo(), ['confirmar' => 1, 'omitir_errores' => 1])->assertCreated()->assertJsonPath('registradas', 1);
        $this->subir($archivo(), ['DocumentoSustentoId' => ''])->assertStatus(422)->assertJsonValidationErrors(['DocumentoSustentoId']);
    }

    public function test_solo_quien_edita_la_asistencia_de_ese_establecimiento_importa(): void
    {
        $this->actuarComo('mquispe');

        $this->subir($this->excel([]))->assertForbidden();
    }
}
