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
        Schema::table('reminders', function (Blueprint $table) {
            // Opt-in, per reminder: ticking this one off asks "How did it
            // go?" first, and the answer is kept on the completion row
            // (reminder_completions.note). It also changes what the push's
            // Complete button does — a lock-screen button cannot take text,
            // so for these reminders it opens the note page instead of
            // completing blind (ReminderDueNotification). Default false keeps
            // every existing reminder a one-tap tick.
            $table->boolean('ask_for_note')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn('ask_for_note');
        });
    }
};
