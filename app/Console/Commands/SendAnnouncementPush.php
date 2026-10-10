<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\AnnouncementPushNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendAnnouncementPush extends Command
{
    protected $signature = 'push:send
        {users* : User ids or emails to notify}
        {--title= : Notification title}
        {--body= : Notification body}
        {--url= : Page to open on tap (defaults to Today; may be off-site)}
        {--tag= : Replaces an earlier notification with the same tag on the device}';

    protected $description = 'Send a free-form web push to one or more users (used by the box\'s Automations watchers).';

    public function handle(): int
    {
        if (! config('webpush.vapid.public_key') || ! config('webpush.vapid.private_key')) {
            $this->error('VAPID keys are not configured. Run: php artisan webpush:vapid');

            return self::FAILURE;
        }

        $title = trim((string) $this->option('title'));
        $body = trim((string) $this->option('body'));

        if ($title === '' || $body === '') {
            $this->error('Both --title and --body are required.');

            return self::FAILURE;
        }

        $notification = new AnnouncementPushNotification(
            $title,
            $body,
            $this->option('url') ?: null,
            $this->option('tag') ?: null,
        );

        $sent = 0;

        foreach ((array) $this->argument('users') as $arg) {
            $user = User::query()->where('id', $arg)->orWhere('email', Str::lower(trim($arg)))->first();

            if (! $user) {
                $this->warn("No user matches {$arg}.");

                continue;
            }

            if ($user->pushSubscriptions()->count() === 0) {
                $this->warn("{$user->name} has no push subscriptions.");

                continue;
            }

            $user->notify($notification);
            $this->info("Sent to {$user->name}.");
            $sent++;
        }

        return $sent > 0 ? self::SUCCESS : self::FAILURE;
    }
}
