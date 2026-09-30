<?php

namespace App\Http\Requests;

class DocumentoSustentoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'DocumentoSustentoNombre' => $this->textoObligatorio(255),
            'DocumentoSustentoRuta' => $this->texto(500),
            'DocumentoSustentoTipo' => $this->texto(100),
            'DocumentoSustentoExtension' => $this->texto(10),
            'DocumentoSustentoTamanoBytes' => ['nullable', 'integer', 'min:0'],
            'DocumentoSustentoHash' => $this->texto(128),
        ];
    }
}
