<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The default seeder — roles and permissions, and nothing else.
 *
 * It used to call DemoDataSeeder and AttendanceSeeder too, which meant a plain
 * `php artisan db:seed` invented a company, two administrators sharing the
 * password "password", five fictional staff and a fortnight of attendance they
 * never worked. On a real install that is not a helpful head start: it is fake
 * people mixed into the staff list and fake punches mixed into the reports,
 * indistinguishable from the real ones a week later.
 *
 * What remains here is not demo data. Every `can:` check in the application
 * names one of the permissions RolePermissionSeeder creates, so an install that
 * skips it has no roles at all and nobody can be made an administrator. It is
 * safe to run on production, and safe to re-run — every row is firstOrCreate.
 *
 * The demo seeders still exist for local play, but must now be asked for by
 * name so that nothing invents staff by accident:
 *
 *   php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder
 *   php artisan db:seed --class=Database\\Seeders\\AttendanceSeeder
 *
 * To set up a real install, use `php artisan hrms:install` instead. It creates
 * the company and the first administrator by asking, and seeds these same roles.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
        ]);
    }
}
