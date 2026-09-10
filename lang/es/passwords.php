<?php

/*
|--------------------------------------------------------------------------
| Restablecer la contraseña (C1.18)
|--------------------------------------------------------------------------
|
| `passwords.sent` no llega a verse desde la aplicación: `/auth/forgot-password`
| responde siempre lo mismo, exista o no la cuenta, y esa frase está en
| `api.reset_link_sent`. Estas son las del formulario web y las que devuelve el
| broker cuando el enlace ya no sirve.
|
*/

return [

    'reset'     => 'Tu contraseña se ha restablecido.',
    'sent'      => 'Te hemos enviado por correo el enlace para restablecer la contraseña.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token'     => 'Este enlace para restablecer la contraseña ya no es válido.',
    'user'      => 'No encontramos ninguna cuenta con esa dirección de correo.',

];
