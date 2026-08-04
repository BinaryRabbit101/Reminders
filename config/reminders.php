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

];
