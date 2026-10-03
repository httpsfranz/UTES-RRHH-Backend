<?php

namespace App\Http\Requests\Concerns;

use App\Models\Personal\VinculoLaboral;
use App\Support\PeriodosDeAsistencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * Reglas de negocio que se repiten en los modulos de asistencia, solicitudes y programacion. Se usan desde
 * withValidator() (via despuesDeValidar), asi que solo corren cuando el resto de la validacion ya paso.
 * Requiere los helpers de CatalogoRequest (valorEfectivo, registro).
 */
trait ReglasDeNegocio
{
    /** AAAA-MM-DD -> DD/MM/AAAA para los mensajes. */
    protected function fechaCorta(?string $fecha): string
    {
        return $fecha ? date('d/m/Y', strtotime($fecha)) : '';
    }

    /**
     * El vinculo laboral debe estar vigente durante todo el rango [$desde, $hasta]: una marcacion, papeleta o
     * licencia no puede registrarse antes de que el vinculo inicie ni despues de que termine. Por omision el vinculo es el
     * de la columna VinculoLaboralId; los modulos que lo alcanzan por otra via (p. ej. la programacion de un trabajador)
     * pasan su id en $vinculoId.
     */
    protected function vinculoVigenteEn(Validator $validator, string $campoError, ?string $desde, ?string $hasta = null, ?int $vinculoId = null): void
    {
        $vinculo = VinculoLaboral::query()->find($vinculoId ?? $this->valorEfectivo('VinculoLaboralId'));
        if (! $vinculo || ! $desde) {
            return;
        }
        $hasta ??= $desde;
        $inicio = $vinculo->VinculoLaboralFechaInicio->toDateString();
        $fin = $vinculo->VinculoLaboralFechaFin?->toDateString();

        if ($desde < $inicio) {
            $validator->errors()->add($campoError, "El vínculo laboral inició el {$this->fechaCorta($inicio)}: no puede registrarse una fecha anterior.");
        } elseif ($fin !== null && $hasta > $fin) {
            $validator->errors()->add($campoError, "El vínculo laboral terminó el {$this->fechaCorta($fin)}: no puede registrarse una fecha posterior.");
        }
    }

    /** Hay un periodo de asistencia CERRADO que cubre alguna fecha del rango (la asistencia ya se consolido). */
    protected function periodoCerradoEn(?string $desde, ?string $hasta = null): bool
    {
        return PeriodosDeAsistencia::cerradoEn($desde, $hasta);
    }

    protected function rechazaPeriodoCerrado(Validator $validator, string $campoError, ?string $desde, ?string $hasta = null): void
    {
        if ($this->periodoCerradoEn($desde, $hasta)) {
            $validator->errors()->add($campoError, 'El período de asistencia de esa fecha ya está cerrado: no se puede registrar ni modificar.');
        }
    }

    /**
     * Existe otro registro que se superpone con [$inicio, $fin] (fin null = abierto). `$filtro` acota por
     * trabajador/vinculo/EESS; `$excluirEstados` deja fuera los registros que ya no cuentan (anulados, rechazados).
     *
     * @param  class-string<Model>  $modelo
     * @param  array<string,mixed>  $filtro
     * @param  list<string>  $excluirEstados
     */
    protected function haySuperposicion(
        string $modelo,
        string $colInicio,
        ?string $colFin,
        ?string $inicio,
        ?string $fin,
        array $filtro,
        string|array|null $columnaEstado = null,
        array $excluirEstados = [],
    ): bool {
        if (! $inicio) {
            return false;
        }

        return $modelo::query()
            ->where($filtro)
            ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
            ->when($columnaEstado && $excluirEstados !== [], fn ($q) => $q->whereNotIn($columnaEstado, $excluirEstados))
            ->whereDate($colInicio, '<=', $fin ?? '9999-12-31')
            ->when($colFin !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull($colFin)->orWhereDate($colFin, '>=', $inicio)))
            ->exists();
    }

    /**
     * Una solicitud solo se modifica mientras esta PENDIENTE; resuelta (aprobada, rechazada) o anulada, queda
     * como constancia. Para resolverla se usan las acciones aprobar/rechazar, no un PATCH.
     */
    protected function soloSiPendiente(Validator $validator, string $columnaEstado, string $campoError, string $entidad = 'La solicitud'): void
    {
        if ($this->esCreacion()) {
            return;
        }
        $estado = $this->registro()?->getAttribute($columnaEstado);
        if ($estado !== null && $estado !== 'PENDIENTE') {
            $validator->errors()->add($campoError, "{$entidad} está {$this->estadoEnTexto($estado)} y ya no se puede modificar.");
        }
    }

    protected function estadoEnTexto(string $estado): string
    {
        return match ($estado) {
            'APROBADO' => 'aprobada',
            'RECHAZADO' => 'rechazada',
            'ANULADO' => 'anulada',
            default => strtolower($estado),
        };
    }

    /** Las solicitudes nacen PENDIENTE: el estado solo cambia con aprobar, rechazar o anular. */
    protected function rechazaEstadoEnviado(Validator $validator, string $columnaEstado): void
    {
        if ($this->exists($columnaEstado)) {
            $validator->errors()->add($columnaEstado, 'El estado no se envía: cambia con las acciones aprobar, rechazar o anular.');
        }
    }
}
