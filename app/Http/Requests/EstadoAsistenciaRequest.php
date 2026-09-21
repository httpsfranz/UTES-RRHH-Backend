<?php

namespace App\Http\Requests;

use App\Models\Asistencia\EstadoAsistencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstadoAsistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('estadoAsistencia')?->EstadoAsistenciaId;

        $req = $this->isMethod('post')
            ? ['required']
            : ['sometimes', 'required'];

        return [
            'EstadoAsistenciaCodigo' => [
                ...$req,
                'string',
                'max:60',
                Rule::unique(
                    EstadoAsistencia::class,
                    'EstadoAsistenciaCodigo'
                )->ignore($id, 'EstadoAsistenciaId'),
            ],

            'EstadoAsistenciaNombre' => [
                ...$req,
                'string',
                'max:200',
                Rule::unique(
                    EstadoAsistencia::class,
                    'EstadoAsistenciaNombre'
                )->ignore($id, 'EstadoAsistenciaId'),
            ],

            'EstadoAsistenciaDescripcion' => [
                ...$req,
                'string',
                'max:500',
            ],

            'EstadoAsistenciaEsFalta' => [
                ...$req,
                'boolean',
            ],

            'EstadoAsistenciaEsDescontable' => [
                ...$req,
                'boolean',
            ],

            'EstadoAsistenciaEsLaborable' => [
                ...$req,
                'boolean',
            ],

            'EstadoAsistenciaEstado' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}