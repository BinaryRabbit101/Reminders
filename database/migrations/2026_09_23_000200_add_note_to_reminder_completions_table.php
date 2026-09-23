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
        Schema::table('reminder_completions', function (Blueprint $table) {
            // What the user wrote down when they ticked the reminder off —
            // only ever asked for on a reminder with `ask_for_note`, and even
            // then optional ("Skip note"). It lives on the completion rather
            // than the reminder because it belongs to the occurrence: a
            // repeating reminder gathers one note per time it was done, and
            // the log row is the one thing that survives the series advancing.
            // Null means "no note", never an empty string
            // (Reminder::complete() trims blanks away).
            $table->text('note')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminder_completions', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
