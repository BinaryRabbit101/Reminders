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
        Schema::create('reminder_completions', function (Blueprint $table) {
            $table->id();
            // The reminder's owner, not whoever tapped complete — a push
            // notification's Complete button has no acting user at all
            // (NotificationActionController), so the log is filed the same
            // way visibility already works: against the owner, with is_shared
            // below carrying it to the rest of the household.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nullable and SQLite cannot add a foreign key after the table
            // exists (reminder_dispatches), so it is declared here. A deleted
            // reminder keeps its completion entries — same "payload is the
            // history, not the reminder" rule NotificationHistory follows —
            // hence the snapshot columns below rather than a live join.
            $table->foreignId('reminder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // Snapshot at completion time: a reminder un-shared later must not
            // retroactively hide a completion the other household member
            // already saw notified about.
            $table->boolean('is_shared')->default(false);
            // The occurrence completed: coalesce(snoozed_until, due_at) at the
            // moment `Reminder::complete()` was called — the same effective
            // moment every other surface buckets and sorts on.
            $table->dateTime('occurred_at');
            $table->dateTime('completed_at');
            $table->timestamps();

            $table->index(['user_id', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_completions');
    }
};
