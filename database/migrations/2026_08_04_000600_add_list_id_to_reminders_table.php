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
            // A nullable column with a FK is the one shape SQLite accepts on
            // an existing table: `ALTER TABLE ... ADD COLUMN ... REFERENCES`
            // is legal precisely because every existing row defaults to null.
            //
            // `nullOnDelete` is the "orphan, don't cascade" rule at the
            // database level — deleting a list must never take its reminders
            // with it. The controller nulls the column explicitly as well, so
            // the behaviour holds even where FK enforcement is off.
            $table->foreignId('list_id')
                ->nullable()
                ->constrained('lists')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('list_id');
        });
    }
};
