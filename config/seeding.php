<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Development accounts
    |--------------------------------------------------------------------------
    |
    | DevelopmentAdminSeeder (local/testing only) sets this password on accounts it creates. When empty, a random
    | password is generated and printed once. Existing accounts never have their password reset.
    |
    */

    'development_password' => env('DEV_SEED_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Synthetic UI demo fixtures
    |--------------------------------------------------------------------------
    |
    | JuthoorUiDemoSeeder runs only in local/testing environments and only with this explicit opt-in. It is never
    | called by DatabaseSeeder.
    |
    */

    'demo_fixtures' => (bool) env('JUTHOOR_DEMO_FIXTURES', false),

];
