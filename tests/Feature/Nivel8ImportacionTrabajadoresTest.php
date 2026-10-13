<?php

namespace Tests\Feature;

use App\Services\ImportacionTrabajadoresService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Alta masiva de trabajadores desde Excel: la plantilla se descarga, el archivo se valida fila por fila sin guardar,
 * y al confirmar se guardan las filas validas (todas, o solo las validas si se pide omitir las erroneas).
 */
class Nivel8ImportacionTrabajadoresTest extends TestCase
{
    use DatabaseTransactions;

    /** @param  list<list<mixed>>  $filas */
    private function excel(array $filas, bool $conCabecera = true): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $w = new Writer;
        $w->openToFile($ruta);
        if ($conCabecera) {
            $w->addRow(Row::fromValues(array_values(ImportacionTrabajadoresService::COLUMNAS)));
        }
        foreach ($filas as $fila) {
            $w->addRow(Row::fromValues($fila));
        }
        $w->close();

        return new UploadedFile($ruta, 'trabajadores.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function fila(string $dni, string $nombres = 'JUAN CARLOS', string $extra = ''): array
    {
        return ['DNI', $dni, 'QUISPE', 'MAMANI', $nombres, 'M', '1990-05-17', "juan{$dni}{$extra}@correo.pe", '987654321', 'Av. España 123', ''];
    }

    private function subir(UploadedFile $archivo, array $extra = [])
    {
        return $this->post('/api/trabajadores/importar', ['archivo' => $archivo] + $extra, ['Accept' => 'application/json']);
    }

    public function test_la_plantilla_se_descarga_como_excel(): void
    {
        $r = $this->get('/api/trabajadores/plantilla')->assertOk();
        $this->assertStringContainsString('plantilla_trabajadores.xlsx', $r->headers->get('Content-Disposition'));
    }

    public function test_validar_no_guarda_nada_y_senala_cada_error_con_su_fila(): void
    {
        $existente = (string) DB::table('Personal.Trabajador')->value('TrabajadorNumeroDocumento');
        $antes = DB::table('Personal.Trabajador')->count();

        $r = $this->subir($this->excel([
            $this->fila('88000001'),                               // fila 2: valida
            $this->fila('88000002', 'JUAN 3'),                     // fila 3: nombre con numero
            ['DNI', '123', 'PEREZ', '', 'ANA', 'F', '', '', '', '', ''], // fila 4: DNI corto
            $this->fila($existente),                               // fila 5: ya existe
            $this->fila('88000001'),                               // fila 6: repetida en el archivo
            ['XX', '88000009', 'PEREZ', '', 'ANA', 'F', '', '', '', '', ''], // fila 7: tipo invalido
        ]))->assertOk()->json();

        $this->assertSame(6, $r['total']);
        $this->assertSame(1, $r['validas']);
        $this->assertSame(5, $r['filas_con_error']);
        $this->assertSame([3, 4, 5, 6, 7], array_values(array_unique(array_column($r['errores'], 'fila'))));
        $this->assertSame($antes, DB::table('Personal.Trabajador')->count(), 'validar no guarda');
    }

    public function test_confirmar_con_errores_exige_omitirlos(): void
    {
        $archivo = fn () => $this->excel([$this->fila('88000011'), ['DNI', '1', 'X', '', 'Y', '', '', '', '', '', '']]);

        $this->subir($archivo(), ['confirmar' => 1])->assertStatus(422);
        $this->assertFalse(DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '88000011')->exists());

        $r = $this->subir($archivo(), ['confirmar' => 1, 'omitir_errores' => 1])->assertCreated()->json();
        $this->assertSame(1, $r['importados']);
        $this->assertTrue(DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '88000011')->exists());
    }

    public function test_importa_muchos_trabajadores_con_catalogos_y_ceros_a_la_izquierda(): void
    {
        $profesion = (string) DB::table('Personal.Profesion')->where('ProfesionEstado', 1)->value('ProfesionNombre');
        $filas = [];
        for ($i = 0; $i < 1500; $i++) {
            $filas[] = ['dni', (string) (87000000 + $i), 'GARCIA', 'LOPEZ', 'MARIA '.chr(65 + $i % 26).chr(65 + intdiv($i, 26) % 26), 'Femenino', '15/03/1985', '', '', '', $profesion];
        }
        $filas[] = ['DNI', 1234567, 'CERO', '', 'IZQUIERDA', '', '', '', '', '', '']; // numero sin el 0 inicial: 01234567

        $r = $this->subir($this->excel($filas), ['confirmar' => 1])->assertCreated()->json();

        $this->assertSame(1501, $r['importados']);
        $t = DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '87000010')->first();
        $this->assertSame('F', $t->TrabajadorSexo);
        $this->assertSame('1985-03-15', substr((string) $t->TrabajadorFechaNacimiento, 0, 10));
        $this->assertNotNull($t->ProfesionId);
        $this->assertTrue(DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '01234567')->exists());
    }

    public function test_un_archivo_sin_las_columnas_de_la_plantilla_se_rechaza_con_un_mensaje(): void
    {
        $r = $this->subir($this->excel([['a', 'b'], ['c', 'd']], false))->assertOk()->json();
        $this->assertSame(0, $r['validas']);
        $this->assertSame('Cabecera', $r['errores'][0]['campo']);
    }

    public function test_solo_quien_puede_editar_personal_importa(): void
    {
        $this->actuarComo('mquispe');
        $this->subir($this->excel([$this->fila('88000099')]))->assertForbidden();
    }
}
