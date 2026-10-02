<?php

namespace App\Http\Requests;

use App\Models\Biometria\AutorizacionMetodo;
use App\Models\Biometria\ConsentimientoBiometrico;
use App\Models\Biometria\PlantillaBiometrica;
use App\Models\Personal\Trabajador;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PlantillaBiometricaRequest extends CatalogoRequest
{
    /** Tipos de plantilla que se almacenan (los demas metodos de marcacion no guardan plantilla). */
    public const TIPOS = ['HUELLA', 'ROSTRO'];

    public const DEDOS = [
        'PULGAR_DERECHO', 'INDICE_DERECHO', 'MEDIO_DERECHO', 'ANULAR_DERECHO', 'MENIQUE_DERECHO',
        'PULGAR_IZQUIERDO', 'INDICE_IZQUIERDO', 'MEDIO_IZQUIERDO', 'ANULAR_IZQUIERDO', 'MENIQUE_IZQUIERDO',
    ];

    /** Una plantilla es un vector de pocos cientos de bytes: 4 KB sobra y evita subir archivos por error. */
    public const MAXIMO_BYTES = 4096;

    public function rules(): array
    {
        return [
            'TrabajadorId' => [$this->obligatorio(), 'integer', $this->existeActivo(Trabajador::class, 'TrabajadorEstado', 'TrabajadorId')],
            'PlantillaBiometricaTipo' => [$this->obligatorio(), Rule::in(self::TIPOS)],
            'PlantillaBiometricaDedo' => ['nullable', Rule::in(self::DEDOS)],
            // Base64 de la plantilla (solo la envia el enrolador/integracion; la API nunca la devuelve).
            'PlantillaBiometricaReferencia' => ['nullable', 'string', function (string $atributo, mixed $valor, Closure $fail) {
                $bytes = base64_decode((string) $valor, true);
                if ($bytes === false || $bytes === '') {
                    $fail('La referencia debe ser una cadena base64 válida.');
                } elseif (strlen($bytes) > self::MAXIMO_BYTES) {
                    $fail('La referencia no puede pesar más de '.self::MAXIMO_BYTES.' bytes.');
                }
            }],
            'PlantillaBiometricaEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'PlantillaBiometricaTipo.in' => 'El tipo debe ser HUELLA o ROSTRO.',
            'PlantillaBiometricaDedo.in' => 'El dedo indicado no es válido.',
        ];
    }

    /**
     * Reglas con otras tablas (dato personal sensible, ver RIT Art. 21 y Ley de Proteccion de Datos Personales):
     *  - la huella necesita indicar el dedo; el rostro no tiene dedo;
     *  - una plantilla ACTIVA solo se guarda si el trabajador tiene un consentimiento biometrico vigente (aceptado);
     *  - el RIT fija el reconocimiento facial como unica forma de marcacion: para HUELLA debe existir una
     *    autorizacion de metodo vigente (Biometria.AutorizacionMetodo);
     *  - no hay dos plantillas activas del mismo dedo.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $trabajadorId = $this->valorEfectivo('TrabajadorId');
            $tipo = $this->valorEfectivo('PlantillaBiometricaTipo');
            $dedo = $this->valorEfectivo('PlantillaBiometricaDedo');

            if ($tipo === 'HUELLA' && blank($dedo)) {
                $validator->errors()->add('PlantillaBiometricaDedo', 'Indica a qué dedo corresponde la huella.');
            }
            if ($tipo === 'ROSTRO' && filled($dedo)) {
                $validator->errors()->add('PlantillaBiometricaDedo', 'El reconocimiento facial no tiene dedo.');
            }
            if ($validator->errors()->isNotEmpty() || ! $this->booleanoEfectivo('PlantillaBiometricaEstado')) {
                return;
            }

            if (! ConsentimientoBiometrico::vigenteDe((int) $trabajadorId)?->ConsentimientoBiometricoAceptado) {
                $validator->errors()->add('TrabajadorId', 'El trabajador no tiene un consentimiento biométrico vigente: debe aceptarlo antes de registrar su plantilla.');

                return;
            }

            if ($tipo === 'HUELLA') {
                $autorizado = AutorizacionMetodo::query()->vigentes()
                    ->where('TrabajadorId', $trabajadorId)
                    ->whereHas('metodo', fn ($m) => $m->where('MetodoMarcacionCodigo', 'HUELLA'))
                    ->exists();
                if (! $autorizado) {
                    $validator->errors()->add('PlantillaBiometricaTipo', 'El RIT establece el reconocimiento facial como única forma de marcación: la huella requiere una autorización de método vigente.');

                    return;
                }

                $repetida = PlantillaBiometrica::query()->where('TrabajadorId', $trabajadorId)
                    ->where('PlantillaBiometricaTipo', 'HUELLA')->where('PlantillaBiometricaDedo', $dedo)->where('PlantillaBiometricaEstado', 1)
                    ->when($this->registroId(), fn ($q, $id) => $q->where('PlantillaBiometricaId', '!=', $id))
                    ->exists();
                if ($repetida) {
                    $validator->errors()->add('PlantillaBiometricaDedo', 'El trabajador ya tiene una plantilla activa de ese dedo.');
                }
            }
        });
    }
}
