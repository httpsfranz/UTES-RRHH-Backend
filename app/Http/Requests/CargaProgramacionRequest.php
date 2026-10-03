<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Programacion\CargaProgramacion;
use App\Models\Programacion\ProgramacionPeriodo;
use App\Models\Programacion\TipoPeriodoProgramacion;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use App\Rules\Codigo;
use Illuminate\Validation\Validator;

/**
 * Carga de programacion: constancia DOCUMENTAL de que un establecimiento remitio su programacion oficial escaneada
 * (RIT, Art. 16: remitir hasta el dia 05, 10, 15 y 20 de cada mes segun el destino). No representa turnos.
 */
class CargaProgramacionRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** ANULADO no se envia: se llega con DELETE. */
    public const ESTADOS_EDITABLES = ['REGISTRADO', 'OBSERVADO', 'CONFORME'];

    public function rules(): array
    {
        return [
            'EessId' => [$this->obligatorio(), 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            'DocumentoSustentoId' => [$this->obligatorio(), 'integer', $this->existe(DocumentoSustento::class)],
            // Quien realiza la carga. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioRegistroId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioRegistroId')],
            'TipoPeriodoProgramacionId' => ['nullable', 'integer', $this->existeActivo(TipoPeriodoProgramacion::class, 'TipoPeriodoProgramacionEstado', 'TipoPeriodoProgramacionId')],
            'ProgramacionPeriodoId' => ['nullable', 'integer', $this->existe(ProgramacionPeriodo::class)],
            // Si no se envia, se genera: PROG-AAAA-MM-NNN.
            'CargaProgramacionCodigo' => ['nullable', 'string', 'max:50', new Codigo, $this->unico(CargaProgramacion::class, 'CargaProgramacionCodigo')],
            'CargaProgramacionAnio' => [$this->obligatorio(), 'integer', 'between:2000,2100'],
            'CargaProgramacionMes' => [$this->obligatorio(), 'integer', 'between:1,12'],
            'CargaProgramacionNumero' => ['nullable', 'integer', 'between:1,2'],
            'CargaProgramacionFechaDocumento' => [$this->obligatorio(), 'date_format:Y-m-d', 'before_or_equal:today'],
            'CargaProgramacionDocumentoNumero' => ['nullable', 'string', 'max:60'],
            'CargaProgramacionMotivo' => $this->texto(500),
            'CargaProgramacionObservacion' => $this->texto(1000),
            'CargaProgramacionEstado' => ['sometimes', 'in:'.implode(',', self::ESTADOS_EDITABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'CargaProgramacionAnio.between' => 'El año debe estar entre 2000 y 2100.',
            'CargaProgramacionMes.between' => 'El mes debe estar entre 1 y 12.',
            'CargaProgramacionNumero.between' => 'La quincena debe ser 1 o 2.',
            'CargaProgramacionFechaDocumento.before_or_equal' => 'La fecha del documento no puede ser futura.',
            'CargaProgramacionEstado.in' => 'El estado debe ser REGISTRADO, OBSERVADO o CONFORME (para anular, elimina la carga).',
        ];
    }

    /**
     * - Nueva = REGISTRADO; anulada ya no se modifica; OBSERVADO exige la observacion.
     * - La programacion quincenal indica la quincena (1 o 2).
     * - Si se enlaza una programacion estructurada: del mismo establecimiento, mes y anio, y no anulada.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $estado = $this->valorEfectivo('CargaProgramacionEstado') ?? 'REGISTRADO';

            if ($this->esCreacion() && $this->filled('CargaProgramacionEstado') && $estado !== 'REGISTRADO') {
                $validator->errors()->add('CargaProgramacionEstado', 'Una carga nueva se registra con estado REGISTRADO.');
            }
            if (! $this->esCreacion() && $this->registro()?->CargaProgramacionEstado === 'ANULADO') {
                $validator->errors()->add('CargaProgramacionEstado', 'La carga está anulada y ya no se puede modificar.');
            }
            if ($estado === 'OBSERVADO' && blank($this->valorEfectivo('CargaProgramacionObservacion'))) {
                $validator->errors()->add('CargaProgramacionObservacion', 'Indica la observación de la carga.');
            }

            $tipo = TipoPeriodoProgramacion::query()->find($this->valorEfectivo('TipoPeriodoProgramacionId'));
            if ($tipo?->TipoPeriodoProgramacionCodigo === 'QUINCENAL' && blank($this->valorEfectivo('CargaProgramacionNumero'))) {
                $validator->errors()->add('CargaProgramacionNumero', 'Indica si la programación es de la quincena 1 o 2.');
            }

            $programacion = ProgramacionPeriodo::query()->find($this->valorEfectivo('ProgramacionPeriodoId'));
            if ($programacion) {
                if ($programacion->EessId !== (int) $this->valorEfectivo('EessId')) {
                    $validator->errors()->add('ProgramacionPeriodoId', 'La programación enlazada es de otro establecimiento.');
                } elseif ($programacion->ProgramacionPeriodoEstado === 'ANULADA') {
                    $validator->errors()->add('ProgramacionPeriodoId', 'La programación enlazada está anulada.');
                } elseif ($programacion->ProgramacionPeriodoAnio !== (int) $this->valorEfectivo('CargaProgramacionAnio')
                    || ($programacion->ProgramacionPeriodoMes !== null && $programacion->ProgramacionPeriodoMes !== (int) $this->valorEfectivo('CargaProgramacionMes'))) {
                    $validator->errors()->add('ProgramacionPeriodoId', 'La programación enlazada es de otro mes o año.');
                }
            }
        });
    }

    public function datos(): array
    {
        $datos = $this->validated();

        if ($this->esCreacion() && blank($datos['CargaProgramacionCodigo'] ?? null)) {
            $prefijo = sprintf('PROG-%04d-%02d-', $datos['CargaProgramacionAnio'], $datos['CargaProgramacionMes']);
            $siguiente = CargaProgramacion::query()->where('CargaProgramacionCodigo', 'like', $prefijo.'%')->count() + 1;
            while (CargaProgramacion::query()->where('CargaProgramacionCodigo', $prefijo.sprintf('%03d', $siguiente))->exists()) {
                $siguiente++;
            }
            $datos['CargaProgramacionCodigo'] = $prefijo.sprintf('%03d', $siguiente);
        }
        // El codigo es obligatorio en la base: un PATCH que lo envie vacio conserva el actual.
        if (! $this->esCreacion() && array_key_exists('CargaProgramacionCodigo', $datos) && blank($datos['CargaProgramacionCodigo'])) {
            unset($datos['CargaProgramacionCodigo']);
        }

        return $datos;
    }
}
