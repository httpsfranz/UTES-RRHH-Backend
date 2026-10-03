<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Programacion\ProgramacionPeriodo;
use App\Models\Programacion\TipoPeriodoProgramacion;
use App\Models\Seguridad\Usuario;
use App\Rules\Codigo;
use App\Services\ProgramacionPeriodoService;
use Illuminate\Validation\Validator;

/**
 * Programacion de un establecimiento para un periodo (mensual, quincenal, semanal o extraordinario): la cabecera que
 * se elabora (BORRADOR), se publica y se cierra. RIT, Art. 16: remitida la programacion, queda prohibida cualquier
 * modificacion de los turnos salvo autorizacion expresa; por eso solo un BORRADOR se edita.
 */
class ProgramacionPeriodoRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'EessId' => [$this->obligatorio(), 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            'TipoPeriodoProgramacionId' => [$this->obligatorio(), 'integer', $this->existeActivo(TipoPeriodoProgramacion::class, 'TipoPeriodoProgramacionEstado', 'TipoPeriodoProgramacionId')],
            // Quien elabora la programacion. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioRegistroId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioRegistroId')],
            'ProgramacionPeriodoCodigo' => ['nullable', 'string', 'max:50', new Codigo, $this->unico(ProgramacionPeriodo::class, 'ProgramacionPeriodoCodigo')],
            'ProgramacionPeriodoAnio' => [$this->obligatorio(), 'integer', 'between:2000,2100'],
            'ProgramacionPeriodoMes' => ['nullable', 'integer', 'between:1,12'],
            'ProgramacionPeriodoNumero' => ['nullable', 'integer', 'between:1,5'],
            'ProgramacionPeriodoFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'ProgramacionPeriodoFechaFin' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'ProgramacionPeriodoObservacion' => $this->texto(1000),
        ];
    }

    public function messages(): array
    {
        return [
            'ProgramacionPeriodoAnio.between' => 'El año debe estar entre 2000 y 2100.',
            'ProgramacionPeriodoMes.between' => 'El mes debe estar entre 1 y 12.',
            'ProgramacionPeriodoNumero.between' => 'El número de quincena o semana debe estar entre 1 y 5.',
            'ProgramacionPeriodoFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'ProgramacionPeriodoFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
        ];
    }

    /**
     * - Fechas coherentes con el tipo: MENSUAL = el mes completo; QUINCENAL = del 1 al 15 o del 16 al fin de mes;
     *   SEMANAL = hasta 7 dias dentro del mes; EXTRAORD = libre. El anio y el mes declarados coinciden con las fechas.
     * - Sin superposicion con otra programacion (no anulada) del mismo establecimiento y tipo.
     * - Solo un BORRADOR se modifica; el estado se cambia con publicar, cerrar o anular.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'ProgramacionPeriodoEstado');
            if (! $this->esCreacion() && ($motivo = ProgramacionPeriodoService::motivoDeBloqueo($this->registro()))) {
                $validator->errors()->add('ProgramacionPeriodoEstado', $motivo);
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $inicio = $this->fechaEfectiva('ProgramacionPeriodoFechaInicio');
            $fin = $this->fechaEfectiva('ProgramacionPeriodoFechaFin');
            if ($fin < $inicio) {
                $validator->errors()->add('ProgramacionPeriodoFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $anio = (int) $this->valorEfectivo('ProgramacionPeriodoAnio');
            $mes = $this->valorEfectivo('ProgramacionPeriodoMes');
            $numero = $this->valorEfectivo('ProgramacionPeriodoNumero');
            $tipo = TipoPeriodoProgramacion::query()->find($this->valorEfectivo('TipoPeriodoProgramacionId'));
            $codigoTipo = $tipo?->TipoPeriodoProgramacionCodigo;

            if ((int) substr($inicio, 0, 4) !== $anio) {
                $validator->errors()->add('ProgramacionPeriodoAnio', 'El año no coincide con la fecha de inicio.');
            }
            if ($mes !== null && (int) substr($inicio, 5, 2) !== (int) $mes) {
                $validator->errors()->add('ProgramacionPeriodoMes', 'El mes no coincide con la fecha de inicio.');
            }
            if (in_array($codigoTipo, ['MENSUAL', 'QUINCENAL', 'SEMANAL'], true) && $mes === null) {
                $validator->errors()->add('ProgramacionPeriodoMes', 'Indica el mes de la programación.');
            }
            $ultimoDia = $inicio ? date('Y-m-t', strtotime($inicio)) : null;

            if ($codigoTipo === 'MENSUAL' && ($inicio !== substr($inicio, 0, 8).'01' || $fin !== $ultimoDia)) {
                $validator->errors()->add('ProgramacionPeriodoFechaInicio', 'Una programación mensual cubre el mes completo, del día 1 al último día.');
            }
            if ($codigoTipo === 'QUINCENAL') {
                if (! in_array((int) $numero, [1, 2], true)) {
                    $validator->errors()->add('ProgramacionPeriodoNumero', 'Indica si es la quincena 1 o la 2.');
                } elseif ((int) $numero === 1 && ($inicio !== substr($inicio, 0, 8).'01' || $fin !== substr($inicio, 0, 8).'15')) {
                    $validator->errors()->add('ProgramacionPeriodoFechaInicio', 'La primera quincena va del día 1 al 15.');
                } elseif ((int) $numero === 2 && ($inicio !== substr($inicio, 0, 8).'16' || $fin !== $ultimoDia)) {
                    $validator->errors()->add('ProgramacionPeriodoFechaInicio', 'La segunda quincena va del día 16 al último día del mes.');
                }
            }
            if ($codigoTipo === 'SEMANAL') {
                if ($numero === null) {
                    $validator->errors()->add('ProgramacionPeriodoNumero', 'Indica el número de la semana (1 a 5).');
                }
                if ((int) ((strtotime($fin) - strtotime($inicio)) / 86400) + 1 > 7 || substr($fin, 0, 7) !== substr($inicio, 0, 7)) {
                    $validator->errors()->add('ProgramacionPeriodoFechaFin', 'Una programación semanal dura hasta 7 días y no cruza de mes.');
                }
            }

            $solapa = $this->haySuperposicion(
                ProgramacionPeriodo::class, 'ProgramacionPeriodoFechaInicio', 'ProgramacionPeriodoFechaFin', $inicio, $fin,
                ['EessId' => $this->valorEfectivo('EessId'), 'TipoPeriodoProgramacionId' => $this->valorEfectivo('TipoPeriodoProgramacionId')],
                'ProgramacionPeriodoEstado', ['ANULADA'],
            );
            if ($solapa) {
                $validator->errors()->add('ProgramacionPeriodoFechaInicio', 'El establecimiento ya tiene una programación de este tipo que se superpone con esas fechas.');
            } elseif (ProgramacionPeriodo::query()
                ->where('EessId', $this->valorEfectivo('EessId'))
                ->where('TipoPeriodoProgramacionId', $this->valorEfectivo('TipoPeriodoProgramacionId'))
                ->whereDate('ProgramacionPeriodoFechaInicio', $inicio)
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists()) {
                // UQ_ProgramacionPeriodo (establecimiento, tipo, fecha de inicio) cuenta tambien las anuladas.
                $validator->errors()->add('ProgramacionPeriodoFechaInicio', 'Ya existe (anulada) una programación de este establecimiento, tipo y fecha de inicio: usa otras fechas.');
            }
        });
    }
}
