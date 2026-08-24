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
            // Opt-in, per reminder: when this one goes off nobody's phone
            // buzzes. The occurrence is still claimed, still dispatched and
            // still written to the notification history — only the web-push
            // half is dropped, exactly the split quiet hours already make
            // (ReminderDueNotification::CHANNELS_IN_APP). Default false keeps
            // every existing reminder loud.
            $table->boolean('is_silenced')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn('is_silenced');
        });
    }
};
