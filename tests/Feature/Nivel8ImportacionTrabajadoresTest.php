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

    /**
     * Fila con vinculo: persona + Establecimiento, Regimen, Condicion, Cargo, Inicio, AIRHSP, Plaza, Horario.
     *
     * @param  array<string,string>  $vinculo  cambios sobre el vinculo valido por defecto
     */
    private function filaConVinculo(string $dni, array $vinculo = []): array
    {
        $vinculo = array_merge(['eess' => 'EESS-LE-01', 'regimen' => 'DL1057', 'condicion' => 'CAS', 'cargo' => 'Enfermero(a)', 'inicio' => '02/01/2026', 'airhsp' => "A{$dni}", 'plaza' => '', 'horario' => 'HOR-ADM-LV'], $vinculo);

        return [...$this->fila($dni, 'ANA LUCIA', 'v'), ...array_values($vinculo)];
    }

    private function subir(UploadedFile $archivo, array $extra = [])
    {
        return $this->post('/api/trabajadores/importar', ['archivo' => $archivo] + $extra, ['Accept' => 'application/json']);
    }

    private function vinculosDe(string $dni)
    {
        return DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->where('t.TrabajadorNumeroDocumento', $dni)->get(['v.*']);
    }

    public function test_con_las_columnas_del_vinculo_registra_persona_vinculo_y_horario(): void
    {
        $r = $this->subir($this->excel([
            $this->filaConVinculo('88100001'),
            $this->filaConVinculo('88100002', ['condicion' => 'Destacado', 'airhsp' => '', 'horario' => '']), // sin AIRHSP (no lo exige) ni horario
            $this->fila('88100003'),                                                                       // solo la persona
        ]), ['confirmar' => 1])->assertCreated()->json();

        $this->assertSame([3, 2, 1, 2, 1], [$r['importados'], $r['con_vinculo'], $r['con_horario'], $r['vinculos'], $r['horarios']]);
        $vinculo = $this->vinculosDe('88100001')->sole();
        $this->assertSame(DB::table('Organizacion.EstablecimientoSalud')->where('EessCodigo', 'EESS-LE-01')->value('EessId'), $vinculo->EessId);
        $this->assertSame('2026-01-02', substr((string) $vinculo->VinculoLaboralFechaInicio, 0, 10));
        $this->assertSame('A88100001', $vinculo->VinculoLaboralCodigoAirhsp);
        $this->assertNotNull($vinculo->VinculoLaboralCodigo, 'el codigo del vinculo se genera solo, como en el formulario');
        $asignacion = DB::table('Personal.AsignacionHorario')->where('VinculoLaboralId', $vinculo->VinculoLaboralId)->sole();
        $this->assertSame(DB::table('Configuracion.Horario')->where('HorarioCodigo', 'HOR-ADM-LV')->value('HorarioId'), $asignacion->HorarioId);
        $this->assertSame('2026-01-02', substr((string) $asignacion->AsignacionHorarioFechaInicio, 0, 10));
        $this->assertCount(1, $this->vinculosDe('88100002'));
        $this->assertCount(0, $this->vinculosDe('88100003'));
    }

    public function test_el_vinculo_y_el_horario_se_validan_con_las_reglas_del_formulario_sin_guardar_nada(): void
    {
        $vinculosAntes = DB::table('Personal.VinculoLaboral')->count();

        $r = $this->subir($this->excel([
            $this->filaConVinculo('88200001'),                                   // fila 2: valida
            $this->filaConVinculo('88200002', ['cargo' => '']),                   // fila 3: falta el cargo
            $this->filaConVinculo('88200003', ['eess' => 'C.S. INEXISTENTE']),    // fila 4: establecimiento desconocido
            $this->filaConVinculo('88200004', ['airhsp' => '']),                  // fila 5: CAS exige AIRHSP (VinculoLaboralRequest)
            $this->filaConVinculo('88200005', ['horario' => 'HOR-CSMC-ADM']),     // fila 6: horario de otro establecimiento (AsignacionHorarioRequest)
            [...$this->fila('88200006'), '', '', '', '', '', '', '', 'HOR-ADM-LV'], // fila 7: horario sin vinculo
        ]))->assertOk()->json();

        $this->assertSame(1, $r['validas']);
        $errores = collect($r['errores'])->mapWithKeys(fn ($e) => [$e['fila'] => $e['campo']]);
        $this->assertSame([3 => 'Cargo', 4 => 'Establecimiento', 5 => 'Código AIRHSP', 6 => 'Horario', 7 => 'Horario'], $errores->all());
        $this->assertSame($vinculosAntes, DB::table('Personal.VinculoLaboral')->count(), 'validar no guarda vinculos');
        $this->assertFalse(DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '88200001')->exists(), 'validar no guarda personas');
    }

    public function test_si_el_vinculo_no_es_valido_tampoco_se_registra_la_persona(): void
    {
        $archivo = fn () => $this->excel([
            $this->filaConVinculo('88300001'),
            $this->filaConVinculo('88300002', ['airhsp' => '']),
        ]);

        // Al confirmar sin omitir, un vinculo invalido impide guardar el archivo entero.
        $r = $this->subir($archivo(), ['confirmar' => 1])->assertStatus(422)->json();
        $this->assertSame(['fila' => 3, 'campo' => 'Código AIRHSP'], array_intersect_key($r['errores'][0], ['fila' => 0, 'campo' => 0]));
        $this->assertFalse(DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '88300001')->exists());

        $r = $this->subir($archivo(), ['confirmar' => 1, 'omitir_errores' => 1])->assertCreated()->json();

        $this->assertSame([1, 1], [$r['importados'], $r['vinculos']]);
        $this->assertFalse(DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '88300002')->exists());
    }

    public function test_a_un_trabajador_ya_registrado_solo_se_le_agrega_el_vinculo(): void
    {
        $this->subir($this->excel([$this->fila('88400001'), $this->fila('88400002')]), ['confirmar' => 1])->assertCreated();
        $antes = DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '88400001')->first();
        $soloDocumento = fn (string $dni, string $paterno = '', string $airhsp = '') => ['DNI', $dni, $paterno, '', '', '', '', '', '', '', '', ...array_slice($this->filaConVinculo($dni, ['airhsp' => $airhsp ?: "A{$dni}"]), 11)];

        $r = $this->subir($this->excel([
            $soloDocumento('88400001'),            // fila 2: se le agrega el vinculo
            $soloDocumento('88400002', 'PEREZ'),   // fila 3: el apellido no coincide con el registrado (QUISPE)
            $this->fila('88400002'),               // fila 4: ya existe y no trae vinculo
        ]), ['confirmar' => 1, 'omitir_errores' => 1])->assertCreated()->json();

        $this->assertSame([0, 1], [$r['importados'], $r['vinculos']]);
        $this->assertSame([3 => 'Apellido paterno', 4 => 'Número de documento'], collect($r['errores'])->mapWithKeys(fn ($e) => [$e['fila'] => $e['campo']])->all());
        $this->assertCount(1, $this->vinculosDe('88400001'));
        $this->assertEquals($antes, DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', '88400001')->first(), 'sus datos personales no cambian');

        // Un segundo vinculo en las mismas fechas es doble percepcion (RIT Art. 86)
        $r = $this->subir($this->excel([$soloDocumento('88400001', '', 'B88400001')]))->assertOk()->json();
        $this->assertSame(0, $r['validas']);
        $this->assertStringContainsString('Art. 86', $r['errores'][0]['mensaje']);
    }

    public function test_no_registra_vinculos_fuera_del_ambito_de_quien_importa(): void
    {
        $usuario = $this->actuarComo('gdangelo');
        $eessPropio = (int) DB::table('Organizacion.EstablecimientoSalud')->where('EessCodigo', 'EESS-LE-01')->value('EessId');
        DB::table('Seguridad.UsuarioAmbito')->where('UsuarioId', $usuario->UsuarioId)->update(['EessId' => $eessPropio]);

        $r = $this->subir($this->excel([
            $this->filaConVinculo('88500001'),
            $this->filaConVinculo('88500002', ['eess' => 'EESS-EP-02']),
        ]))->assertOk()->json();

        $this->assertSame(1, $r['validas']);
        $this->assertSame(['fila' => 3, 'campo' => 'Establecimiento'], array_intersect_key($r['errores'][0], ['fila' => 0, 'campo' => 0]));
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
