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
        Schema::table('users', function (Blueprint $table) {
            // Deliberately *not* `->constrained()`: adding a foreign key to an
            // existing SQLite table means rebuilding it, which would touch the
            // constraint set every other table already points at
            // (ARCHITECTURE.md §5). Membership is a nullable pointer the app
            // clears itself when a household is emptied out.
            $table->foreignId('household_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');
        });
    }
};
