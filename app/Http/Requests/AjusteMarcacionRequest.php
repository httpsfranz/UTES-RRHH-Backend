<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Asistencia\AjusteMarcacion;
use App\Models\Asistencia\Marcacion;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Solicitud de ajuste de una marcacion: corregir su fecha y hora (FechaHoraNueva) o, sin fecha nueva, invalidarla.
 * Nace PENDIENTE y al aprobarla se aplica a la marcacion (AjusteMarcacionService). La fecha y hora anteriores las copia
 * el sistema de la marcacion; un periodo de asistencia cerrado es inmutable, tambien para los ajustes.
 */
class AjusteMarcacionRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** El datetime-local del navegador envia "2026-09-28T06:30": se acepta igual que "2026-09-28 06:30:00". */
    protected function prepareForValidation(): void
    {
        $valor = $this->input('AjusteMarcacionFechaHoraNueva');
        if (is_string($valor)) {
            $this->merge(['AjusteMarcacionFechaHoraNueva' => str_replace('T', ' ', trim($valor))]);
        }
    }

    public function rules(): array
    {
        return [
            'MarcacionId' => [$this->obligatorio(), 'integer', $this->existe(Marcacion::class)],
            // Quien solicita el ajuste. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'AjusteMarcacionFechaHoraNueva' => ['nullable', 'date_format:Y-m-d H:i,Y-m-d H:i:s'],
            'AjusteMarcacionMotivo' => $this->textoObligatorio(1000),
        ];
    }

    public function messages(): array
    {
        return [
            'AjusteMarcacionFechaHoraNueva.date_format' => 'La fecha y hora nuevas deben tener el formato AAAA-MM-DD HH:MM.',
        ];
    }

    /**
     * - La marcacion debe seguir valida y su periodo de asistencia (y el de la fecha nueva) no estar cerrado.
     * - La fecha nueva es distinta de la actual, no es futura, cae en un dia con vinculo vigente y no duplica otra marcacion.
     * - Una sola solicitud pendiente por marcacion; solo se modifica pendiente; el estado no se envia.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'AjusteMarcacionEstado');
            $this->soloSiPendiente($validator, 'AjusteMarcacionEstado', 'AjusteMarcacionEstado', 'La solicitud');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $marcacion = Marcacion::query()->find($this->valorEfectivo('MarcacionId'));
            if (! $marcacion->MarcacionEsValida) {
                $validator->errors()->add('MarcacionId', 'La marcación ya está invalidada: no admite ajustes.');

                return;
            }
            $actual = $marcacion->MarcacionFechaHora->format('Y-m-d H:i:s');
            $this->rechazaPeriodoCerrado($validator, 'MarcacionId', substr($actual, 0, 10));

            $pendiente = AjusteMarcacion::query()
                ->where('MarcacionId', $marcacion->MarcacionId)
                ->where('AjusteMarcacionEstado', 'PENDIENTE')
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($pendiente) {
                $validator->errors()->add('MarcacionId', 'Esa marcación ya tiene un ajuste pendiente de resolver.');
            }

            $nueva = $this->fechaHoraNueva();
            if ($nueva !== null) {
                if ($nueva === $actual) {
                    $validator->errors()->add('AjusteMarcacionFechaHoraNueva', 'La fecha y hora nuevas son iguales a las de la marcación.');
                } elseif (strtotime($nueva) > now()->addMinutes(5)->getTimestamp()) {
                    $validator->errors()->add('AjusteMarcacionFechaHoraNueva', 'La fecha y hora nuevas no pueden ser futuras.');
                } else {
                    $this->rechazaPeriodoCerrado($validator, 'AjusteMarcacionFechaHoraNueva', substr($nueva, 0, 10));
                    $this->vinculoVigenteEn($validator, 'AjusteMarcacionFechaHoraNueva', substr($nueva, 0, 10), null, $marcacion->VinculoLaboralId);
                    $duplicada = Marcacion::query()
                        ->where('VinculoLaboralId', $marcacion->VinculoLaboralId)
                        ->where('MarcacionFechaHora', $nueva)
                        ->where('MarcacionTipo', $marcacion->MarcacionTipo)
                        ->whereKeyNot($marcacion->MarcacionId)
                        ->exists();
                    if ($duplicada) {
                        $validator->errors()->add('AjusteMarcacionFechaHoraNueva', 'Ya existe una marcación del mismo tipo para ese trabajador en esa fecha y hora.');
                    }
                }
            }
        });
    }

    /** "Y-m-d H:i:s" de la fecha nueva que quedara guardada, o null (= invalidar la marcacion). */
    private function fechaHoraNueva(): ?string
    {
        $valor = $this->valorEfectivo('AjusteMarcacionFechaHoraNueva');
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }
        if (blank($valor)) {
            return null;
        }
        $t = strtotime((string) $valor);

        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }

    public function datos(): array
    {
        $datos = $this->validated();

        if (isset($datos['AjusteMarcacionFechaHoraNueva']) && strlen($datos['AjusteMarcacionFechaHoraNueva']) === 16) {
            $datos['AjusteMarcacionFechaHoraNueva'] .= ':00';
        }
        // Lo que decia la marcacion antes del ajuste: lo copia el sistema, no se envia.
        if (isset($datos['MarcacionId'])) {
            $datos['AjusteMarcacionFechaHoraAnterior'] = Marcacion::query()->find($datos['MarcacionId'])->MarcacionFechaHora->format('Y-m-d H:i:s');
        }

        return $datos;
    }
}
