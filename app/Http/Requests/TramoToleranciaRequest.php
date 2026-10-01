<?php

namespace App\Http\Requests;

use App\Models\Configuracion\TablaTolerancia;
use App\Models\Configuracion\TramoTolerancia;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TramoToleranciaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TablaToleranciaId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(TablaTolerancia::class, 'TablaToleranciaEstado', 'TablaToleranciaId'),
            ],
            'TramoToleranciaTipo' => [$this->obligatorio(), Rule::in(['TARDANZA', 'SALIDA_ANTICIPADA'])],
            // Minutos transcurridos DESDE LA HORA DE INGRESO (o antes de la hora de salida) del turno.
            'TramoToleranciaMinutosDesde' => [$this->obligatorio(), 'integer', 'between:0,1440'],
            'TramoToleranciaMinutosHasta' => ['nullable', 'integer', 'between:0,1440'],
            'TramoToleranciaFactorDescuento' => ['sometimes', 'numeric', 'decimal:0,2', 'between:0,999.99'],
            'TramoToleranciaMinutosDescuento' => ['nullable', 'integer', 'between:0,1440'],
            'TramoToleranciaEsInasistencia' => $this->booleano(),
            'TramoToleranciaDescripcion' => $this->texto(250),
        ];
    }

    public function messages(): array
    {
        return [
            'TramoToleranciaTipo.in' => 'El tipo debe ser TARDANZA o SALIDA_ANTICIPADA.',
        ];
    }

    /**
     * Las restricciones de la tabla son entre columnas: hasta >= desde, UNIQUE (tabla, tipo, desde) y,
     * sobre todo, que los tramos de una misma escala NO se superpongan (con tramos solapados un
     * minuto de tardanza tendria dos descuentos posibles). Hasta vacio = sin limite superior.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $desde = (int) $this->valorEfectivo('TramoToleranciaMinutosDesde');
            $hasta = $this->valorEfectivo('TramoToleranciaMinutosHasta');
            $hasta = $hasta === null ? null : (int) $hasta;

            if ($hasta !== null && $hasta < $desde) {
                $validator->errors()->add('TramoToleranciaMinutosHasta', 'El tramo no puede terminar antes de empezar (hasta menor que desde).');

                return;
            }

            $otros = TramoTolerancia::query()
                ->where('TablaToleranciaId', $this->valorEfectivo('TablaToleranciaId'))
                ->where('TramoToleranciaTipo', $this->valorEfectivo('TramoToleranciaTipo'))
                ->when($this->registroId(), fn ($q, $id) => $q->where('TramoToleranciaId', '!=', $id));

            if ((clone $otros)->where('TramoToleranciaMinutosDesde', $desde)->exists()) {
                $validator->errors()->add('TramoToleranciaMinutosDesde', 'Ya existe un tramo de ese tipo que empieza en ese minuto.');

                return;
            }

            // [desde, hasta] se superpone con [d, h] si desde <= h y d <= hasta (null = infinito).
            $solapa = $otros
                ->where(fn ($q) => $q->whereNull('TramoToleranciaMinutosHasta')->orWhere('TramoToleranciaMinutosHasta', '>=', $desde))
                ->when($hasta !== null, fn ($q) => $q->where('TramoToleranciaMinutosDesde', '<=', $hasta))
                ->exists();

            if ($solapa) {
                $validator->errors()->add('TramoToleranciaMinutosDesde', 'El tramo se superpone con otro tramo de la misma escala y tipo.');
            }
        });
    }
}
