<?php

namespace App\Http\Requests;

use App\Models\Configuracion\TablaTolerancia;
use App\Models\Configuracion\TipoJornada;
use App\Models\Configuracion\Turno;
use Illuminate\Validation\Validator;

class TurnoRequest extends CatalogoRequest
{
    /** RIT Art. 20: ninguna guardia (ni turno) puede superar las 12 horas continuas. */
    private const MAXIMO_MINUTOS = 720;

    public function rules(): array
    {
        return [
            'TipoJornadaId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(TipoJornada::class, 'TipoJornadaEstado', 'TipoJornadaId'),
            ],
            // NULL = el turno usa la escala general de tolerancia.
            'TablaToleranciaId' => [
                'nullable', 'integer',
                $this->existeActivo(TablaTolerancia::class, 'TablaToleranciaEstado', 'TablaToleranciaId'),
            ],
            'TurnoCodigo' => $this->codigoUnico(Turno::class, 'TurnoCodigo', 30),
            'TurnoNombre' => $this->nombreUnico(Turno::class, 'TurnoNombre', 100),
            'TurnoHoraEntrada' => [$this->obligatorio(), 'date_format:H:i'],
            'TurnoHoraSalida' => [$this->obligatorio(), 'date_format:H:i'],
            'TurnoToleranciaEntradaMinutos' => ['sometimes', 'integer', 'between:0,120'],
            'TurnoToleranciaSalidaMinutos' => ['sometimes', 'integer', 'between:0,120'],
            'TurnoRefrigerioMinutos' => ['sometimes', 'integer', 'between:0,120'],
            'TurnoPermiteHoraExtra' => $this->booleano(),
            'TurnoEsGuardia' => $this->booleano(),
            'TurnoEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'TurnoHoraEntrada.date_format' => 'La hora de entrada debe tener el formato HH:MM (por ejemplo 07:30).',
            'TurnoHoraSalida.date_format' => 'La hora de salida debe tener el formato HH:MM (por ejemplo 13:30).',
        ];
    }

    /**
     * La duracion la calcula la base (TurnoDuracionMinutos), pero hay que validarla ANTES de guardar:
     * un turno que cruza la medianoche dura 24 h - (entrada - salida), y entrada = salida dura 24 h.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $entrada = $this->minutos($this->valorEfectivo('TurnoHoraEntrada'));
            $salida = $this->minutos($this->valorEfectivo('TurnoHoraSalida'));
            if ($entrada === null || $salida === null) {
                return;
            }

            $duracion = $salida > $entrada ? $salida - $entrada : 1440 - ($entrada - $salida);
            $esGuardia = (bool) $this->valorEfectivo('TurnoEsGuardia');

            if ($duracion > self::MAXIMO_MINUTOS) {
                $validator->errors()->add('TurnoHoraSalida', 'El turno no puede durar más de 12 horas continuas (RIT, Art. 20: no se programan turnos de 24 horas).');
            } elseif ($esGuardia && $duracion !== self::MAXIMO_MINUTOS) {
                $validator->errors()->add('TurnoHoraSalida', 'Una guardia dura exactamente 12 horas continuas (RIT, Art. 20).');
            }

            $refrigerio = (int) ($this->valorEfectivo('TurnoRefrigerioMinutos') ?? 0);
            if ($refrigerio >= $duracion) {
                $validator->errors()->add('TurnoRefrigerioMinutos', 'El refrigerio debe ser menor que la duración del turno.');
            }
        });
    }

    /** "07:30" o "07:30:00.0000000" (lo que devuelve SQL Server) -> minutos desde las 00:00. */
    private function minutos(mixed $hora): ?int
    {
        if (! is_string($hora) || ! preg_match('/^(\d{2}):(\d{2})/', $hora, $m)) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
