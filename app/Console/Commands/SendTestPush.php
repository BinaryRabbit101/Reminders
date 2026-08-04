<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Console\Command;

class SendTestPush extends Command
{
    protected $signature = 'push:test {user? : User id or email to notify}';

    protected $description = 'Send a test web push notification to a user (verifies the push pipeline end-to-end).';

    public function handle(): int
    {
        if (! config('webpush.vapid.public_key') || ! config('webpush.vapid.private_key')) {
            $this->error('VAPID keys are not configured. Run: php artisan webpush:vapid');

            return self::FAILURE;
        }

        $arg = $this->argument('user');

        $user = $arg
            ? User::query()->where('id', $arg)->orWhere('email', $arg)->first()
            : User::query()->whereHas('pushSubscriptions')->first();

        if (! $user) {
            $this->error('No matching user found (or no user has a push subscription yet).');

            return self::FAILURE;
        }

        $subscriptions = $user->pushSubscriptions()->count();

        if ($subscriptions === 0) {
            $this->warn("User {$user->name} has no push subscriptions. Enable notifications in Settings first.");

            return self::FAILURE;
        }

        $user->notify(new TestPushNotification);

        $this->info("Sent test push to {$user->name} ({$subscriptions} device(s)).");

        return self::SUCCESS;
    }
}
