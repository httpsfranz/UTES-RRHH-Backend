<?php

return [
    /*
     * Plazos del RIT que dependen del dia en que se presenta el tramite (Art. 16, 30, 32, 57, 64, 73 y 74). Se apaga solo
     * para cargar informacion historica; en operacion normal debe quedar activo.
     */
    'control_de_plazos' => (bool) env('RIT_CONTROL_DE_PLAZOS', true),

    // sistema:respaldar: sin carpeta usa la de respaldos de SQL Server.
    'respaldo_directorio' => env('RESPALDO_DIRECTORIO'),
    'respaldo_dias' => (int) env('RESPALDO_DIAS', 14),
];
