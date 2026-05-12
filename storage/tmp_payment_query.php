<?php
$dbConfig = require __DIR__ . '/../config/database.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['database'], $dbConfig['charset']);
$pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$travelerId = 150;
echo "BOOKINGS\n";
$sql1 = "SELECT b.id, b.booking_reference, b.booking_date, b.branch_id, bp.lead_traveler_name FROM bookings b LEFT JOIN booking_parties bp ON bp.booking_id=b.id WHERE b.lead_traveler_id=? ORDER BY b.booking_date, b.id";
$stmt = $pdo->prepare($sql1); $stmt->execute([$travelerId]); foreach($stmt->fetchAll() as $r){echo json_encode($r), PHP_EOL;}
echo "RECEIVABLES\n";
$sql2 = "SELECT id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount, status FROM customer_receivable_items WHERE booking_reference IN (SELECT booking_reference FROM bookings WHERE lead_traveler_id=?) ORDER BY booking_reference, id";
$stmt = $pdo->prepare($sql2); $stmt->execute([$travelerId]); foreach($stmt->fetchAll() as $r){echo json_encode($r), PHP_EOL;}
echo "RECEIPTS\n";
$sql3 = "SELECT id, booking_reference, receipt_no, currency, received_amount, allocated_amount, unallocated_amount, status FROM customer_receipts WHERE booking_reference IN (SELECT booking_reference FROM bookings WHERE lead_traveler_id=?) ORDER BY booking_reference, id";
$stmt = $pdo->prepare($sql3); $stmt->execute([$travelerId]); foreach($stmt->fetchAll() as $r){echo json_encode($r), PHP_EOL;}
echo "ALLOCATIONS\n";
$sql4 = "SELECT cr.receipt_no, cr.booking_reference, cri.service_line_reference, cra.allocated_amount FROM customer_receipt_allocations cra INNER JOIN customer_receipts cr ON cr.id=cra.customer_receipt_id INNER JOIN customer_receivable_items cri ON cri.id=cra.customer_receivable_item_id WHERE cr.booking_reference IN (SELECT booking_reference FROM bookings WHERE lead_traveler_id=?) ORDER BY cr.booking_reference, cr.id, cra.id";
$stmt = $pdo->prepare($sql4); $stmt->execute([$travelerId]); foreach($stmt->fetchAll() as $r){echo json_encode($r), PHP_EOL;}
?>
