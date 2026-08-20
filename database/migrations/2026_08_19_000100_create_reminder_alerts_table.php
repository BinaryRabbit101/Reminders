<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A pre-alert: "also tell me an hour before this is due". Several per
     * reminder are allowed, one row each.
     *
     * The offset is stored, never the moment — a pre-alert is a *relationship*
     * to `reminders.due_at`, so editing the reminder's due time moves every
     * one of its alerts without touching a single row here.
     */
    public function up(): void
    {
        Schema::create('reminder_alerts', function (Blueprint $table) {
            $table->id();
            // SQLite cannot add a foreign key after the table exists, so the
            // constraint is declared here (ARCHITECTURE.md §5). Deleting a
            // reminder takes its alerts — and their dispatch log — with it.
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            // How many minutes before `reminders.due_at` this alert fires.
            // The allow-list lives on the model (ReminderAlert::OFFSETS).
            $table->unsignedInteger('offset_minutes');
            // A snooze of *this alert's current occurrence* only, in UTC. The
            // main reminder's own `snoozed_until` is a different column and a
            // different decision: neither one moves the other.
            $table->dateTime('snoozed_until')->nullable();
            $table->timestamps();

            // One alert per horizon: "an hour before" cannot be asked for
            // twice, and the form's checkbox chips could otherwise post it.
            $table->unique(['reminder_id', 'offset_minutes']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_alerts');
    }
};
