<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Carga de asistencia manual: el parte diario que un establecimiento sin reloj remite a Recursos Humanos
 * (RIT Art. 21: sin equipos, el control se hace con el parte diario, autorizado por la Unidad Funcional de
 * Gestion de Recursos Humanos). Las marcaciones que vienen de ese archivo apuntan a esta carga.
 */
class CargaAsistenciaManualRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** ANULADO no se envia: se llega con DELETE (que ademas invalida las marcaciones de la carga). */
    public const ESTADOS_EDITABLES = ['REGISTRADO', 'PROCESADO', 'OBSERVADO'];

    public function rules(): array
    {
        return [
            // Quien carga el archivo. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioId')],
            'EessId' => ['nullable', 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'CargaAsistenciaManualNombreArchivo' => $this->texto(255),
            'CargaAsistenciaManualRegistros' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'CargaAsistenciaManualObservacion' => $this->texto(1000),
            'CargaAsistenciaManualEstado' => ['sometimes', 'in:'.implode(',', self::ESTADOS_EDITABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'CargaAsistenciaManualEstado.in' => 'El estado debe ser REGISTRADO, PROCESADO u OBSERVADO (para anular, elimina la carga).',
            'CargaAsistenciaManualRegistros.min' => 'La cantidad de registros no puede ser negativa.',
        ];
    }

    /**
     * - Una carga nueva nace REGISTRADA; una ANULADA ya no se modifica.
     * - PROCESADA debe indicar cuantos registros se cargaron; OBSERVADA debe decir por que.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $estado = $this->valorEfectivo('CargaAsistenciaManualEstado') ?? 'REGISTRADO';

            if ($this->esCreacion() && $this->filled('CargaAsistenciaManualEstado') && $estado !== 'REGISTRADO') {
                $validator->errors()->add('CargaAsistenciaManualEstado', 'Una carga nueva se registra con estado REGISTRADO.');
            }
            if (! $this->esCreacion() && $this->registro()?->CargaAsistenciaManualEstado === 'ANULADO') {
                $validator->errors()->add('CargaAsistenciaManualEstado', 'La carga está anulada y ya no se puede modificar.');
            }
            if ($estado === 'PROCESADO' && (int) $this->valorEfectivo('CargaAsistenciaManualRegistros') < 1) {
                $validator->errors()->add('CargaAsistenciaManualRegistros', 'Indica cuántos registros se cargaron para marcar la carga como procesada.');
            }
            if ($estado === 'OBSERVADO' && blank($this->valorEfectivo('CargaAsistenciaManualObservacion'))) {
                $validator->errors()->add('CargaAsistenciaManualObservacion', 'Indica la observación de la carga.');
            }
        });
    }
}
