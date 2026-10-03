<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Asistencia\AsistenciaDiaria;
use App\Models\Asistencia\EstadoAsistencia;
use App\Models\Asistencia\JustificacionFalta;
use App\Models\Personal\VinculoLaboral;
use App\Models\Programacion\TurnoProgramado;
use Illuminate\Validation\Validator;

/**
 * Asistencia diaria: el hecho consolidado del dia de un vinculo (una fila por vinculo y fecha). Normalmente la genera
 * el motor de consolidacion a partir de las marcaciones; esta API permite el registro y la correccion manual.
 */
class AsistenciaDiariaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** Los datetime-local del navegador envian "2026-09-28T06:30". */
    protected function prepareForValidation(): void
    {
        foreach (['AsistenciaDiariaHoraEntrada', 'AsistenciaDiariaHoraSalida'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => str_replace('T', ' ', trim($this->input($campo)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            // Turno programado esperado ese dia.
            'TurnoProgramadoId' => ['nullable', 'integer', $this->existe(TurnoProgramado::class)],
            'EstadoAsistenciaId' => [$this->obligatorio(), 'integer', $this->existeActivo(EstadoAsistencia::class, 'EstadoAsistenciaEstado', 'EstadoAsistenciaId')],
            'JustificacionFaltaId' => ['nullable', 'integer', $this->existe(JustificacionFalta::class)],
            'AsistenciaDiariaFecha' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'AsistenciaDiariaHoraEntrada' => ['nullable', 'date_format:Y-m-d H:i,Y-m-d H:i:s'],
            'AsistenciaDiariaHoraSalida' => ['nullable', 'date_format:Y-m-d H:i,Y-m-d H:i:s'],
            'AsistenciaDiariaMinutosTardanza' => ['sometimes', 'nullable', 'integer', 'between:0,1440'],
            'AsistenciaDiariaMinutosFalta' => ['sometimes', 'nullable', 'integer', 'between:0,1440'],
            'AsistenciaDiariaMinutosExtra' => ['sometimes', 'nullable', 'integer', 'between:0,1440'],
            'AsistenciaDiariaMinutosTrabajados' => ['sometimes', 'nullable', 'integer', 'between:0,1440'],
            'AsistenciaDiariaObservacion' => $this->texto(1000),
        ];
    }

    public function messages(): array
    {
        return [
            'AsistenciaDiariaHoraEntrada.date_format' => 'La hora de entrada debe tener el formato AAAA-MM-DD HH:MM.',
            'AsistenciaDiariaHoraSalida.date_format' => 'La hora de salida debe tener el formato AAAA-MM-DD HH:MM.',
            '*.between' => 'Los minutos deben estar entre 0 y 1440 (un día).',
        ];
    }

    /**
     * - Una sola fila por vinculo y fecha; no futura; vinculo vigente ese dia; periodo de asistencia no cerrado.
     * - La entrada es de ese dia y la salida del mismo dia o del siguiente (guardia nocturna), nunca antes de la entrada.
     * - La justificacion enlazada debe estar APROBADA, ser del mismo vinculo y cubrir la fecha.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $fecha = $this->fechaEfectiva('AsistenciaDiariaFecha');
            $vinculoId = $this->valorEfectivo('VinculoLaboralId');

            if ($fecha && $fecha > now()->toDateString()) {
                $validator->errors()->add('AsistenciaDiariaFecha', 'La asistencia no puede registrarse para una fecha futura.');

                return;
            }

            $this->vinculoVigenteEn($validator, 'AsistenciaDiariaFecha', $fecha);
            $this->rechazaPeriodoCerrado($validator, 'AsistenciaDiariaFecha', $fecha);

            $existe = AsistenciaDiaria::query()
                ->where('VinculoLaboralId', $vinculoId)
                ->whereDate('AsistenciaDiariaFecha', $fecha)
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($existe) {
                $validator->errors()->add('AsistenciaDiariaFecha', 'Ya existe la asistencia de ese trabajador para esa fecha.');
            }

            $entrada = $this->momento('AsistenciaDiariaHoraEntrada');
            $salida = $this->momento('AsistenciaDiariaHoraSalida');
            if ($entrada && substr($entrada, 0, 10) !== $fecha) {
                $validator->errors()->add('AsistenciaDiariaHoraEntrada', 'La entrada debe ser del mismo día de la asistencia.');
            }
            if ($salida && $fecha && substr($salida, 0, 10) !== $fecha && substr($salida, 0, 10) !== date('Y-m-d', strtotime($fecha.' +1 day'))) {
                $validator->errors()->add('AsistenciaDiariaHoraSalida', 'La salida debe ser del mismo día o del día siguiente (guardia nocturna).');
            }
            if ($entrada && $salida && $salida < $entrada) {
                $validator->errors()->add('AsistenciaDiariaHoraSalida', 'La salida no puede ser anterior a la entrada.');
            }

            $justificacionId = $this->valorEfectivo('JustificacionFaltaId');
            if ($justificacionId) {
                $justificacion = JustificacionFalta::query()->find($justificacionId);
                if ($justificacion && $justificacion->JustificacionFaltaEstado !== 'APROBADO') {
                    $validator->errors()->add('JustificacionFaltaId', 'La justificación no está aprobada.');
                } elseif ($justificacion && ($justificacion->VinculoLaboralId !== (int) $vinculoId
                    || $fecha < $justificacion->JustificacionFaltaFechaInicio->toDateString()
                    || $fecha > $justificacion->JustificacionFaltaFechaFin->toDateString())) {
                    $validator->errors()->add('JustificacionFaltaId', 'La justificación es de otro trabajador o no cubre esa fecha.');
                }
            }
        });
    }

    /** "Y-m-d H:i:s" de un datetime que quedara guardado (null si no hay). */
    private function momento(string $campo): ?string
    {
        $valor = $this->valorEfectivo($campo);
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        return blank($valor) ? null : date('Y-m-d H:i:s', strtotime((string) $valor));
    }

    public function datos(): array
    {
        $datos = $this->validated();

        // Un minuto vacio (el formulario lo envia asi) significa "calcular / dejar como esta", no NULL.
        foreach (['Tardanza', 'Falta', 'Extra', 'Trabajados'] as $minutos) {
            if (array_key_exists("AsistenciaDiariaMinutos{$minutos}", $datos) && $datos["AsistenciaDiariaMinutos{$minutos}"] === null) {
                unset($datos["AsistenciaDiariaMinutos{$minutos}"]);
            }
        }

        foreach (['AsistenciaDiariaHoraEntrada', 'AsistenciaDiariaHoraSalida'] as $campo) {
            if (isset($datos[$campo]) && strlen($datos[$campo]) === 16) {
                $datos[$campo] .= ':00';
            }
        }

        // Minutos trabajados = salida - entrada, salvo que se indiquen (descuenta lo que el motor no sabe: refrigerio).
        $entrada = $this->momento('AsistenciaDiariaHoraEntrada');
        $salida = $this->momento('AsistenciaDiariaHoraSalida');
        if (! array_key_exists('AsistenciaDiariaMinutosTrabajados', $datos) && $entrada && $salida && ($this->esCreacion() || $this->hasAny(['AsistenciaDiariaHoraEntrada', 'AsistenciaDiariaHoraSalida']))) {
            $datos['AsistenciaDiariaMinutosTrabajados'] = (int) round((strtotime($salida) - strtotime($entrada)) / 60);
        }
        $datos['AsistenciaDiariaFechaProceso'] = now()->format('Y-m-d H:i:s');

        return $datos;
    }
}
