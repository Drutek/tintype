<?php

return [
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

    // Allow self sign-up after the first admin exists.
    'open_registration' => (bool) env('TINTYPE_OPEN_REGISTRATION', false),

    'github' => [
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'web_url' => env('GITHUB_WEB_URL', 'https://github.com'),
        'check_name' => env('TINTYPE_CHECK_NAME', 'Visual Tests'),
        'ui_review_check_name' => env('TINTYPE_UI_REVIEW_CHECK_NAME', 'UI Review'),
    ],
];
