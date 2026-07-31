<?php

return [

    /*
     | Push is off until credentials exist.
     |
     | Deliberately opt-in rather than "on if a file happens to be present":
     | a half-configured install should send nothing at all rather than fail
     | once per notification into failed_jobs.
     */
    'enabled' => (bool) env('FCM_ENABLED', false),

    /*
     | The Firebase project these handsets belong to. Visible in the Firebase
     | console under Project settings; it is not a secret.
     */
    'project_id' => env('FCM_PROJECT_ID'),

    /*
     | Absolute path to the service-account JSON downloaded from
     | Firebase Console → Project settings → Service accounts → Generate new
     | private key.
     |
     | This file IS a secret — it can send notifications to every installation
     | of the app. It is kept outside the repository and gitignored; storage/ is
     | already blocked from public access.
     */
    'credentials' => env('FCM_CREDENTIALS', storage_path('app/firebase/service-account.json')),

    /*
     | Seconds to wait on Google before giving up. A queued notification that
     | hangs holds a worker; better to fail and let the job retry.
     */
    'timeout' => (int) env('FCM_TIMEOUT', 10),

    /*
     | Android notification channel id. Must match the channel the Flutter app
     | creates, or Android 8+ silently drops the notification.
     */
    'android_channel' => env('FCM_ANDROID_CHANNEL', 'hrms_default'),

];
