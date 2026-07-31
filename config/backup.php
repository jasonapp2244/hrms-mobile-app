<?php

return [

    /*
     | Where dumps are written. Outside the web root by default — a backup
     | served over HTTP is a copy of every employee record on the internet.
     | storage/ is already blocked from public access; a path on another disk
     | or a mounted volume is better still, because a server that loses its
     | filesystem loses the backups with it.
     */
    'path' => env('BACKUP_PATH', storage_path('backups')),

    /*
     | How many dumps to keep. Old ones are removed after a successful new one,
     | never before — a rotation that deletes first and then fails leaves you
     | with fewer backups than you started with.
     */
    'keep' => (int) env('BACKUP_KEEP', 14),

    /*
     | mysqldump and mysql binaries. On a Linux host these are usually on PATH;
     | under XAMPP on Windows they are not, hence the explicit setting.
     */
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql'     => env('BACKUP_MYSQL', 'mysql'),

    /*
     | Compress with gzip. A dump of this schema is mostly repeated column names
     | and compresses to a fraction of its size. Set false if the host has no
     | gzip available.
     */
    'compress' => (bool) env('BACKUP_COMPRESS', true),

    /*
     | Seconds before a dump is abandoned. Generous: a large attendance table on
     | a slow disk is not a failure, it is just slow.
     */
    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

];
