<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reminder_dispatches', function (Blueprint $table) {
            $table->id();
            // SQLite cannot add a foreign key after the table exists, so the
            // constraint is declared here rather than in a later migration.
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            // The occurrence this row claims: coalesce(snoozed_until, due_at)
            // at the moment the sweep ran, in UTC. Snoozing moves a reminder
            // to a new occurrence, which is what lets it fire again.
            $table->dateTime('due_at');
            // Null when the dispatch was recorded but deliberately not sent
            // (stale-due suppression) — the occurrence is still burnt.
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            // The send-once guarantee. Row locks are no-ops in SQLite
            // (ARCHITECTURE.md §5), so uniqueness is the only thing standing
            // between two overlapping sweeps and a double push: the sender
            // inserts here FIRST and only notifies if the insert won.
            $table->unique(['reminder_id', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_dispatches');
    }
};
