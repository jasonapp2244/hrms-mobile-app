<?php

/*
|--------------------------------------------------------------------------
| Fichajes, descansos y los motivos por los que se rechaza uno (C1.18)
|--------------------------------------------------------------------------
|
| Compartido con el panel web, que las resuelve en el idioma por defecto.
|
*/

return [

    // --- Resultado de un fichaje -------------------------------------------
    'clocked_in'  => 'Has fichado la ENTRADA a las :time.',
    'clocked_out' => 'Has fichado la SALIDA a las :time.',

    'break_started' => 'Descanso iniciado a las :time. Tu tiempo trabajado se pausa hasta que vuelvas.',
    'break_ended'   => 'Descanso terminado a las :time. Bienvenido de nuevo.',

    // --- Rechazos -----------------------------------------------------------
    'duplicate_scan' => 'Ya se registró hace un momento. Espera un minuto.',
    'no_office'      => 'Tu empresa todavía no tiene ninguna oficina configurada. Contacta con RR. HH.',
    'not_clocked_in' => 'Tienes que haber fichado la entrada antes de iniciar un descanso.',

    'outside_geofence' => 'Parece que estás a :distance de :office, fuera de la zona de fichaje de :radius'
        . ' m. Acércate o pide a RR. HH. que registre este fichaje por ti.',

    // --- Fichajes que llegan tarde, desde la cola sin conexión (B2.4) -------
    'punch_in_future' => 'Ese fichaje tiene una fecha futura. Revisa la fecha y la hora de este dispositivo.',
    'punch_too_old'   => 'Ese fichaje tiene más de :hours horas. Pide una corrección para que se pueda '
        . 'comprobar y registrar correctamente.',

    // --- Cómo se llama cada fichaje -----------------------------------------
    'type' => [
        'in'          => 'Entrada registrada',
        'out'         => 'Salida registrada',
        'break_start' => 'Descanso iniciado',
        'break_end'   => 'Vuelta del descanso',
    ],

];
