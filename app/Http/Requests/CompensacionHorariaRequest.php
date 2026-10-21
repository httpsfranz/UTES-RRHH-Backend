<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Asistencia\AsistenciaDiaria;
use App\Models\Compensaciones\CompensacionHoraria;
use App\Models\Compensaciones\TipoCompensacion;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * Compensacion horaria: horas que el trabajador genera (sobretiempo, guardia, dia no laborable, permiso por compensar)
 * y devuelve despues. RIT, Art. 17: el trabajo fuera de la jornada es excepcional y voluntario, requiere autorizacion
 * previa del jefe inmediato, se compensa como maximo hasta el mes siguiente y debe ser de una hora diaria como minimo.
 */
class CompensacionHorariaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** Tipo de compensacion al que se aplica el minimo de una hora diaria (RIT, Art. 17). */
    public const TIPO_SOBRETIEMPO = 'HORA_EXTRA';

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'TipoCompensacionId' => [$this->esCreacion() ? 'required_without:CompensacionHorariaFechaTrabajo' : 'sometimes', 'nullable', 'integer', $this->existeActivo(TipoCompensacion::class, 'TipoCompensacionEstado', 'TipoCompensacionId')],
            'AsistenciaDiariaId' => ['nullable', 'integer', $this->existe(AsistenciaDiaria::class)],
            'CompensacionHorariaAutorizadoPor' => ['nullable', 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'CompensacionHorariaAutorizadoPor')],
            // Fecha del trabajo fuera de jornada que el jefe autoriza ANTES (Art. 17): las horas salen luego de las marcaciones.
            'CompensacionHorariaFechaTrabajo' => [$this->esCreacion() ? 'required_without:CompensacionHorariaHorasGeneradas' : 'sometimes', 'nullable', 'date_format:Y-m-d'],
            // Solo para registros sin fecha de trabajo (historicos): con fecha, las horas las calcula el sistema y no se envian.
            'CompensacionHorariaHorasGeneradas' => [$this->esCreacion() ? 'required_without:CompensacionHorariaFechaTrabajo' : 'sometimes', 'nullable', 'numeric', 'gt:0', 'max:24', 'regex:/^\d+(\.\d{1,2})?$/'],
            'CompensacionHorariaFechaLimite' => ['nullable', 'date_format:Y-m-d'],
            'CompensacionHorariaAutorizadoPreviamente' => $this->booleano(),
            'CompensacionHorariaObservacion' => $this->texto(500),
        ];
    }

    public function messages(): array
    {
        return [
            'CompensacionHorariaHorasGeneradas.gt' => 'Las horas generadas deben ser mayores que cero.',
            'CompensacionHorariaHorasGeneradas.max' => 'Las horas generadas no pueden superar las 24 de un día.',
            'CompensacionHorariaHorasGeneradas.regex' => 'Las horas admiten hasta 2 decimales (por ejemplo 1.5).',
            'CompensacionHorariaFechaLimite.date_format' => 'La fecha límite debe tener el formato AAAA-MM-DD.',
        ];
    }

    /**
     * - El sobretiempo es de una hora como minimo (RIT, Art. 17).
     * - La fecha limite no es anterior a hoy ni pasa del fin del mes siguiente al trabajo (RIT, Art. 17).
     * - La asistencia diaria enlazada es del mismo trabajador.
     * - Solo se modifica pendiente; el estado y las horas devueltas no se envian (se usan las acciones aprobar/devolver).
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'CompensacionHorariaEstado');
            $this->rechazaEstadoEnviado($validator, 'CompensacionHorariaHorasDevueltas');
            $this->soloSiPendiente($validator, 'CompensacionHorariaEstado', 'CompensacionHorariaEstado', 'La compensación');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fechaTrabajo = $this->fechaEfectiva('CompensacionHorariaFechaTrabajo');
            if ($fechaTrabajo !== null) {
                // Autorizacion PREVIA (Art. 17): para un dia ya pasado solo si se declara que se autorizo antes (correo o documento).
                if ($this->esCreacion() && $fechaTrabajo < HoraLocal::hoy()->toDateString() && ! $this->boolean('CompensacionHorariaAutorizadoPreviamente')) {
                    $validator->errors()->add('CompensacionHorariaFechaTrabajo', 'La autorización debe ser previa al trabajo (RIT, Art. 17). Si se autorizó antes por correo o documento, márcalo.');
                }
                $repetida = CompensacionHoraria::query()->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))->whereDate('CompensacionHorariaFechaTrabajo', $fechaTrabajo)
                    ->where('CompensacionHorariaEstado', '<>', 'ANULADO')->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))->exists();
                if ($repetida) {
                    $validator->errors()->add('CompensacionHorariaFechaTrabajo', 'El trabajador ya tiene una autorización de trabajo fuera de jornada para ese día.');
                }

                return;
            }

            $tipo = TipoCompensacion::query()->find($this->valorEfectivo('TipoCompensacionId'));
            if ($tipo?->TipoCompensacionCodigo === self::TIPO_SOBRETIEMPO && (float) $this->valorEfectivo('CompensacionHorariaHorasGeneradas') < 1) {
                $validator->errors()->add('CompensacionHorariaHorasGeneradas', 'El trabajo fuera de la jornada a compensar es de una hora diaria como mínimo (RIT, Art. 17).');
            }

            $limite = $this->fechaEfectiva('CompensacionHorariaFechaLimite');
            if ($limite !== null) {
                $generacion = $this->registro()?->CompensacionHorariaFechaGeneracion?->toDateString() ?? now()->toDateString();
                $maximo = date('Y-m-t', strtotime(substr($generacion, 0, 7).'-01 +1 month'));
                if ($limite < $generacion) {
                    $validator->errors()->add('CompensacionHorariaFechaLimite', 'La fecha límite no puede ser anterior a la generación de las horas.');
                } elseif ($limite > $maximo) {
                    $validator->errors()->add('CompensacionHorariaFechaLimite', 'La compensación se realiza como máximo hasta el mes siguiente del trabajo (hasta el '.$this->fechaCorta($maximo).', RIT Art. 17).');
                }
            }

            $asistencia = AsistenciaDiaria::query()->find($this->valorEfectivo('AsistenciaDiariaId'));
            if ($asistencia && $asistencia->VinculoLaboralId !== (int) $this->valorEfectivo('VinculoLaboralId')) {
                $validator->errors()->add('AsistenciaDiariaId', 'La asistencia diaria es de otro trabajador.');
            }
        });
    }

    /**
     * Con fecha de trabajo, el sistema completa lo que antes se digitaba: autorizada previamente, autorizada por quien la
     * registra (el jefe), horas en 0 hasta que el dia ocurra (luego salen de la marcacion de salida) y "compensar hasta" =
     * fin del mes siguiente al trabajo (Art. 17).
     *
     * @return array<string,mixed>
     */
    public function datos(): array
    {
        $datos = $this->validated();
        $fecha = $datos['CompensacionHorariaFechaTrabajo'] ?? null;
        if ($fecha === null) {
            return $datos;
        }
        unset($datos['CompensacionHorariaHorasGeneradas']);
        if ($this->esCreacion()) {
            $datos['CompensacionHorariaHorasGeneradas'] = 0;
            $datos['CompensacionHorariaAutorizadoPreviamente'] = true;
            $datos['CompensacionHorariaAutorizadoPor'] ??= $this->user()?->getAuthIdentifier();
            $datos['TipoCompensacionId'] ??= TipoCompensacion::query()->where('TipoCompensacionCodigo', self::TIPO_SOBRETIEMPO)->value('TipoCompensacionId');
        }
        $datos['CompensacionHorariaFechaLimite'] = CarbonImmutable::parse($fecha)->addMonthNoOverflow()->endOfMonth()->toDateString();

        return $datos;
    }
}
