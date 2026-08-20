<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The alert twin of `reminder_dispatches`, and a **separate table on
     * purpose**. `reminder_dispatches` keys on `(reminder_id, due_at)`, which
     * is byte-identical to the notification-history payload of a due
     * notification (delivery-engine close-out) — adding a `kind` column there
     * would break that correspondence for the sake of saving one table.
     *
     * Everything else is the same mechanism: insert first, send second, with
     * the unique index as the entire send-once guarantee.
     */
    public function up(): void
    {
        Schema::create('reminder_alert_dispatches', function (Blueprint $table) {
            $table->id();
            // Declared here rather than in a later migration — SQLite cannot
            // add a foreign key to an existing table (ARCHITECTURE.md §5).
            $table->foreignId('reminder_alert_id')->constrained()->cascadeOnDelete();
            // The alert moment this row claims, in UTC:
            // coalesce(alert.snoozed_until, reminder.due_at - offset_minutes)
            // at the moment the sweep ran. Snoozing the alert mints a new
            // fire moment and therefore a new key, which is what lets the
            // same alert fire again — exactly the main snooze mechanic.
            $table->dateTime('fire_at');
            // Null when the alert was claimed but deliberately not sent
            // (stale suppression) — the moment is still burnt.
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['reminder_alert_id', 'fire_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_alert_dispatches');
    }
};
