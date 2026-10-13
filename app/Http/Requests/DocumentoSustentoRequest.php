<?php

namespace App\Http\Requests;

use App\Models\Soporte\DocumentoSustento;
use Illuminate\Support\Facades\DB;

class DocumentoSustentoRequest extends CatalogoRequest
{
    /** Tamano maximo de un adjunto, en MB (parametro MAX_ADJUNTO_MB; 5 si no esta definido). */
    public static function limiteEnMb(): int
    {
        return max(1, (int) (DB::table('Configuracion.ParametroSistema')->where('ParametroSistemaCodigo', 'MAX_ADJUNTO_MB')->value('ParametroSistemaValor') ?: 5));
    }

    public function messages(): array
    {
        return [
            'archivo.max' => 'El archivo supera el tamaño máximo permitido ('.self::limiteEnMb().' MB).',
            'archivo.mimes' => 'Solo se admiten archivos PDF, imágenes (JPG, PNG), Word o Excel.',
            'DocumentoSustentoHash.regex' => 'El hash debe ser un SHA-256 en hexadecimal (64 caracteres).',
            'DocumentoSustentoHash.unique' => 'Ya existe un documento con ese hash: el archivo ya fue registrado.',
        ];
    }

    public function rules(): array
    {
        return [
            // El archivo subido define nombre, tipo, extension, tamano y hash: no se escriben a mano.
            'archivo' => ['sometimes', 'file', 'max:'.(self::limiteEnMb() * 1024), 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'],
            'DocumentoSustentoNombre' => $this->hasFile('archivo') ? $this->texto(255) : $this->textoObligatorio(255),
            'DocumentoSustentoRuta' => $this->texto(500),
            'DocumentoSustentoTipo' => $this->texto(100),
            'DocumentoSustentoExtension' => $this->texto(10),
            'DocumentoSustentoTamanoBytes' => ['nullable', 'integer', 'min:0'],
            // SHA-256 en hexadecimal (64 caracteres). Unico cuando existe (indice filtrado UX_DocumentoSustento_Hash):
            // sirve para detectar el mismo archivo subido dos veces.
            'DocumentoSustentoHash' => ['nullable', 'string', 'regex:/^[A-Fa-f0-9]{64}$/D', $this->unico(DocumentoSustento::class, 'DocumentoSustentoHash')],
        ];
    }
}
