<?php

/*
|--------------------------------------------------------------------------
| Mensajes de autenticación (C1.18)
|--------------------------------------------------------------------------
|
| El original en inglés lo trae el framework. `auth.throttle` es el que se ve
| de verdad: la API cuenta los intentos ella misma y lanza el evento `Lockout`,
| pero el panel web sigue usando este.
|
*/

return [

    'failed'   => 'Esas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña indicada no es correcta.',
    'throttle' => 'Demasiados intentos de inicio de sesión. Vuelve a intentarlo en :seconds segundos.',

];
