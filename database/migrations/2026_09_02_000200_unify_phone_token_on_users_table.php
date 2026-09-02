<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One key for the phone, not two.
     *
     * The quick-add endpoint shipped earlier today with its own
     * `shortcut_token`, on the reasoning that a read credential should not
     * quietly become a write one. In practice that reasoning bought nothing:
     * both tokens live on the same phone, in the same person's hands, behind
     * the same tailnet — so the split only meant two things to paste, two
     * things to keep in step, and a widget link that mysteriously failed when
     * pasted into the Shortcut. The owner asked for one key; this is it.
     *
     * `widget_token` is *renamed* rather than dropped and re-minted, so the
     * value already sitting in a Scriptable CONFIG on a phone keeps working —
     * nobody has to re-paste anything to get the unified key. The name goes
     * with it: a column called `widget_token` that also authorizes writes is
     * exactly the sort of thing that misleads the next reader.
     *
     * Consequence worth knowing, recorded here because the schema is where it
     * bites: rolling this token now revokes *both* uses at once. There is
     * still no other way to take a bearer token back.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['shortcut_token']);
            $table->dropColumn('shortcut_token');
        });

        // Split across calls on purpose: SQLite needs the index gone before
        // the column it covers is renamed, and the new one added after.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['widget_token']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('widget_token', 'phone_token');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone_token');
        });
    }

    /**
     * Reverse the migrations.
     *
     * The token goes back to being the widget's, and the shortcut column
     * comes back empty — which revokes quick-add for everybody, because there
     * is no way to recover a value that was never stored twice.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_token']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('phone_token', 'widget_token');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('widget_token');
            $table->string('shortcut_token')->nullable()->unique();
        });
    }
};
