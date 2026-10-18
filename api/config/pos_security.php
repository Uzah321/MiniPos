<?php

return [
    'login_rate_limit' => [
        // Only failed credential responses consume these budgets. Successful
        // logins, login-mode discovery and terminal-activation errors do not.
        'credential_failures_per_minute' => max(
            1,
            (int) env('POS_LOGIN_CREDENTIAL_FAILURES_PER_MINUTE', 10)
        ),
        'terminal_failures_per_minute' => max(
            1,
            (int) env('POS_LOGIN_TERMINAL_FAILURES_PER_MINUTE', 10)
        ),
        'network_failures_per_minute' => max(
            1,
            (int) env('POS_LOGIN_NETWORK_FAILURES_PER_MINUTE', 30)
        ),
        'mode_requests_per_minute' => max(
            1,
            (int) env('POS_LOGIN_MODE_REQUESTS_PER_MINUTE', 120)
        ),
    ],

    'manager_approval_rate_limit' => [
        // Successful approvals do not consume these failure budgets.
        'manager_failures_per_minute' => max(
            1,
            (int) env('POS_MANAGER_APPROVAL_MANAGER_FAILURES_PER_MINUTE', 5)
        ),
        'actor_failures_per_minute' => max(
            1,
            (int) env('POS_MANAGER_APPROVAL_ACTOR_FAILURES_PER_MINUTE', 10)
        ),
        'network_failures_per_minute' => max(
            1,
            (int) env('POS_MANAGER_APPROVAL_NETWORK_FAILURES_PER_MINUTE', 30)
        ),
    ],
];
