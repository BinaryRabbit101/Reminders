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
            // Structured recurrence rather than RRULE strings: a null unit
            // means "one-off", which is every reminder that already exists.
            $table->string('repeat_unit')->nullable();
            $table->unsignedSmallInteger('repeat_interval')->default(1);
            $table->json('repeat_weekdays')->nullable();

            // A *local* calendar date (inclusive), never a UTC instant — the
            // series stops once its next occurrence falls past this day.
            $table->date('repeat_until')->nullable();

            // The day-of-month the user actually asked for, kept because
            // `due_at` cannot be trusted to remember it: "monthly on the
            // 31st" is stored as Feb 28 while it sits in February, and
            // advancing from the clamped value would strand the series on
            // the 28th forever.
            $table->unsignedTinyInteger('repeat_anchor_day')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn([
                'repeat_unit',
                'repeat_interval',
                'repeat_weekdays',
                'repeat_until',
                'repeat_anchor_day',
            ]);
        });
    }
};
