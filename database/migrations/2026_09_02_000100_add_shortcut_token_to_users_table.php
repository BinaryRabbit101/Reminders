<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The bearer token behind the quick-add endpoint the iPhone Shortcut
     * posts to (`POST /api/shortcut/reminders`). Same shape as
     * `widget_token`, and deliberately *not* that column reused: the widget
     * token is already sitting in a Scriptable CONFIG on a phone, where it
     * buys read access to a reminder list. Letting the same string also write
     * would upgrade a credential that is already out in the world, and would
     * collapse two different revocations into one button
     * (quick-add-shortcut spec).
     *
     * Nullable is the resting state for the same reason it is there: an
     * account carries no live write key until it asks for one. Unique is the
     * integrity tool, not decoration — two accounts sharing a token would be
     * two accounts sharing an identity (ARCHITECTURE.md §5).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('shortcut_token')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['shortcut_token']);
            $table->dropColumn('shortcut_token');
        });
    }
};
