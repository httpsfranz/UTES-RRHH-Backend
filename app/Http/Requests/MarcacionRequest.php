<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Asistencia\CargaAsistenciaManual;
use App\Models\Asistencia\Marcacion;
use App\Models\Biometria\AutorizacionMetodo;
use App\Models\Biometria\DispositivoMarcacion;
use App\Models\Biometria\MetodoMarcacion;
use App\Models\Biometria\PlantillaBiometrica;
use App\Models\Personal\VinculoLaboral;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MarcacionRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public const TIPOS = ['ENTRADA', 'SALIDA', 'SALIDA_PAPELETA', 'RETORNO_PAPELETA', 'SALIDA_REFRIGERIO', 'RETORNO_REFRIGERIO'];

    /** El datetime-local del navegador envia "2026-09-28T06:30": se acepta igual que "2026-09-28 06:30:00". */
    protected function prepareForValidation(): void
    {
        $valor = $this->input('MarcacionFechaHora');
        if (is_string($valor)) {
            $this->merge(['MarcacionFechaHora' => str_replace('T', ' ', trim($valor))]);
        }
    }

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'MetodoMarcacionId' => [$this->obligatorio(), 'integer', $this->existeActivo(MetodoMarcacion::class, 'MetodoMarcacionEstado', 'MetodoMarcacionId')],
            'DispositivoMarcacionId' => ['nullable', 'integer', $this->existeActivo(DispositivoMarcacion::class, 'DispositivoMarcacionEstado', 'DispositivoMarcacionId')],
            'PlantillaBiometricaId' => ['nullable', 'integer', $this->existe(PlantillaBiometrica::class)],
            'CargaAsistenciaManualId' => ['nullable', 'integer', $this->existe(CargaAsistenciaManual::class)],
            'MarcacionFechaHora' => [$this->obligatorio(), 'date_format:Y-m-d H:i,Y-m-d H:i:s'],
            'MarcacionTipo' => [$this->obligatorio(), Rule::in(self::TIPOS)],
            // Latitud,longitud del aplicativo movil: "-8.1116,-79.0288".
            'MarcacionGeolocalizacion' => ['nullable', 'string', 'max:100', 'regex:/^-?\d{1,2}(\.\d+)?,\s?-?\d{1,3}(\.\d+)?$/D'],
            'MarcacionObservacion' => $this->texto(500),
            'MarcacionOrigen' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]*$/D'],
            'MarcacionEsValida' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'MarcacionFechaHora.date_format' => 'La fecha y hora deben tener el formato AAAA-MM-DD HH:MM.',
            'MarcacionTipo.in' => 'El tipo de marcación no es válido.',
            'MarcacionGeolocalizacion.regex' => 'La geolocalización debe ser "latitud,longitud" (por ejemplo -8.1116,-79.0288).',
            'MarcacionOrigen.regex' => 'El origen solo admite mayúsculas, números y guion bajo (por ejemplo DISPOSITIVO).',
        ];
    }

    /**
     * RIT Art. 21: la forma de registro es unicamente el reconocimiento facial; otro metodo solo con autorizacion
     * de Recursos Humanos (AutorizacionMetodo vigente) o, donde no hay equipos, con el parte diario cargado.
     * Ademas: no futura, vinculo vigente ese dia, periodo no cerrado, sin duplicados, y la plantilla y la carga
     * deben ser coherentes con el trabajador y el metodo.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $fechaHora = $this->fechaHoraEfectiva();
            $fecha = $fechaHora ? substr($fechaHora, 0, 10) : null;
            $vinculo = VinculoLaboral::query()->find($this->valorEfectivo('VinculoLaboralId'));
            $metodo = MetodoMarcacion::query()->find($this->valorEfectivo('MetodoMarcacionId'));
            $cambiaMarca = $this->esCreacion() || $this->hasAny(['VinculoLaboralId', 'MetodoMarcacionId', 'MarcacionFechaHora', 'MarcacionTipo', 'PlantillaBiometricaId', 'CargaAsistenciaManualId']);

            // Una marcacion de un periodo ya cerrado no se toca, ni siquiera para corregir su observacion.
            $this->rechazaPeriodoCerrado($validator, 'MarcacionFechaHora', $fecha);
            if (! $cambiaMarca || $validator->errors()->isNotEmpty()) {
                return;
            }

            if ($fechaHora && strtotime($fechaHora) > now()->addMinutes(5)->getTimestamp()) {
                $validator->errors()->add('MarcacionFechaHora', 'La marcación no puede tener fecha y hora futuras.');

                return;
            }

            $this->vinculoVigenteEn($validator, 'MarcacionFechaHora', $fecha);

            $duplicada = Marcacion::query()
                ->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))
                ->where('MarcacionFechaHora', $fechaHora)
                ->where('MarcacionTipo', $this->valorEfectivo('MarcacionTipo'))
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($duplicada) {
                $validator->errors()->add('MarcacionFechaHora', 'Ya existe una marcación del mismo tipo para ese trabajador en esa fecha y hora.');
            }

            $cargaId = $this->valorEfectivo('CargaAsistenciaManualId');
            $carga = $cargaId ? CargaAsistenciaManual::query()->find($cargaId) : null;
            if ($carga) {
                if ($carga->CargaAsistenciaManualEstado === 'ANULADO') {
                    $validator->errors()->add('CargaAsistenciaManualId', 'La carga de asistencia está anulada.');
                }
                if ($carga->EessId !== null && $vinculo && $carga->EessId !== $vinculo->EessId) {
                    $validator->errors()->add('CargaAsistenciaManualId', 'La carga corresponde a otro establecimiento que el del trabajador.');
                }
                if ($metodo && $metodo->MetodoMarcacionCodigo !== 'MANUAL') {
                    $validator->errors()->add('CargaAsistenciaManualId', 'Las marcaciones de un parte diario cargado usan el método Registro manual.');
                }
            }

            $plantillaId = $this->valorEfectivo('PlantillaBiometricaId');
            $plantilla = $plantillaId ? PlantillaBiometrica::query()->find($plantillaId) : null;
            if ($plantilla && $vinculo) {
                if ($plantilla->TrabajadorId !== $vinculo->TrabajadorId) {
                    $validator->errors()->add('PlantillaBiometricaId', 'La plantilla biométrica pertenece a otro trabajador.');
                } elseif ($metodo && in_array($metodo->MetodoMarcacionCodigo, ['HUELLA', 'ROSTRO'], true) && $plantilla->PlantillaBiometricaTipo !== $metodo->MetodoMarcacionCodigo) {
                    $validator->errors()->add('PlantillaBiometricaId', 'El tipo de la plantilla no corresponde al método de marcación.');
                }
            }

            if ($metodo && $vinculo && $fecha && $metodo->MetodoMarcacionCodigo !== 'ROSTRO') {
                $conParteDiario = $metodo->MetodoMarcacionCodigo === 'MANUAL' && $carga !== null;
                $autorizado = AutorizacionMetodo::query()
                    ->where('TrabajadorId', $vinculo->TrabajadorId)
                    ->where('MetodoMarcacionId', $metodo->MetodoMarcacionId)
                    ->where('AutorizacionMetodoEstado', 1)
                    ->whereDate('AutorizacionMetodoFechaInicio', '<=', $fecha)
                    ->where(fn ($q) => $q->whereNull('AutorizacionMetodoFechaFin')->orWhereDate('AutorizacionMetodoFechaFin', '>=', $fecha))
                    ->exists();

                if (! $conParteDiario && ! $autorizado) {
                    $validator->errors()->add('MetodoMarcacionId', 'El RIT (Art. 21) establece el reconocimiento facial como única forma de registro: el método "'.$metodo->MetodoMarcacionNombre.'" requiere una autorización vigente del trabajador o, si el establecimiento no tiene equipo, un parte diario cargado.');
                }
            }
        });
    }

    /** "Y-m-d H:i:s" del valor que quedara guardado. */
    private function fechaHoraEfectiva(): ?string
    {
        $valor = $this->valorEfectivo('MarcacionFechaHora');
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }
        if (! $valor) {
            return null;
        }
        $t = strtotime((string) $valor);

        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }

    public function datos(): array
    {
        $datos = $this->validated();

        // Origen por omision: de donde vino la marcacion.
        if ($this->esCreacion() && blank($datos['MarcacionOrigen'] ?? null)) {
            $metodo = MetodoMarcacion::query()->find($datos['MetodoMarcacionId'] ?? null);
            $datos['MarcacionOrigen'] = match (true) {
                filled($datos['CargaAsistenciaManualId'] ?? null) => 'CARGA_MANUAL',
                filled($datos['DispositivoMarcacionId'] ?? null) => 'DISPOSITIVO',
                $metodo?->MetodoMarcacionCodigo === 'APP' => 'APP',
                default => 'MANUAL',
            };
        }
        if (isset($datos['MarcacionFechaHora']) && strlen($datos['MarcacionFechaHora']) === 16) {
            $datos['MarcacionFechaHora'] .= ':00';
        }

        return $datos;
    }
}
