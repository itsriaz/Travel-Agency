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
            'branch_label' => 'Swat Branch',
            'display_name' => 'Imdad International Travels & Tours',
            'location_label' => 'Matta Swat, Pakistan',
            'contact_person' => 'Imdad Khan - Managing Director',
            'address' => 'Baghdehrai Road, Shop No. 3-4, Swat Market, Matta Swat.',
            'license' => 'PR: 4985',
            'email' => 'imdadint9036@gmail.com',
            'landline' => '0092 946 790738',
            'landline_label' => 'Phone',
            'logo_path' => '/assets/images/receipt-branches/swat-branch-logo-crop.png',
            'service_note' => 'Hajj, Umrah, Visit & Air Tickets',
            'contacts' => [
                ['name' => 'Imdad Khan', 'role' => 'Managing Director', 'phone' => '00923467788738'],
                ['name' => 'Abid Khan', 'role' => 'Office Manager', 'phone' => '00923401740444'],
                ['name' => 'Hazrat Bilal', 'role' => 'Sales Executive', 'phone' => '00923174430004'],
                ['name' => 'Mudassir', 'role' => 'Accounts', 'phone' => '00923409795921'],
            ],
        ],
        'dubai' => [
            'branch_label' => 'Dubai Branch',
            'display_name' => 'Noble Route Travel And Tours LLC',
            'location_label' => 'Dubai, UAE',
            'contact_person' => 'Fida Hussain Khan',
            'email' => 'nobleroutetravel@gmail.com',
            'landline' => '(04) 334 5596',
            'landline_label' => 'Phone',
            'logo_path' => '/assets/images/receipt-branches/dubai-branch-logo-crop.png',
            'contacts' => [
                ['name' => 'Abubakar Khan', 'role' => '', 'phone' => '0507135210'],
                ['name' => 'Office Contact 1', 'role' => '', 'phone' => '0545161203'],
                ['name' => 'Office Contact 2', 'role' => '', 'phone' => '0562388796'],
            ],
        ],
    ],
];
