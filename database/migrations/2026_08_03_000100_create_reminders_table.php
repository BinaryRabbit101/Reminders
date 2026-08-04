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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            // SQLite cannot add a foreign key after the table exists, so the
            // constraint is declared here rather than in a later migration.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            // UTC, always. Local wall-time is converted on the way in.
            $table->dateTime('due_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('snoozed_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
