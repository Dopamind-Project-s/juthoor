<?php

/*
| Community publishing (Phase 4). Times are stored in UTC; forms and pages use the community time zone unless an
| event names its own.
*/
return [
    'timezone' => env('COMMUNITY_TIMEZONE', 'Asia/Amman'),

    // After expires_at, news is either hidden from everyone but its managers ('hidden') or kept and labelled ('labelled').
    'expired_news' => env('COMMUNITY_EXPIRED_NEWS', 'hidden'),

    // Items shown in a portal section before the "all news" / calendar pages.
    'portal_news' => 6,
    'portal_events' => 6,
    'per_page' => 12,
];
