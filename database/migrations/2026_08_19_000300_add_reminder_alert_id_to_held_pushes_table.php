<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which *kind* of push is waiting for morning: null for a reminder coming
     * due (everything held before this migration), an alert id for a
     * pre-alert.
     *
     * **No foreign key.** SQLite cannot add one to a table that already
     * exists (ARCHITECTURE.md §5), so this is a plain indexed
     * `unsignedBigInteger` and the integrity is held in code:
     * `HeldPush::isSuperseded()` treats a missing alert row as superseded and
     * drops the push, which is the same answer a cascade would have given.
     *
     * The existing unique index — `(user_id, reminder_id, occurred_at)` — is
     * deliberately left alone. Widening it to include this column would make
     * NULLs distinct under SQLite and quietly undo the idempotent holding the
     * main path depends on; and it is already sufficient, because a pre-alert
     * only ever fires *strictly before* its reminder's effective due moment,
     * so an alert's `occurred_at` can never collide with the main
     * occurrence's.
     */
    public function up(): void
    {
        Schema::table('held_pushes', function (Blueprint $table) {
            $table->unsignedBigInteger('reminder_alert_id')->nullable()->index()->after('reminder_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('held_pushes', function (Blueprint $table) {
            $table->dropIndex(['reminder_alert_id']);
            $table->dropColumn('reminder_alert_id');
        });
    }
};
