<?php

/*
|--------------------------------------------------------------------------
| Permisos: en qué estado está una solicitud (C1.18)
|--------------------------------------------------------------------------
|
| El *tipo* de permiso no está aquí. «Vacaciones», «Sin sueldo» y los demás son
| filas de `leave_types`, configuradas por empresa: son datos, no vocabulario.
|
*/

return [

    'status' => [
        'pending'   => 'Pendiente',
        'approved'  => 'Aprobada',
        'rejected'  => 'Rechazada',
        'cancelled' => 'Retirada',
    ],

    'stage' => [
        'awaiting_manager' => 'Pendiente del responsable',
        'awaiting_hr'      => 'Pendiente de RR. HH.',
    ],

];
