<?php

namespace Tests\Feature;

use App\Http\Requests\DocumentoSustentoRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Documentos de sustento: la subida de un archivo define nombre, tipo, extension, tamano y hash; el archivo se puede
 * ver y descargar; y un mismo archivo no se registra dos veces.
 */
class Nivel8DocumentosTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_subir_un_archivo_completa_sus_datos_y_permite_descargarlo(): void
    {
        $r = $this->post('/api/documentos-sustento', ['archivo' => UploadedFile::fake()->create('constancia.pdf', 12, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();

        $d = $r->json('data');
        $this->assertSame('constancia.pdf', $d['nombre']);
        $this->assertSame('pdf', $d['extension']);
        $this->assertSame('application/pdf', $d['tipo']);
        $this->assertSame(12 * 1024, $d['tamano_bytes']);
        $this->assertSame(64, strlen($d['hash']));
        $this->assertTrue($d['tiene_archivo']);
        Storage::disk('local')->assertExists(DB::table('Soporte.DocumentoSustento')->where('DocumentoSustentoId', $d['id'])->value('DocumentoSustentoRuta'));

        $this->get("/api/documentos-sustento/{$d['id']}/archivo")->assertOk();
        $descarga = $this->get("/api/documentos-sustento/{$d['id']}/archivo?descargar=1")->assertOk();
        $this->assertStringContainsString('attachment', $descarga->headers->get('Content-Disposition'));
    }

    public function test_el_mismo_archivo_no_se_registra_dos_veces(): void
    {
        $this->post('/api/documentos-sustento', ['archivo' => UploadedFile::fake()->create('a.pdf', 7, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/documentos-sustento', ['archivo' => UploadedFile::fake()->create('copia.pdf', 7, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_rechaza_archivos_demasiado_grandes_o_de_otro_tipo(): void
    {
        $limite = DocumentoSustentoRequest::limiteEnMb();
        $this->post('/api/documentos-sustento', ['archivo' => UploadedFile::fake()->create('grande.pdf', ($limite + 1) * 1024, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('archivo');
        $this->post('/api/documentos-sustento', ['archivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('archivo');
    }

    public function test_reemplazar_el_archivo_borra_el_anterior_y_eliminar_borra_el_archivo(): void
    {
        $id = $this->post('/api/documentos-sustento', ['archivo' => UploadedFile::fake()->create('uno.pdf', 3, 'application/pdf')], ['Accept' => 'application/json'])->json('data.id');
        $ruta1 = DB::table('Soporte.DocumentoSustento')->where('DocumentoSustentoId', $id)->value('DocumentoSustentoRuta');

        $this->post("/api/documentos-sustento/{$id}", ['_method' => 'PATCH', 'archivo' => UploadedFile::fake()->create('dos.pdf', 4, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        $ruta2 = DB::table('Soporte.DocumentoSustento')->where('DocumentoSustentoId', $id)->value('DocumentoSustentoRuta');
        Storage::disk('local')->assertMissing($ruta1);
        Storage::disk('local')->assertExists($ruta2);

        $this->deleteJson("/api/documentos-sustento/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($ruta2);
    }
}
