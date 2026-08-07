<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3.9 — the details somebody needs when something has gone wrong.
 *
 * An emergency contact is the one field in an HR record that is only ever read
 * on the worst day, which is exactly why it cannot be a note in a spreadsheet
 * somebody keeps locally. The relationship is stored alongside the name and
 * number because "who is Sarah to them" is the first thing asked and the one
 * thing a phone number does not answer.
 *
 * The address fields sit here rather than on `users` because they belong to the
 * person as an employee, and a user account is optional — plenty of staff on
 * the roster have never signed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('emergency_contact_name', 150)->nullable()->after('work_mode');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relation', 60)->nullable()->after('emergency_contact_phone');

            $table->string('personal_email', 150)->nullable()->after('emergency_contact_relation');
            $table->text('address')->nullable()->after('personal_email');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('country', 100)->nullable()->after('city');

            // Free text on purpose. The formats vary by country, some companies
            // are not allowed to hold it at all, and validating it would be
            // guessing at rules that differ per jurisdiction.
            $table->string('national_id', 60)->nullable()->after('country');
            $table->string('blood_group', 10)->nullable()->after('national_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
                'personal_email', 'address', 'city', 'country', 'national_id', 'blood_group',
            ]);
        });
    }
};
