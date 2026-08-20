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
            // Opt-in, per reminder, and only meaningful alongside a repeat
            // rule: when the reminder goes off the series rolls straight on to
            // its next occurrence instead of parking in Overdue. Default false
            // keeps the 2026-08-07 recurrence amendment as the behaviour every
            // existing reminder still gets.
            $table->boolean('auto_complete')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn('auto_complete');
        });
    }
};
