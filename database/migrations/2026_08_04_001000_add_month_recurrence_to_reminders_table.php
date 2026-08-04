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
            // How a monthly/yearly rule picks its day. Null (or
            // 'day_of_month') means today's existing behavior — the anchor
            // day in `repeat_anchor_day`. 'nth_weekday' means "the 3rd
            // Wednesday" style rule, read together with `repeat_week_of_month`
            // and the single weekday already carried in `repeat_weekdays`.
            $table->string('repeat_month_mode')->nullable();

            // 1-4 for first through fourth, -1 for "last" — only meaningful
            // when repeat_month_mode is 'nth_weekday'.
            $table->tinyInteger('repeat_week_of_month')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn(['repeat_month_mode', 'repeat_week_of_month']);
        });
    }
};
