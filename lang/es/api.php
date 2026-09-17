<?php

/*
|--------------------------------------------------------------------------
| Lo que responde la API (C1.18)
|--------------------------------------------------------------------------
|
| El inglés en lang/en/api.php es el original. Las claves y los códigos `error`
| que las acompañan no cambian nunca: el cliente decide por el código y muestra
| el mensaje, así que estas frases se pueden reescribir sin tocar la aplicación.
|
*/

return [

    // --- Rechazos comunes a toda la API -----------------------------------
    'no_employee_record' => 'Esta cuenta no está vinculada a ninguna ficha de empleado. Contacta con RR. HH.',
    'validation_failed'  => 'Los datos enviados no son válidos.',
    'unauthenticated'    => 'Se requiere iniciar sesión.',
    'forbidden'          => 'No tienes permiso para hacer eso.',
    'not_found'          => 'No se encontró el recurso.',
    'request_failed'     => 'La solicitud falló.',
    'too_many_requests'  => 'Demasiadas solicitudes. Ve más despacio.',
    'server_error'       => 'Algo salió mal.',

    // --- Intervalos de fechas ---------------------------------------------
    'invalid_range'   => 'La fecha de inicio debe ser igual o anterior a la de fin.',
    'range_too_large' => 'Pide como máximo :days días a la vez.',
    'future_day'      => 'Ese día todavía no ha llegado.',

    // --- Inicio de sesión --------------------------------------------------
    'invalid_credentials' => 'Esos datos no coinciden con nuestros registros.',
    'account_disabled'    => 'Esta cuenta ha sido desactivada. Contacta con RR. HH.',
    'reset_link_sent'     => 'Si esa dirección tiene una cuenta, el enlace para restablecer la contraseña ya va en camino.',
    'signed_out'          => 'Sesión cerrada.',
    'signed_out_all'      => 'Sesión cerrada en todos los dispositivos.',

    // --- Dispositivos ------------------------------------------------------
    'device_registered'   => 'Dispositivo registrado para recibir notificaciones.',
    'device_not_trusted'  => 'Esta cuenta está vinculada a otro teléfono. Pide a RR. HH. que la libere antes de iniciar sesión aquí.',
    'device_unregistered' => 'Este dispositivo dejará de recibir notificaciones.',

    // --- Perfil ------------------------------------------------------------
    'profile_updated'  => 'Perfil actualizado.',
    'password_changed' => 'Contraseña cambiada.',
    'wrong_password'   => 'Tu contraseña actual no es correcta.',

    // --- Documentos --------------------------------------------------------
    'document_not_found' => 'No existe ese documento.',
    'document_missing'   => 'Ese documento ya no está archivado. Contacta con RR. HH.',

    // --- Permisos ----------------------------------------------------------
    'leave_not_yours'     => 'Esa solicitud de permiso no es tuya.',
    'leave_attachment_missing' => 'El archivo adjunto a esta solicitud ya no está en el servidor.',
    'leave_own_request'   => 'No puedes decidir sobre tu propia solicitud de permiso.',
    'leave_outside_team'   => 'Esa solicitud es de alguien que no está en tu equipo.',
    'leave_auto_approved' => 'Permiso aprobado: este tipo no necesita autorización.',
    'leave_submitted'     => 'Solicitud de permiso enviada. Te avisaremos cuando se revise.',
    'leave_withdrawn'     => 'Solicitud de permiso retirada.',
    'leave_passed_to_hr'  => 'La solicitud de :name ha pasado a RR. HH. para la aprobación final.',
    'leave_rejected'      => 'Solicitud rechazada.',
    'leave_reason_needed' => 'Indica un motivo: el empleado lo verá.',

    // --- Correcciones (A4.13) ----------------------------------------------
    'correction_not_yours' => 'Esa solicitud no es tuya.',
    'correction_submitted' => 'Solicitud enviada. RR. HH. la revisará.',
    'correction_withdrawn' => 'Solicitud retirada.',

];
