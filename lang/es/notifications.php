<?php

/*
|--------------------------------------------------------------------------
| Todo lo que el servidor envía a alguien (C1.18)
|--------------------------------------------------------------------------
|
| Se redactan en el idioma de quien las recibe, no en el de quien las provoca:
| RR. HH. aprueba un permiso en inglés y el empleado lo lee en español. La
| aplicación consulta `User::preferredLocale()`, que lee `users.locale`.
|
*/

return [

    // --- Permiso solicitado, para quien tenga que decidirlo ----------------
    'leave_submitted' => [
        'title'   => ':name ha solicitado un permiso',
        'push'    => ':type, del :from al :to.',
        'body'    => ':days día(s) de :type, del :from al :to.',
        'subject' => 'Solicitud de permiso de :name',
        'line'    => ':name ha solicitado :days día(s) de :type.',
        'dates'   => 'Fechas: del :from al :to.',
        'reason'  => 'Motivo: :reason',
        'action'  => 'Revisar la solicitud',
        'why'     => 'Recibes este mensaje porque la solicitud está pendiente de ti.',
    ],

    // --- Qué ha pasado con el permiso que pediste --------------------------
    'leave_decided' => [
        'title' => [
            'approved'         => 'Tu permiso ha sido aprobado',
            'rejected'         => 'Tu solicitud de permiso ha sido rechazada',
            'cancelled'        => 'Tu solicitud de permiso ha sido retirada',
            'manager_approved' => 'Tu solicitud de permiso ha pasado a RR. HH.',
            'default'          => 'Tu solicitud de permiso se ha actualizado',
        ],
        'body' => [
            'approved'         => 'Tu :type del :dates ha sido aprobado.',
            'rejected'         => 'Tu :type del :dates no ha sido aprobado.',
            'cancelled'        => 'Tu :type del :dates ha sido retirado.',
            'manager_approved' => 'Tu responsable ha aprobado :type del :dates. Ahora está en RR. HH. para la decisión final.',
            'default'          => 'Tu :type del :dates se ha actualizado.',
        ],
        'note'   => 'Nota: :note',
        'action' => 'Ver tus permisos',
        'dates'  => 'Fechas: :dates',
    ],

    // --- Sigues fichado después de terminar el turno -----------------------
    // --- Tu turno está a punto de empezar (B5.1) --------------------------
    'shift_starting' => [
        'title' => 'Tu turno empieza pronto',
        'push'  => 'Empiezas a las :time. Toca para fichar la entrada.',
        'body'  => 'Tu turno empieza a las :time. Toca para fichar la entrada.',
    ],

    'missing_checkout' => [
        'title'  => 'Sigues con la entrada fichada',
        'push'   => 'Fichaste la entrada a las :time. Toca para fichar la salida.',
        'body'   => 'Fichaste la entrada a las :time y no se ha registrado ninguna salida. Toca para fichar la salida.',
        'line'   => 'Fichaste la entrada a las :time el :date y no se ha registrado ninguna salida.',
        'finish' => 'Si ya has terminado por hoy, ficha la salida.',
        'auto'   => 'Si no se registra nada, el día se cerrará automáticamente a la hora de fin de tu turno.',
        'action' => 'Fichar la salida',
    ],

    // --- El cuadrante publicado ha cambiado --------------------------------
    'schedule_updated' => [
        'changed' => 'Tu horario ha cambiado',
        'ready'   => 'Tu horario ya está disponible',
        'summary' => ':days día(s) entre el :from y el :to.',
        'action'  => 'Ver tu horario',
        'check'   => 'Revisa las horas antes de tu próximo turno: puede que hayan cambiado.',
    ],

    // --- Dirigidas a RR. HH., que trabajan en un escritorio -----------------
    'document_expiring' => [
        'title_expired'     => 'Documento caducado',
        'title_expiring'    => 'Documento a punto de caducar',
        'body'              => ':employee — :title (:type) :timing.',
        'subject_expired'   => 'Caducado: :title — :employee',
        'subject_expiring'  => 'Caduca pronto: :title — :employee',
        'greeting_expired'  => 'Un documento ha caducado',
        'greeting_expiring' => 'Un documento está a punto de caducar',
        'line'              => ':title (:type) de :employee :timing.',
        'action'            => 'Abrir sus documentos',
        'why'               => 'Recibes este mensaje porque gestionas las fichas de empleado.',
        'an_employee'       => 'Un empleado',
        'an_employee_lower' => 'un empleado',
        'employee_word'     => 'empleado',
        'timing' => [
            'none'          => 'no tiene fecha de caducidad',
            'expired_today' => 'caducó hoy',
            'expired_days'  => 'caducó hace :days día(s)',
            'expires_today' => 'caduca hoy',
            'expires_days'  => 'caduca en :days día(s)',
        ],
    ],

    'late_arrivals' => [
        'title'     => ':count llegada(s) tarde',
        'body_one'  => ':name fichó :minutes minuto(s) tarde.',
        'body_many' => ':count personas ficharon tarde el :date.',
        'subject'   => ':count llegada(s) tarde — :date',
        'greeting'  => 'Llegadas tarde del :date',
        'intro'     => 'Estos fichajes de entrada fueron posteriores al inicio del turno y fuera del margen de cortesía.',
        'row'       => '· :name (:department) — entrada a las :at, :minutes minuto(s) tarde',
        'action'    => 'Abrir el informe de llegadas tarde',
        'why'       => 'Recibes este mensaje porque tienes el permiso de informes.',
    ],

    // --- Restablecer la contraseña ------------------------------------------
    'password_reset' => [
        'subject'  => 'Restablece tu contraseña de :app',
        'greeting' => 'Hola :name:',
        'there'    => 'buenas',
        'line'     => 'Alguien ha pedido restablecer la contraseña de esta cuenta.',
        'action'   => 'Elegir una contraseña nueva',
        'expiry'   => 'Este enlace deja de funcionar en :minutes minutos y solo se puede usar una vez.',
        'ignore'   => 'Si no has sido tú, no ha cambiado nada: puedes ignorar este correo y tu contraseña seguirá igual.',
    ],

    'greeting' => 'Hola :name:',

];
