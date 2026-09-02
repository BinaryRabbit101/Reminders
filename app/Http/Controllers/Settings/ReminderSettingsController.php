<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ReminderSettingsRequest;
use App\Models\User;
use App\Support\ReminderPresenter;
use App\Support\ReminderTimezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Reminders: the three preferences that tune the delivery engine.
 *
 * Why its own page rather than an extension of settings/Notifications: that
 * page is about **this device** — whether the browser you are holding has a
 * push subscription, a fact the server cannot even see. These are account
 * preferences that follow you onto every device you own. Folding them
 * together would make one page half device-scoped and half account-scoped,
 * with no way to say which half a control belonged to. The pages link to each
 * other instead.
 */
class ReminderSettingsController extends Controller
{
    /**
     * Show the reminder settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Reminders', $this->payload($request->user()));
    }

    /**
     * Save the preferences.
     */
    public function update(ReminderSettingsRequest $request): RedirectResponse
    {
        $request->user()->fill($request->settingsAttributes())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder settings saved.')]);

        return to_route('reminder-settings.edit');
    }

    /**
     * Mint this account a phone token — or roll the one it already has.
     *
     * One button does both jobs, because they are the same job: there is no
     * way to revoke a bearer token other than replacing it, so "generate" and
     * "generate a new one" are one action with two labels.
     *
     * And one button for **both** phone surfaces, because it is one key. The
     * toast says what rolling costs rather than burying it on the page: the
     * widget and the Shortcut both stop working until the new value is pasted
     * into each.
     */
    public function regeneratePhoneToken(Request $request): RedirectResponse
    {
        $existed = $request->user()->phone_token !== null;

        $request->user()->regeneratePhoneToken();

        Inertia::flash('toast', ['type' => 'success', 'message' => $existed
            ? __('New key generated. Paste it into the widget and the Shortcut — the old one no longer works.')
            : __('Phone key generated.'),
        ]);

        return to_route('reminder-settings.edit');
    }

    /**
     * Everything the page renders.
     *
     * The raw columns travel as `settings` — nulls included, because null is
     * the "app default" option and the select has to be able to reopen on it.
     * Everything under `effective` is the same preferences *resolved*, already
     * spelled out as strings: what the user will actually get, whether they
     * chose it or inherited it. As everywhere else in this app, the client
     * renders those strings rather than assembling them (today-view close-out).
     *
     * @return array{
     *     settings: array{
     *         timezone: string|null,
     *         default_time: string|null,
     *         quiet_hours_enabled: bool,
     *         quiet_hours_start: string,
     *         quiet_hours_end: string,
     *     },
     *     timezones: list<array{value: string, label: string}>,
     *     effective: array{
     *         timezone: string,
     *         timezone_label: string,
     *         default_time_label: string,
     *         quiet_hours_label: string,
     *     },
     *     app_defaults: array{timezone_label: string, default_time_label: string},
     *     phone: array{
     *         token: string|null,
     *         feed_url: string|null,
     *         shortcut_endpoint: string,
     *     },
     * }
     */
    private function payload(User $user): array
    {
        $quiet = $user->quietHours();

        return [
            'settings' => [
                'timezone' => $user->timezone,
                'default_time' => $user->default_time,
                'quiet_hours_enabled' => $user->quiet_hours_enabled,
                'quiet_hours_start' => $user->quiet_hours_start,
                'quiet_hours_end' => $user->quiet_hours_end,
            ],
            'timezones' => ReminderTimezones::options(),
            'effective' => [
                'timezone' => $user->timezone(),
                'timezone_label' => ReminderTimezones::label($user->timezone()),
                'default_time_label' => ReminderPresenter::timeLabel($user->defaultTime()),
                'quiet_hours_label' => ReminderPresenter::timeLabel($quiet->start())
                    .' to '.ReminderPresenter::timeLabel($quiet->end()),
            ],
            'app_defaults' => [
                'timezone_label' => ReminderTimezones::label((string) config('reminders.timezone')),
                'default_time_label' => ReminderPresenter::timeLabel((string) config('reminders.default_time')),
            ],
            // One key, both phone surfaces. Three fields rather than one,
            // because the two surfaces want the same secret in different
            // shapes: the widget's CONFIG takes a whole URL with the token in
            // its query string, while the Shortcut takes the endpoint and the
            // key separately and sends the key as a header.
            //
            // `token` is null until the account asks for one — an unused
            // account should not be carrying a live bearer token it never
            // wanted — and `feed_url` follows it, since a link with no token
            // in it is not a link to anything. The endpoint is shown either
            // way: it is not a secret, and seeing where the button will point
            // is half of understanding what it does.
            'phone' => [
                'token' => $user->phone_token,
                'feed_url' => $user->phone_token === null
                    ? null
                    : route('widget.today', ['token' => $user->phone_token]),
                'shortcut_endpoint' => route('shortcut.reminders.store'),
            ],
        ];
    }
}
