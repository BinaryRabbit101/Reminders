<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A push that came due inside somebody's quiet hours, waiting for their
     * morning. One row per (recipient, reminder, occurrence).
     *
     * This is **per recipient**, unlike `reminder_dispatches`, and that is the
     * whole reason the table exists: a shared reminder fans out to a household
     * whose members can keep different hours, so "was this occurrence sent?"
     * (per occurrence, one dispatch row) and "has this person been buzzed
     * yet?" (per recipient, one held row) are two different questions. The
     * dispatch log is untouched by quiet hours — claiming, suppressing and
     * advancing all behave exactly as they did before.
     */
    public function up(): void
    {
        Schema::create('held_pushes', function (Blueprint $table) {
            $table->id();
            // SQLite cannot add a foreign key after the table exists, so both
            // constraints are declared here (ARCHITECTURE.md §5). Deleting a
            // reminder or an account takes its held pushes with it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            // The occurrence being held, in UTC — byte-identical to the
            // matching `reminder_dispatches.due_at` and to the `due_at` in the
            // notification payload, so all three still join on it.
            $table->dateTime('occurred_at');
            // When the recipient's quiet window ends, in UTC. Computed on
            // their local calendar at hold time, so a window that straddles a
            // DST change still releases at the wall-clock hour they chose.
            $table->dateTime('release_at')->index();
            $table->timestamps();

            // Idempotent holding, the same stance the dispatch log takes: row
            // locks are no-ops in SQLite, so uniqueness is what stops two
            // overlapping sweeps holding the same push twice.
            $table->unique(['user_id', 'reminder_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('held_pushes');
    }
};
