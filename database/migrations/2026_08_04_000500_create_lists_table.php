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
        Schema::create('lists', function (Blueprint $table) {
            $table->id();

            // A list is one person's filing system, never a household's — the
            // FK is declared here, at creation time, because SQLite cannot
            // add one afterwards (ARCHITECTURE.md §5).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // A token from the fixed palette (App\Support\ListColor), not a
            // hex value: the swatch a token resolves to is presentation, and
            // presentation is allowed to change without a data migration.
            $table->string('color');

            $table->timestamps();

            // "Errands" twice in one account is a mistake, not a feature. The
            // unique index is also the only integrity primitive SQLite gives
            // us, so it carries the guarantee rather than the form request.
            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lists');
    }
};
