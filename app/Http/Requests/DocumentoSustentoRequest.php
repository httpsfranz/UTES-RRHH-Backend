<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentoSustentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');

        return [
            'DocumentoSustentoNombre'      => [$esCreacion ? 'required' : 'sometimes', 'string', 'max:255'],
            'DocumentoSustentoRuta'        => ['nullable', 'string', 'max:500'],
            'DocumentoSustentoTipo'        => ['nullable', 'string', 'max:100'],
            'DocumentoSustentoExtension'   => ['nullable', 'string', 'max:10'],
            'DocumentoSustentoTamanoBytes' => ['nullable', 'integer', 'min:0'],
            'DocumentoSustentoHash'        => ['nullable', 'string', 'max:128'],
        ];
    }
}
