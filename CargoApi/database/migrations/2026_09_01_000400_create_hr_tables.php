<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People — the roster, the hiring pipeline, and who sees which module.
 *
 * The system already had two half-answers to "who works here". `users` is a
 * login, and `drivers` is an operational record with a licence and an on-time
 * rate. Neither is an employee: a mechanic and the woman at the front desk
 * appear in neither, and a driver's hire date, contact and photograph had
 * nowhere to live.
 *
 * `employees` is the HR record, and it *links* to the other two rather than
 * replacing either. A driver keeps their `drivers` row, because every trip,
 * dispatch and GPS ping in the system points at it and the operational history
 * belongs there. A member of staff who signs in keeps their `users` row for
 * the same reason. What this adds is the person the two describe.
 *
 * `applicants` is the pipeline in front of it. Hiring one creates the employee
 * record from the application rather than having somebody retype it.
 *
 * `user_modules` is the module assignment. Read the comment on the table
 * itself before touching it: it narrows what a role already allows, and it
 * cannot widen it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Their number on the payroll. Unique, because it is what a payslip
            // and a government form are filed under.
            $table->string('employee_no')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            // Free text, not an enum: "Driver", "Helper", "Mechanic",
            // "Dispatcher", "Accounting Clerk". A fixed list would be wrong
            // within a month of the first hire nobody anticipated.
            $table->string('position');
            $table->string('department')->nullable();
            // regular / probationary / contractual / part_time
            $table->string('employment_type')->default('probationary');
            $table->string('status')->default('active');
            $table->date('hired_on');
            $table->date('birth_date')->nullable();
            $table->string('contact');
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();
            // Centavos, like every other money column (DESIGN.md section 7.1).
            $table->unsignedBigInteger('base_salary_cents')->default(0);
            /**
             * Only the storage path is kept; the URL is derived on read, so
             * moving the install does not leave every staff photograph pointing
             * at a host that no longer exists. Same reasoning as proof of
             * delivery (`ProofStore`).
             */
            $table->string('photo_path')->nullable();
            /**
             * The operational record, where this employee is one.
             *
             * Unique so one driver cannot be two employees. Null for everybody
             * who does not drive, which is most of the office.
             */
            $table->foreignUlid('driver_id')->nullable()->unique()->constrained()->nullOnDelete();
            // Their login, where they have one. Also unique: two employees
            // sharing an account would make every audit trail meaningless.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('position');
        });

        Schema::create('applicants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('position_applied');
            $table->string('contact');
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            // Where they came from — a referral, a walk-in, a job post. The
            // office wants to know which of those actually works.
            $table->string('source')->nullable();
            $table->date('applied_on');
            /**
             * Where they are in the pipeline: applied, screening, interview,
             * offered, hired, rejected. Its own vocabulary rather than the
             * shared status list, because "screening" is not a status any other
             * module has and overloading `pending` for it would lose the stage.
             */
            $table->string('stage')->default('applied');
            $table->string('photo_path')->nullable();
            $table->string('resume_path')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('notes')->nullable();
            // Set when the application became a hire, so the pipeline can show
            // its own outcomes instead of the row simply disappearing.
            $table->foreignUlid('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('stage');
        });

        /**
         * Which modules a person sees in the sidebar.
         *
         * This NARROWS what the account's role already allows; it cannot widen
         * it. The distinction is the whole design of the table, and getting it
         * backwards would be a security bug rather than a display one:
         * `navigation` is a list of links, while access is decided by the
         * permission on each endpoint (`Role::permissions()`). Granting a nav
         * row a role has no permission for would produce a menu item that 403s
         * on click — the appearance of access without any.
         *
         * So: no rows for a user means "everything your role allows", which is
         * the behaviour every existing account keeps. Rows mean "only these, of
         * the ones your role allows".
         */
        Schema::create('user_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The `nav_items.key` this grant refers to. A plain string rather
            // than a foreign key: nav rows are reseeded configuration, and a
            // reseed that renumbered them would silently reassign somebody's
            // menu.
            $table->string('nav_key');
            $table->timestamps();

            $table->unique(['user_id', 'nav_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_modules');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('employees');
    }
};
