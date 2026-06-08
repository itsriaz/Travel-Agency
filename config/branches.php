<?php

declare(strict_types=1);

$envValue = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);

    if ($value === false) {
        $serverValue = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        $value = is_string($serverValue) ? $serverValue : null;
    }

    if ($value === null) {
        return $default;
    }

    $trimmed = trim($value);

    return $trimmed === '' ? $default : $trimmed;
};

$splitCsv = static function (?string $value): array {
    if ($value === null) {
        return [];
    }

    $parts = array_map('trim', explode(',', $value));

    return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
};

$parseIpOverrides = static function (?string $value) use ($splitCsv): array {
    $overrides = [];

    foreach ($splitCsv($value) as $pair) {
        [$ip, $branch] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
        if ($ip === '' || $branch === '') {
            continue;
        }

        $overrides[$ip] = $branch;
    }

    return $overrides;
};

return [
    'login' => [
        'country_headers' => $splitCsv($envValue(
            'BRANCH_LOGIN_COUNTRY_HEADERS',
            'HTTP_CF_IPCOUNTRY,HTTP_X_COUNTRY_CODE,GEOIP_COUNTRY_CODE,HTTP_X_APP_COUNTRY_CODE'
        )),
        'ip_overrides' => $parseIpOverrides($envValue('BRANCH_LOGIN_IP_OVERRIDES', '')),
    ],
    'receipt_contacts' => [
        'swat' => [
            'display_name' => 'Imdad International Travels & Tours',
            'address' => 'Baghdehrai Road, Shop No. 3-4, Swat Market, Matta Swat.',
            'license' => 'PR: 4985',
            'email' => 'imdadint9036@gmail.com',
            'landline' => '0092 946 790738',
            'service_note' => 'Hajj, Umrah, Visit & Air Tickets',
            'contacts' => [
                ['name' => 'Imdad Khan', 'role' => 'Managing Director', 'phone' => '00923467788738'],
                ['name' => 'Abid Khan', 'role' => 'Office Manager', 'phone' => '00923401740444'],
                ['name' => 'Tayyab', 'role' => 'Accounts', 'phone' => '00923174430004'],
                ['name' => 'Mudassir', 'role' => 'Sales Executive', 'phone' => '00923409795921'],
            ],
        ],
        'dubai' => [
            'display_name' => 'Noble Route Travels',
            'email' => 'nobleroutetravel@gmail.com',
            'landline' => '(04) 334 5596',
            'contacts' => [
                ['name' => 'Mobile', 'role' => 'Contact', 'phone' => '0507135210'],
                ['name' => 'Mobile', 'role' => 'Contact', 'phone' => '0545161203'],
                ['name' => 'Mobile', 'role' => 'Contact', 'phone' => '0562388796'],
            ],
        ],
    ],
];
