<?php

namespace App\Support;

use App\Http\Requests\CatalogoRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Valida un arreglo de datos con un Form Request de alta (POST), fuera de una peticion HTTP. Las cargas desde Excel lo
 * usan para que cada fila pase por las MISMAS reglas que el formulario de la pantalla (incluidas las que consultan la
 * base, como la superposicion de vinculos), sin copiarlas.
 */
class ValidacionDeFormulario
{
    /**
     * @param  class-string<CatalogoRequest>  $clase
     * @param  array<string,mixed>  $datos
     * @return array{0:array<string,mixed>|null,1:array<string,string>} datos listos para guardar, o errores (campo => primer mensaje)
     */
    public static function alta(string $clase, array $datos, ?Authenticatable $usuario = null): array
    {
        $peticion = $clase::create('/', 'POST', $datos);
        $peticion->setContainer(app())->setRedirector(app('redirect'));
        $peticion->setUserResolver(fn () => $usuario);

        try {
            $peticion->validateResolved();
        } catch (ValidationException $e) {
            return [null, array_map(fn (array $mensajes) => $mensajes[0], $e->errors())];
        }

        return [$peticion->datos(), []];
    }
}
