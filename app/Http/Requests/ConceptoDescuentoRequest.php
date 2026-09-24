<?php

namespace App\Http\Requests;

use App\Models\Compensaciones\ConceptoDescuento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Override;

class ConceptoDescuentoRequest extends FormRequest
{

    // Mientras no exista el modulo de Seguridad (M04), true.
     
    public function authorize(): bool
    {
        return true; 
    }


    public function rules(): array
    {
         $esCreacion = $this->isMethod('POST');
         $id = $this->route('concepto')?->ConceptoDescuentoId;
        return [
           'ConceptoDescuentoCodigo' => [
            $esCreacion ? 'required' : 'sometimes',
            'string', 'max:50',
            Rule::unique(ConceptoDescuento::class, 'ConceptoDescuentoCodigo')
            ->ignore($id, 'ConceptoDescuentoId'),
           ],
           'ConceptoDescuentoNombre' => [
            $esCreacion ? 'required' : 'sometimes',
            'string', 'max:150',
            Rule::unique(ConceptoDescuento::class, 'ConceptoDescuentoNombre')
            ->ignore($id, 'ConceptoDescuentoId'),
           ],
           'ConceptoDescuentoDescripcion' => ['nullable', 'string', 'max:300'],
           'ConceptoDescuentoEstado' => ['sometimes', 'boolean'],
        ];
    }

    #[Override]
    public function messages():array
    {
        return [
            'ConceptoDescuentoCodigo.unique' => 'Ya existe un concepto de descuento con ese código.',
            'ConceptoDescuentoNombre.unique' => 'Ya existe un concepto de descuento con ese nombre.',
        ];
    }
}
