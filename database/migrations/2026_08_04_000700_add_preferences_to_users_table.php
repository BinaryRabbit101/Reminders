<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-user preferences that tune the delivery engine. Columns on `users`
     * rather than a `user_settings` JSON blob (settings-and-quiet-hours spec
     * left the choice open): every one of these is a scalar the delivery
     * engine reads per recipient on the per-minute sweep, and SQLite's JSON
     * support is not something the send path should be leaning on. Columns
     * also type-check — Larastan sees `bool` and `string|null`, not `mixed`.
     *
     * `timezone` and `default_time` are **nullable on purpose**: null means
     * "whatever the app is configured for" (`config('reminders.timezone')`,
     * `config('reminders.default_time')`), resolved by `User::timezone()` and
     * `User::defaultTime()`. That is what keeps a user who never opens the
     * settings page behaving exactly as they did before this shipped.
     *
     * Quiet hours are the other way round: the window always has a value so
     * the toggle has something to turn on, and `quiet_hours_enabled` — off by
     * default — is the only thing that decides whether it applies.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->nullable();
            // Local wall-clock 'HH:MM', like the window below — never an
            // instant, so it survives DST without meaning anything different.
            $table->string('default_time', 5)->nullable();
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->string('quiet_hours_start', 5)->default('22:00');
            $table->string('quiet_hours_end', 5)->default('07:00');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'timezone',
                'default_time',
                'quiet_hours_enabled',
                'quiet_hours_start',
                'quiet_hours_end',
            ]);
        });
    }
};
