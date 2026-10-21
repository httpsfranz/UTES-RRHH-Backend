<?php

/*
 * Marcador facial (proyecto aparte, C:\Proyectos\UTES-Marcador-Facial): el navegador del kiosco calcula el descriptor
 * del rostro (128 numeros, face-api) y lo envia; el backend lo compara con las plantillas ROSTRO de
 * Biometria.PlantillaBiometrica y registra la marcacion.
 */
return [

    // Distancia euclidiana maxima entre descriptores para aceptar que es la misma persona. face-api sugiere 0.6;
    // 0.5 es mas estricto (menos confusiones entre personas, a cambio de pedir mejor luz).
    'umbral_facial' => (float) env('BIOMETRIA_UMBRAL_FACIAL', 0.5),

    // Despues de una marca, el mismo trabajador frente a la camara no genera otra durante estos minutos (evita que
    // quedarse parado marque la salida justo despues de la entrada).
    'minutos_entre_marcas' => (int) env('BIOMETRIA_MINUTOS_ENTRE_MARCAS', 10),

];
