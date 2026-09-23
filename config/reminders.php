<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Every datetime column in this app is stored in UTC (see app.timezone).
    | This is the one timezone users read and write times in: dates entered
    | in the UI are interpreted here before being converted to UTC, and
    | stored UTC values are rendered back through it for display.
    |
    */

    'timezone' => env('REMINDERS_TIMEZONE', 'America/Chicago'),

    /*
    |--------------------------------------------------------------------------
    | Default Reminder Time
    |--------------------------------------------------------------------------
    |
    | Local wall-clock time (in the timezone above) used when a reminder is
    | given a date but no time.
    |
    */

    'default_time' => '09:00',

    /*
    |--------------------------------------------------------------------------
    | Completion Hook
    |--------------------------------------------------------------------------
    |
    | A shell command run whenever a reminder is completed *with a note* (the
    | "Ask for a note when done" option). It receives one JSON object on
    | stdin describing the completion — see App\Jobs\RunCompletionHook for
    | the fields — and runs on the queue, never inline, so it may take its
    | time. A non-zero exit fails the attempt and it is retried.
    |
    | Empty (the default) means no hook: nothing is queued, and a note is
    | simply kept on the completion row.
    |
    */

    'completion_hook' => env('REMINDERS_COMPLETION_HOOK'),

];
