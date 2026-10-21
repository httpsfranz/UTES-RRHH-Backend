<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Formulario unico del trabajador: datos personales + profesion ("Otro" la crea) + colegiaturas (un colegio que no existe
 * tambien se crea). Sin tipo de documento el alta es con DNI.
 */
class Nivel9TrabajadorFormularioTest extends TestCase
{
    use DatabaseTransactions;

    private function datos(array $extra = []): array
    {
        return $extra + [
            'TrabajadorNumeroDocumento' => '7'.random_int(1000000, 9999999),
            'TrabajadorNombres' => 'Lucía',
            'TrabajadorApellidoPaterno' => 'Paredes',
            'TrabajadorApellidoMaterno' => 'Ríos',
        ];
    }

    public function test_sin_tipo_de_documento_el_alta_es_con_dni(): void
    {
        $this->postJson('/api/trabajadores', $this->datos())->assertCreated()->assertJsonPath('data.tipo_documento.codigo', 'DNI');
    }

    public function test_profesion_otro_se_crea_y_queda_en_el_catalogo(): void
    {
        $id = $this->postJson('/api/trabajadores', $this->datos(['ProfesionNueva' => 'Tecnólogo en Radiología']))
            ->assertCreated()->assertJsonPath('data.profesion.nombre', 'Tecnólogo en Radiología')->json('data.id');

        $this->assertDatabaseHas('Personal.Profesion', ['ProfesionNombre' => 'Tecnólogo en Radiología', 'ProfesionEstado' => 1]);
        // Escribirla otra vez (con otras mayusculas) reutiliza la misma, no la duplica.
        $this->postJson('/api/trabajadores', $this->datos(['ProfesionNueva' => 'tecnólogo en radiología']))->assertCreated();
        $this->assertSame(1, DB::table('Personal.Profesion')->whereRaw('LOWER(ProfesionNombre) = ?', ['tecnólogo en radiología'])->count());
        $this->assertNotNull($id);
    }

    public function test_las_colegiaturas_se_registran_en_el_mismo_formulario_y_un_colegio_nuevo_se_crea(): void
    {
        $id = $this->postJson('/api/trabajadores', $this->datos([
            'ProfesionNueva' => 'Biólogo',
            'Colegiaturas' => [['ColegiaturaTipoNuevo' => 'Colegio de Biólogos del Perú', 'ColegiaturaNumero' => 'cbp12345', 'ColegiaturaEsPrincipal' => true]],
        ]))->assertCreated()
            ->assertJsonPath('data.colegiaturas.0.numero', 'CBP12345')
            ->assertJsonPath('data.colegiaturas.0.tipo_nombre', 'Colegio de Biólogos del Perú')
            ->json('data.id');

        $tipo = DB::table('Personal.ColegiaturaTipo')->where('ColegiaturaTipoNombre', 'Colegio de Biólogos del Perú')->first();
        $this->assertSame((int) DB::table('Personal.Trabajador')->where('TrabajadorId', $id)->value('ProfesionId'), (int) $tipo->ProfesionId);
        $this->assertStringStartsWith('CBP', $tipo->ColegiaturaTipoCodigo);

        // Editar sin la colegiatura la da de baja (no se borra).
        $this->patchJson("/api/trabajadores/{$id}", ['Colegiaturas' => []])->assertOk()->assertJsonCount(0, 'data.colegiaturas');
        $this->assertDatabaseHas('Personal.Colegiatura', ['TrabajadorId' => $id, 'ColegiaturaEstado' => 0]);
    }

    public function test_el_colegio_debe_ser_de_la_profesion_y_solo_una_principal(): void
    {
        $medico = (int) DB::table('Personal.ColegiaturaTipo')->where('ColegiaturaTipoCodigo', 'CMP')->value('ColegiaturaTipoId');
        $enfermeria = (int) DB::table('Personal.Profesion')->where('ProfesionId', '<>', DB::table('Personal.ColegiaturaTipo')->where('ColegiaturaTipoId', $medico)->value('ProfesionId'))->value('ProfesionId');

        $this->postJson('/api/trabajadores', $this->datos([
            'ProfesionId' => $enfermeria,
            'Colegiaturas' => [['ColegiaturaTipoId' => $medico, 'ColegiaturaNumero' => '99887']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('Colegiaturas.0.ColegiaturaTipoId');

        $this->postJson('/api/trabajadores', $this->datos([
            'ProfesionNueva' => 'Químico Farmacéutico',
            'Colegiaturas' => [
                ['ColegiaturaTipoNuevo' => 'Colegio Químico Farmacéutico', 'ColegiaturaNumero' => '111', 'ColegiaturaEsPrincipal' => true],
                ['ColegiaturaTipoNuevo' => 'Otro Colegio Regional', 'ColegiaturaNumero' => '222', 'ColegiaturaEsPrincipal' => true],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors('Colegiaturas');
    }
}
