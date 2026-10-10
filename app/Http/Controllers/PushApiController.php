<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireApiToken;
use App\Models\User;
use App\Notifications\AnnouncementPushNotification;
use App\Support\HouseholdPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The estate push service (HUB SHAPE.md move 3): other sites and box scripts
 * reach the household's phones through Reminders, because this is where the
 * phones are subscribed. Same message `push:send` sends; this is its HTTP
 * twin.
 *
 * Authenticated by the account's phone token in `X-Api-Token`
 * ({@see RequireApiToken}). A token can only ever reach
 * its own household — "both" is the token holder's household, and explicit
 * user ids outside it are refused — so a key minted on one account is never
 * a way to buzz a stranger's phone.
 *
 * See docs/shipped/push-api.md for the wire format.
 */
class PushApiController extends Controller
{
    public function send(Request $request, HouseholdPush $push): JsonResponse
    {
        if (! config('webpush.vapid.public_key') || ! config('webpush.vapid.private_key')) {
            return response()->json([
                'ok' => false,
                'message' => 'Push is not configured on Reminders (no VAPID keys).',
            ], 503);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'tag' => ['nullable', 'string', 'max:100'],
            'to' => ['required'],
        ]);

        /** @var User $sender */
        $sender = $request->user();

        $recipients = $this->resolveRecipients($sender, $data['to']);

        $result = $push->send($recipients, new AnnouncementPushNotification(
            trim($data['title']),
            trim($data['body']),
            ($data['url'] ?? null) ?: null,
            ($data['tag'] ?? null) ?: null,
        ));

        return response()->json([
            'ok' => true,
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
            'message' => $this->summary($result),
        ]);
    }

    /**
     * Who a caller can target: the token holder's household (a household of
     * one when they have none), with each member's subscribed-device count
     * so a caller can see who would actually buzz.
     */
    public function recipients(Request $request): JsonResponse
    {
        /** @var User $holder */
        $holder = $request->user();

        return response()->json([
            'ok' => true,
            'recipients' => $holder->householdMembers()->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'me' => $user->is($holder),
                'devices' => $user->pushSubscriptions()->count(),
            ])->values()->all(),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(User $sender, mixed $to): Collection
    {
        if ($to === 'me') {
            return collect([$sender]);
        }

        if ($to === 'both') {
            return $sender->householdMembers();
        }

        if (! is_array($to) || $to === [] || ! array_is_list($to)) {
            throw ValidationException::withMessages([
                'to' => 'to must be "both", "me" or a list of user ids.',
            ]);
        }

        $ids = [];

        foreach ($to as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages(['to' => 'to must be "both", "me" or a list of user ids.']);
            }

            $ids[] = (int) $id;
        }

        $household = $sender->householdMembers()->keyBy('id');
        $outside = array_values(array_diff($ids, $household->keys()->all()));

        if ($outside !== []) {
            throw ValidationException::withMessages([
                'to' => 'Not in your household: user '.implode(', ', $outside).'. See GET /api/push/recipients.',
            ]);
        }

        return $household->only(array_unique($ids))->values();
    }

    /**
     * @param  array{sent: int, failed: int, skipped: list<int>}  $result
     */
    private function summary(array $result): string
    {
        $devices = fn (int $n): string => $n === 1 ? '1 device' : "{$n} devices";

        $message = 'Sent to '.$devices($result['sent']).'.';

        if ($result['failed'] > 0) {
            $message .= ' '.$devices($result['failed']).' failed.';
        }

        if ($result['skipped'] !== []) {
            $message .= ' '.count($result['skipped']).' recipient(s) have no phone subscribed.';
        }

        return $message;
    }
}
