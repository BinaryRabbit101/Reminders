<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The bearer token behind the home-screen widget's feed
     * (`GET /api/widget/today?token=…`). Per-user rather than app-wide,
     * because two accounts share this app and each phone's widget must see
     * *its owner's* reminders — an env-held app token could not tell them
     * apart (scriptable-widget spec).
     *
     * Nullable is the resting state: an account has no token until it asks
     * for one on the settings page, and nothing about the app changes for an
     * account that never does. Unique is load-bearing rather than tidy — the
     * token is the whole authentication, so two accounts sharing one would be
     * two accounts sharing an identity. A unique index is also SQLite's only
     * real integrity tool here (ARCHITECTURE.md §5); the generator's
     * collision loop only keeps the insert from failing in practice.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('widget_token')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['widget_token']);
            $table->dropColumn('widget_token');
        });
    }
};
