<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DispositivoMarcacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
{
    return [
        'EessId' => [
            'sometimes',
            'integer',
        ],

        'DispositivoMarcacionCodigo' => [
            'sometimes',
            'string',
            'max:50',
        ],

        'DispositivoMarcacionNombre' => [
            'sometimes',
            'string',
            'max:150',
        ],

        'DispositivoMarcacionTipo' => [
            'sometimes',
            'string',
            'max:50',
        ],

        'DispositivoMarcacionUbicacion' => [
            'sometimes',
            'nullable',
            'string',
            'max:200',
        ],

        'DispositivoMarcacionIp' => [
            'sometimes',
            'nullable',
            'ip',
        ],

        'DispositivoMarcacionEstado' => [
            'sometimes',
            'boolean',
        ],
    ];
}
    
    
}
