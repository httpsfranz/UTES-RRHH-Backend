<?php

namespace Tests\Feature;

use App\Support\HojaDeCalculo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Programacion mensual desde Excel (RIT, Art. 16): la plantilla es la grilla del mes ya llena; cada fila del archivo
 * reemplaza el mes de ese trabajador con las mismas reglas que pintar la grilla, y al validar nada queda guardado.
 */
class Nivel12ImportacionProgramacionTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private const MES = ['anio' => 2026, 'mes' => 12];

    private function dni(int $vinculo): string
    {
        return (string) DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->where('v.VinculoLaboralId', $vinculo)->value('t.TrabajadorNumeroDocumento');
    }

    /**
     * Archivo con la cabecera de la plantilla y una fila por trabajador.
     *
     * @param  array<int,array<int,string>>  $filas  vinculo => [dia => codigos]
     */
    private function excel(array $filas): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'prg').'.xlsx';
        $w = new Writer;
        $w->openToFile($ruta);
        $w->addRow(Row::fromValues(['DNI', 'Trabajador', 'Cargo', ...range(1, 31)]));
        foreach ($filas as $vinculo => $dias) {
            $w->addRow(Row::fromValues([$this->dni($vinculo), '', '', ...array_map(fn ($d) => $dias[$d] ?? '', range(1, 31))]));
        }
        $w->close();

        return new UploadedFile($ruta, 'programacion.xlsx', null, null, true);
    }

    private function subir(UploadedFile $archivo, array $extra = [])
    {
        return $this->post('/api/programacion-mensual/importar', ['eess_id' => $this->eessId(), 'archivo' => $archivo] + self::MES + $extra, ['Accept' => 'application/json']);
    }

    /** @return list<string> codigos programados ese dia */
    private function codigos(int $vinculo, string $fecha): array
    {
        $grilla = $this->getJson('/api/programacion-mensual?'.http_build_query(['eess_id' => $this->eessId()] + self::MES))->json();

        return array_column(collect($grilla['trabajadores'])->firstWhere('vinculo_id', $vinculo)['celdas'][$fecha]['turnos'], 'codigo');
    }

    private function pintar(int $vinculo, string $fecha, array $codigos): void
    {
        $this->putJson('/api/programacion-mensual/celda', ['eess_id' => $this->eessId(), 'VinculoLaboralId' => $vinculo, 'Fecha' => $fecha, 'TurnoIds' => array_map(fn ($c) => $this->turnoId($c), $codigos)])->assertOk();
    }

    public function test_la_plantilla_trae_al_personal_con_lo_ya_programado_y_las_ausencias_entre_parentesis(): void
    {
        $v = $this->nuevoVinculo();
        $this->pintar($v, '2026-12-07', ['M', 'T']);
        $this->rolVacacional($this->periodoVacacional($v), '2026-12-14', 3);

        $respuesta = $this->get('/api/programacion-mensual/plantilla?'.http_build_query(['eess_id' => $this->eessId()] + self::MES))->assertOk();
        $this->assertStringContainsString('programacion_EESS-LE-01_2026-12.xlsx', $respuesta->headers->get('Content-Disposition'));

        $filas = iterator_to_array(HojaDeCalculo::filas($respuesta->getFile()->getPathname(), 'xlsx'), false);
        $cabecera = $filas[2];
        $fila = collect($filas)->first(fn ($f) => HojaDeCalculo::texto($f[0]) === $this->dni($v));
        $this->assertSame('DNI', $cabecera[0]);
        $this->assertSame('M+T', $fila[array_search(7, $cabecera, true)]);
        $this->assertMatchesRegularExpression('/^\(.+\)$/', $fila[array_search(14, $cabecera, true)]);
    }

    public function test_validar_ensaya_cada_dia_con_las_reglas_de_la_grilla_y_no_guarda_nada(): void
    {
        $v = $this->nuevoVinculo();   // regimen "OTRO": no hace guardia

        $r = $this->subir($this->excel([$v => [7 => 'M', 8 => 'm + t', 9 => 'X', 10 => 'N']]))->assertOk()->json();

        $this->assertSame([1, 3, 2], [$r['filas'], $r['cambios'], $r['validos']]);
        $this->assertSame(['Día 10', 'Día 9'], array_column($r['errores'], 'campo'));
        $this->assertStringContainsString('guardia', $r['errores'][0]['mensaje']);
        $this->assertSame([], $this->codigos($v, '2026-12-07'), 'validar no guarda');
    }

    public function test_cada_fila_reemplaza_el_mes_del_trabajador_y_no_toca_a_los_demas(): void
    {
        $v = $this->nuevoVinculo();
        $otro = $this->nuevoVinculo();
        $this->pintar($v, '2026-12-07', ['T']);
        $this->pintar($v, '2026-12-14', ['M']);
        $this->pintar($otro, '2026-12-07', ['M']);

        $r = $this->subir($this->excel([$v => [7 => 'M', 8 => 'M/T']]), ['confirmar' => 1])->assertCreated()->json();

        $this->assertSame(3, $r['aplicados']);
        $this->assertSame(['M'], $this->codigos($v, '2026-12-07'));
        $this->assertSame(['M', 'T'], $this->codigos($v, '2026-12-08'));
        $this->assertSame([], $this->codigos($v, '2026-12-14'), 'el dia vacio en el archivo queda libre');
        $this->assertSame(['M'], $this->codigos($otro, '2026-12-07'), 'quien no esta en el archivo no se toca');
    }

    public function test_con_errores_hay_que_omitirlos_y_la_celda_que_falla_queda_como_estaba(): void
    {
        $v = $this->nuevoVinculo();
        $this->pintar($v, '2026-12-09', ['M']);
        $archivo = fn () => $this->excel([$v => [7 => 'M', 9 => 'N']]);   // la guardia no procede: el 9 debe conservar su M

        $this->subir($archivo(), ['confirmar' => 1])->assertStatus(422);
        $this->assertSame([], $this->codigos($v, '2026-12-07'));

        $r = $this->subir($archivo(), ['confirmar' => 1, 'omitir_errores' => 1])->assertCreated()->json();
        $this->assertSame(1, $r['aplicados']);
        $this->assertSame(['M'], $this->codigos($v, '2026-12-07'));
        $this->assertSame(['M'], $this->codigos($v, '2026-12-09'));
    }

    public function test_rechaza_personal_ajeno_dias_con_ausencia_y_meses_publicados(): void
    {
        $v = $this->nuevoVinculo();
        $ajeno = $this->nuevoVinculo(['EessId' => $this->eessId('EESS-LE-02')]);
        $this->rolVacacional($this->periodoVacacional($v), '2026-12-14', 3);

        $r = $this->subir($this->excel([$v => [14 => 'M', 15 => '(VP)'], $ajeno => [7 => 'M']]))->assertOk()->json();
        $errores = collect($r['errores'])->pluck('mensaje', 'campo');
        $this->assertEqualsCanonicalizing(['Día 14', 'DNI'], $errores->keys()->all());
        $this->assertStringContainsString('vacaciones programadas', $errores['Día 14']);
        $this->assertStringContainsString('No figura en el personal', $errores['DNI']);
        $this->assertSame(0, $r['cambios'], 'el texto entre parentesis de un dia con ausencia se ignora');

        $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2027-01-01');
        $this->post('/api/programacion-mensual/importar', ['eess_id' => $this->eessId(), 'anio' => 2027, 'mes' => 1, 'archivo' => $this->excel([$v => [4 => 'M']])], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'publicada'));
    }

    public function test_solo_quien_puede_editar_la_programacion_importa(): void
    {
        $v = $this->nuevoVinculo();
        $this->actuarComo('mquispe');

        $this->subir($this->excel([$v => [7 => 'M']]))->assertForbidden();
    }
}
