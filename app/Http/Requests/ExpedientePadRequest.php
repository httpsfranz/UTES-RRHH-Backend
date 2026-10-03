<?php

namespace App\Http\Requests;

use App\Models\Disciplina\ExpedientePad;
use App\Models\Disciplina\TipoFaltaDisciplinaria;
use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Expediente del procedimiento administrativo disciplinario (Ley 30057; RIT, Art. 100 a 107). Se instruye (INICIADO),
 * se tramita (EN_PROCESO) y termina RESUELTO (con sancion) o ARCHIVADO; ANULADO si se abrio por error.
 */
class ExpedientePadRequest extends CatalogoRequest
{
    /** ANULADO no se envia: se llega con DELETE. */
    public const ESTADOS_EDITABLES = ['INICIADO', 'EN_PROCESO', 'RESUELTO', 'ARCHIVADO'];

    /** Estados de los que ya no se sale: el procedimiento termino. */
    public const TERMINALES = ['RESUELTO', 'ARCHIVADO', 'ANULADO'];

    /** Siguiente estado permitido desde cada uno (el procedimiento avanza, no retrocede). */
    private const TRANSICIONES = [
        'INICIADO' => ['INICIADO', 'EN_PROCESO', 'ARCHIVADO'],
        'EN_PROCESO' => ['EN_PROCESO', 'RESUELTO', 'ARCHIVADO'],
    ];

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'TipoFaltaDisciplinariaId' => [$this->obligatorio(), 'integer', $this->existeActivo(TipoFaltaDisciplinaria::class, 'TipoFaltaDisciplinariaEstado', 'TipoFaltaDisciplinariaId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'ExpedientePadNumero' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/.]*$/D', $this->unico(ExpedientePad::class, 'ExpedientePadNumero')],
            'ExpedientePadFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'ExpedientePadFechaFin' => ['nullable', 'date_format:Y-m-d'],
            'ExpedientePadDescripcion' => $this->texto(1500),
            'ExpedientePadSancion' => $this->texto(300),
            'ExpedientePadEstado' => ['sometimes', 'in:'.implode(',', self::ESTADOS_EDITABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'ExpedientePadNumero.regex' => 'El número solo admite letras, números, guion, barra y punto.',
            'ExpedientePadFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'ExpedientePadFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
            'ExpedientePadEstado.in' => 'El estado debe ser INICIADO, EN_PROCESO, RESUELTO o ARCHIVADO (para anular, elimina el expediente).',
        ];
    }

    /**
     * - Una nueva INICIA; el procedimiento solo avanza (INICIADO -> EN_PROCESO -> RESUELTO o ARCHIVADO) y, terminado, no se modifica.
     * - Resolver exige la sancion de la Ley 30057 (amonestacion escrita, suspension o destitucion; la amonestacion verbal no
     *   pasa por PAD, RIT Art. 101); solo un expediente resuelto lleva sancion.
     * - Resuelto o archivado exige fecha de fin, no anterior al inicio.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $nuevo = $this->valorEfectivo('ExpedientePadEstado') ?? 'INICIADO';
            $actual = $this->registro()?->ExpedientePadEstado;

            if ($this->esCreacion() && $this->filled('ExpedientePadEstado') && $nuevo !== 'INICIADO') {
                $validator->errors()->add('ExpedientePadEstado', 'Un expediente nuevo se registra INICIADO.');
            }
            if ($actual !== null) {
                if (in_array($actual, self::TERMINALES, true)) {
                    $validator->errors()->add('ExpedientePadEstado', 'El expediente está '.strtolower(match ($actual) {
                        'RESUELTO' => 'resuelto', 'ARCHIVADO' => 'archivado', default => 'anulado'
                    }).' y ya no se puede modificar.');

                    return;
                }
                if (! in_array($nuevo, self::TRANSICIONES[$actual] ?? [], true)) {
                    $validator->errors()->add('ExpedientePadEstado', "Un expediente {$actual} no puede pasar a {$nuevo}: el procedimiento avanza de INICIADO a EN_PROCESO y de ahí a RESUELTO o ARCHIVADO.");

                    return;
                }
            }

            $inicio = $this->fechaEfectiva('ExpedientePadFechaInicio');
            $fin = $this->fechaEfectiva('ExpedientePadFechaFin');
            if ($fin !== null && $fin < $inicio) {
                $validator->errors()->add('ExpedientePadFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');
            }
            if (in_array($nuevo, ['RESUELTO', 'ARCHIVADO'], true) && $fin === null) {
                $validator->errors()->add('ExpedientePadFechaFin', 'Indica la fecha en que terminó el procedimiento.');
            }

            $sancion = (string) $this->valorEfectivo('ExpedientePadSancion');
            if ($nuevo === 'RESUELTO') {
                if ($sancion === '') {
                    $validator->errors()->add('ExpedientePadSancion', 'Indica la sanción con la que se resuelve el expediente.');
                } elseif (preg_match('/verbal/iu', $sancion)) {
                    $validator->errors()->add('ExpedientePadSancion', 'La amonestación verbal no pasa por el procedimiento disciplinario ni consta por escrito (RIT, Art. 101).');
                } elseif (! preg_match('/amonestaci[oó]n|suspensi[oó]n|destituci[oó]n/iu', $sancion)) {
                    $validator->errors()->add('ExpedientePadSancion', 'La sanción debe ser una de la Ley 30057: amonestación escrita, suspensión sin goce de remuneraciones o destitución (RIT, Art. 101).');
                }
            } elseif ($sancion !== '') {
                $validator->errors()->add('ExpedientePadSancion', 'Solo un expediente resuelto lleva sanción.');
            }
        });
    }
}
