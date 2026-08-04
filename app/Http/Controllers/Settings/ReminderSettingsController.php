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
     * Mint this account a widget token — or roll the one it already has.
     *
     * One button does both jobs, because they are the same job: there is no
     * way to revoke a bearer token other than replacing it, so "generate" and
     * "generate a new one" are one action with two labels. Rolling it stops
     * the old link resolving immediately, which is exactly what somebody
     * clicking it wants; the toast says so rather than burying it on the page.
     */
    public function regenerateWidgetToken(Request $request): RedirectResponse
    {
        $existed = $request->user()->widget_token !== null;

        $request->user()->regenerateWidgetToken();

        Inertia::flash('toast', ['type' => 'success', 'message' => $existed
            ? __('New widget link generated. The old one no longer works.')
            : __('Widget link generated.'),
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
     *     widget: array{token: string|null, feed_url: string|null},
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
            // The home-screen widget's feed. The whole URL is assembled here,
            // token and all, because pasting it into the widget's CONFIG is
            // the only thing anybody ever does with it — a bare token would
            // just make the reader build this string by hand on a phone.
            //
            // Null until the account asks for one: an unused account should
            // not be carrying a live bearer token it never wanted.
            'widget' => [
                'token' => $user->widget_token,
                'feed_url' => $user->widget_token === null
                    ? null
                    : route('widget.today', ['token' => $user->widget_token]),
            ],
        ];
    }
}
