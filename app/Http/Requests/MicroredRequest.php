<?php

namespace App\Http\Requests;

use App\Models\Organizacion\Microred;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MicroredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('microred')?->MicroredId;

        $required = $this->isMethod('post');

        return [
            'MicroredCodigo' => [
                $required ? 'required' : 'sometimes',
                'string',
                'max:30',
                Rule::unique(Microred::class, 'MicroredCodigo')
                    ->ignore($id, 'MicroredId'),
            ],

            'MicroredNombre' => [
                $required ? 'required' : 'sometimes',
                'string',
                'max:150',
                Rule::unique(Microred::class, 'MicroredNombre')
                    ->ignore($id, 'MicroredId'),
            ],

            'MicroredDistrito' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'MicroredUbigeo' => [
                
                'sometimes',
                'nullable',
                'string',
                'max:10',
            ],

            'MicroredDireccion' => [
                'sometimes',
                'nullable',
                'string',
                'max:300',
            ],

            'MicroredTelefono' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'MicroredDescripcion' => [
                'sometimes',
                'nullable',
                'string',
                'max:300',
            ],

            'MicroredEstado' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}