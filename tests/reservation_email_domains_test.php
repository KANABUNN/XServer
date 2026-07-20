<?php
declare(strict_types=1);

require_once __DIR__ . '/../apps/db.php';
require_once __DIR__ . '/../apps/reservation_service.php';

function reservation_domain_test_source(string $email, string $useDate): array
{
    return [
        'email' => $email,
        'organization_name' => 'テスト団体',
        'agree_terms' => '1',
        'room_code' => 'tamoku',
        'use_dates' => [$useDate],
        'usage_start_time' => '09:00',
        'usage_end_time' => '10:00',
    ];
}

function reservation_domain_test_assert(
    array $config,
    string $email,
    string $useDate,
    bool $expected
): void {
    try {
        reservation_validate_form_input(
            $config,
            reservation_domain_test_source($email, $useDate)
        );
        $actual = true;
    } catch (RuntimeException $e) {
        $actual = false;
    }

    if ($actual === $expected) {
        return;
    }

    fwrite(
        STDERR,
        sprintf(
            "reservation email domain test failed: %s (expected %s)\n",
            $email,
            $expected ? 'accepted' : 'rejected'
        )
    );
    exit(1);
}

$reservationDefaults = [
    'timezone' => 'Asia/Tokyo',
    'booking_min_days_before' => 0,
    'booking_max_months_ahead' => 2,
    'time_step_minutes' => 15,
    'booking_time_start' => '09:00',
    'booking_time_end' => '20:00',
];
$useDate = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Tokyo')))
    ->format('Y-m-d');

$newConfig = [
    'reservation' => array_merge($reservationDefaults, [
        'email_domains' => ['bene.fit.ac.jp', 'fit.ac.jp'],
    ]),
];
reservation_domain_test_assert($newConfig, 'user@bene.fit.ac.jp', $useDate, true);
reservation_domain_test_assert($newConfig, 'user@fit.ac.jp', $useDate, true);
reservation_domain_test_assert($newConfig, 'user@evilfit.ac.jp', $useDate, false);
reservation_domain_test_assert($newConfig, 'user@fit.ac.jp.evil', $useDate, false);

$legacyConfig = [
    'reservation' => array_merge($reservationDefaults, [
        'email_domain' => 'bene.fit.ac.jp',
    ]),
];
reservation_domain_test_assert($legacyConfig, 'user@bene.fit.ac.jp', $useDate, true);
reservation_domain_test_assert($legacyConfig, 'user@fit.ac.jp', $useDate, true);

fwrite(STDOUT, "reservation email domain tests passed\n");
