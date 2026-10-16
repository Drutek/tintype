<?php

return [
    // Base URL capture workers use to reach this server (hosted Storybooks, baseline images).
    // Defaults to APP_URL; set it when workers sit on a private network (e.g. http://web:8080).
    'internal_url' => env('TINTYPE_INTERNAL_URL'),

    // Shared secret for capture workers (Authorization: Bearer <token>).
    'worker_token' => env('TINTYPE_WORKER_TOKEN'),

    // Stories per capture job shard.
    'shard_size' => (int) env('TINTYPE_SHARD_SIZE', 25),

    // Capture image major version; baselines from another major are not reused.
    'capture_image_version' => env('TINTYPE_CAPTURE_IMAGE_VERSION', '1'),

    // Reject results from workers on these architectures (baselines must be x86_64).
    // Set TINTYPE_ALLOW_ANY_ARCH=true for local development on Apple Silicon.
    'allow_any_arch' => (bool) env('TINTYPE_ALLOW_ANY_ARCH', false),

    // Max commits walked when resolving ancestor builds.
    'max_ancestor_commits' => (int) env('TINTYPE_MAX_ANCESTOR_COMMITS', 1000),

    // When set, `tintype:metrics` publishes queue depth here every minute (AWS autoscaling).
    'cloudwatch_namespace' => env('TINTYPE_CLOUDWATCH_NAMESPACE'),

    // Allow self sign-up after the first admin exists.
    'open_registration' => (bool) env('TINTYPE_OPEN_REGISTRATION', false),

    // Browser-facing Reverb endpoint (null key = realtime off; pages fall back to polling).
    'realtime' => [
        'key' => env('BROADCAST_CONNECTION') === 'reverb' ? env('REVERB_APP_KEY') : null,
        'host' => env('TINTYPE_REVERB_PUBLIC_HOST', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        'port' => (int) env('TINTYPE_REVERB_PUBLIC_PORT', env('REVERB_PORT', 8080)),
        'scheme' => env('TINTYPE_REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'http')),
    ],

    // Generic OpenID Connect sign-in. Users matching an existing account by verified email are linked;
    // new users are created when open registration is on or auto_join_org is set.
    'oidc' => [
        'issuer' => env('TINTYPE_OIDC_ISSUER'),
        'client_id' => env('TINTYPE_OIDC_CLIENT_ID'),
        'client_secret' => env('TINTYPE_OIDC_CLIENT_SECRET'),
        'label' => env('TINTYPE_OIDC_LABEL', 'Single sign-on'),
        'auto_join_org' => env('TINTYPE_OIDC_AUTO_JOIN_ORG'),
        'trust_email' => (bool) env('TINTYPE_OIDC_TRUST_EMAIL', false),
    ],

    'github' => [
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'web_url' => env('GITHUB_WEB_URL', 'https://github.com'),
        'check_name' => env('TINTYPE_CHECK_NAME', 'Visual Tests'),
        'ui_review_check_name' => env('TINTYPE_UI_REVIEW_CHECK_NAME', 'UI Review'),
    ],
];
