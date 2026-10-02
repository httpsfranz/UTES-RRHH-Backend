<?php

/*
 * Mensajes de validacion en espanol. Los nombres de campo legibles
 * (MicroredTelefono -> "teléfono") los arma App\Http\Requests\CatalogoRequest::attributes().
 */
return [
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser un texto.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'numeric' => 'El campo :attribute debe ser un número.',
    'decimal' => 'El campo :attribute debe tener :decimal decimales.',
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'ip' => 'El campo :attribute debe ser una dirección IP válida.',
    'date' => 'El campo :attribute debe ser una fecha válida.',
    'date_format' => 'El campo :attribute debe tener el formato :format (por ejemplo 2026-07-28).',
    'digits' => 'El campo :attribute debe tener exactamente :digits dígitos.',
    'exists' => 'El :attribute seleccionado no existe o está inactivo.',
    'in' => 'El :attribute seleccionado no es válido.',
    'unique' => 'Ya existe un registro con ese :attribute.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha posterior o igual a :date.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'between' => [
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],
    'min' => [
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'max' => [
        'numeric' => 'El campo :attribute no debe ser mayor que :max.',
        'string' => 'El campo :attribute no debe superar los :max caracteres.',
    ],
    'same' => 'Los campos :attribute y :other deben coincidir.',
    'array' => 'El campo :attribute debe ser una lista.',
    'present' => 'El campo :attribute debe estar presente.',
    'distinct' => 'El campo :attribute tiene un valor repetido.',
    'password' => [
        'letters' => 'La :attribute debe contener al menos una letra.',
        'mixed' => 'La :attribute debe contener mayúsculas y minúsculas.',
        'numbers' => 'La :attribute debe contener al menos un número.',
        'symbols' => 'La :attribute debe contener al menos un símbolo.',
    ],
    'attributes' => [],
];
