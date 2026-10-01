<?php

namespace App\Http\Requests;

use App\Models\Soporte\DocumentoSustento;

class DocumentoSustentoRequest extends CatalogoRequest
{
    public function messages(): array
    {
        return [
            'DocumentoSustentoHash.regex' => 'El hash debe ser un SHA-256 en hexadecimal (64 caracteres).',
            'DocumentoSustentoHash.unique' => 'Ya existe un documento con ese hash: el archivo ya fue registrado.',
        ];
    }

    public function rules(): array
    {
        return [
            'DocumentoSustentoNombre' => $this->textoObligatorio(255),
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
