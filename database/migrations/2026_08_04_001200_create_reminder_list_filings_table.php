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
        Schema::create('reminder_list_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            // The filer — always someone other than the reminder's own owner
            // in practice (the owner's filing lives on reminders.list_id
            // instead), enforced by ReminderListController::assign()/
            // unassign() branching before either write, not by a DB check.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Unlike reminders.list_id (nullOnDelete: a reminder outlives the
            // list it was filed under), a filing *is* the list assignment —
            // one without a list means nothing, so deleting the list deletes
            // the filing rather than leaving a null husk behind.
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();
            $table->timestamps();

            // One filing per (reminder, filer): re-filing moves it rather
            // than stacking a second row.
            $table->unique(['reminder_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_list_filings');
    }
};
