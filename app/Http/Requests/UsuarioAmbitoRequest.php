<?php

namespace App\Http\Requests;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Organizacion\Microred;
use App\Models\Seguridad\Usuario;
use App\Models\Seguridad\UsuarioAmbito;
use Illuminate\Validation\Validator;

/**
 * Ambito de visibilidad de un usuario, sin recursividad: una Microred (ve sus EESS), un unico EESS, o ninguno de los
 * dos (ve toda la Red, perfil de sede). No se puede indicar Microred y EESS a la vez.
 */
class UsuarioAmbitoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'UsuarioId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioId')],
            'MicroredId' => ['nullable', 'integer', $this->existeActivo(Microred::class, 'MicroredEstado', 'MicroredId')],
            'EessId' => ['nullable', 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            'UsuarioAmbitoEstado' => $this->booleano(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $microred = $this->valorEfectivo('MicroredId');
            $eess = $this->valorEfectivo('EessId');

            if ($microred !== null && $eess !== null) {
                $validator->errors()->add('EessId', 'Indica una microred o un establecimiento, no ambos (sin ninguno, el usuario ve toda la Red).');

                return;
            }

            $existe = UsuarioAmbito::query()
                ->where('UsuarioId', $this->valorEfectivo('UsuarioId'))
                ->when($microred === null, fn ($q) => $q->whereNull('MicroredId'), fn ($q) => $q->where('MicroredId', $microred))
                ->when($eess === null, fn ($q) => $q->whereNull('EessId'), fn ($q) => $q->where('EessId', $eess))
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($existe) {
                $validator->errors()->add('UsuarioId', 'El usuario ya tiene ese ámbito asignado.');
            }
        });
    }
}
