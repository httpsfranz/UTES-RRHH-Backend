<?php

namespace App\Services\ConsultaDni;

use RuntimeException;

/** El proveedor no pudo atender la consulta: el formulario sigue funcionando y los datos se escriben a mano. */
class ConsultaDniNoDisponible extends RuntimeException {}
