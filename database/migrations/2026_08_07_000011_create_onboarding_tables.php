<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3.12 — joining and leaving checklists.
 *
 * Two tables, and the split is the point. `checklist_templates` is what the
 * company always does — issue a laptop, sign the handbook, revoke the door
 * card. `employee_checklist_items` is a copy of that taken for one person at
 * one moment.
 *
 * Copied rather than referenced, because a checklist is a record of what was
 * done. Editing the template next year must not rewrite what somebody was
 * asked to do last year, and deleting a template must not blank the history of
 * every leaver who went through it. So the item carries its own title and
 * description, and the template id is only a breadcrumb.
 *
 * Offboarding is the half that matters legally: the door card, the laptop, the
 * account. An unticked "revoke building access" against somebody who left three
 * months ago is precisely the thing an audit asks about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->enum('kind', ['onboarding', 'offboarding']);
            $table->string('title', 200);
            $table->string('description', 500)->nullable();

            // Who normally does it — "IT", "Line manager". Free text rather than
            // a user id: it is a role in the company's own words, and the person
            // filling it changes more often than the task does.
            $table->string('owner', 100)->nullable();

            // Days from the hire or leaving date. Negative is before it, which
            // is where most of onboarding actually happens.
            $table->smallInteger('due_offset_days')->default(0);

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['company_id', 'kind', 'position']);
        });

        Schema::create('employee_checklist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Nulled rather than cascaded: the template may be retired, and the
            // record of what this person was asked to do outlives it.
            $table->foreignId('checklist_template_id')->nullable()
                ->constrained('checklist_templates')->nullOnDelete();

            $table->enum('kind', ['onboarding', 'offboarding']);

            // Copied from the template at the moment the list was raised.
            $table->string('title', 200);
            $table->string('description', 500)->nullable();
            $table->string('owner', 100)->nullable();

            $table->date('due_on')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['employee_id', 'kind']);
            // The overdue sweep: everything outstanding for one company.
            $table->index(['company_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_checklist_items');
        Schema::dropIfExists('checklist_templates');
    }
};
