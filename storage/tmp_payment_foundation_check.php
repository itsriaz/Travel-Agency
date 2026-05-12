<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
$app = \App\Core\App::bootstrap(BASE_PATH);
$bookingRepository = new \App\Repositories\BookingRepository($app);
$foundation = new \App\Services\CustomerPaymentFoundationService($app);
$booking = $bookingRepository->findBookingById(289);
$result = $foundation->buildWorkspacePreview(
    (string) $booking['booking_reference'],
    (int) ($booking['lead_traveler_id'] ?? 0),
    [1,2],
    [
        'booking_id' => (int) ($booking['id'] ?? 0),
        'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
        'booking_date' => (string) ($booking['booking_date'] ?? ''),
    ]
);
echo json_encode($result['summary'], JSON_PRETTY_PRINT), PHP_EOL;
