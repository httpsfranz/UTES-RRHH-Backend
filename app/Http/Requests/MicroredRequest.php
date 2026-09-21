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
        $req = $this->isMethod('post') ? 'required' : 'sometimes|required';

        return [
            'MicroredCodigo' => [$req, 'string', 'max:30',
                Rule::unique(Microred::class, 'MicroredCodigo')->ignore($id, 'MicroredId')],
            'MicroredNombre' => [$req, 'string', 'max:150',
                Rule::unique(Microred::class, 'MicroredNombre')->ignore($id, 'MicroredId')],
            'MicroredDistrito'    => ['nullable', 'string', 'max:100'],
            'MicroredUbigeo'      => ['nullable', 'string', 'max:10'],
            'MicroredDireccion'   => ['nullable', 'string', 'max:300'],
            'MicroredTelefono'    => ['nullable', 'string', 'max:30'],
            'MicroredDescripcion' => ['nullable', 'string', 'max:300'],
            'MicroredEstado'      => ['sometimes', 'boolean'],
        ];
    }
}