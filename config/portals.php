<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Family portals and hosts
    |--------------------------------------------------------------------------
    |
    | One application serves the platform and every family website. Host routing stays off until DNS, TLS and the
    | reverse proxy are verified; with it off, family portals are served only on platform paths (/families/{slug}).
    | With it on, every request must use the platform host, an alias below, or an active family domain; anything
    | else is refused (no Host-header confusion).
    |
    */

    'host_routing' => (bool) env('JUTHOOR_FAMILY_HOSTS', false),

    'platform_host' => strtolower((string) env('JUTHOOR_PLATFORM_HOST', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost')),

    // Extra hosts that serve the central platform (local development, health checks).
    'platform_aliases' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) env('JUTHOOR_PLATFORM_ALIASES', 'localhost,127.0.0.1')))))),

    // Family subdomains are "<label>.<suffix>", e.g. al-zoubi.juthoor.dopamind.cloud.
    'subdomain_suffix' => strtolower((string) env('JUTHOOR_FAMILY_DOMAIN_SUFFIX', '')) ?: null,

    'scheme' => env('JUTHOOR_PORTAL_SCHEME', 'https'),

    // When true, the legacy static Al-Zoubi pages redirect (302) to the dynamic portal. Enable only after comparison.
    'canonical_portal' => (bool) env('JUTHOOR_PORTAL_CANONICAL', false),

    // Hostname labels and family slugs that can never belong to a family.
    'reserved_labels' => [
        'www', 'juthoor', 'admin', 'site-admin', 'manage', 'management', 'api', 'app', 'auth', 'login', 'register', 'account',
        'mail', 'smtp', 'imap', 'pop', 'ftp', 'ns1', 'ns2', 'dns', 'cdn', 'static', 'assets', 'media', 'storage', 'files',
        'docs', 'help', 'support', 'status', 'blog', 'dev', 'staging', 'test', 'demo', 'preview', 'portal', 'families',
        'family', 'dashboard', 'root', 'system', 'platform', 'dopamind', 'localhost', 'up',
    ],

    // Approved accent palettes (no free-form colours or CSS).
    'accents' => [
        'heritage' => ['label' => 'أخضر تراثي', 'primary' => '#163f32', 'accent' => '#b08a4a', 'soft' => '#f6f1e6'],
        'olive' => ['label' => 'زيتوني', 'primary' => '#3f4a2a', 'accent' => '#a8874f', 'soft' => '#f5f2e8'],
        'sand' => ['label' => 'رملي', 'primary' => '#5b4630', 'accent' => '#c19a5b', 'soft' => '#f8f2e7'],
        'maroon' => ['label' => 'عنابي', 'primary' => '#5e2430', 'accent' => '#b48a55', 'soft' => '#f7f0ea'],
        'night' => ['label' => 'كحلي', 'primary' => '#1d2f45', 'accent' => '#b8975a', 'soft' => '#f3f1ea'],
    ],

];
