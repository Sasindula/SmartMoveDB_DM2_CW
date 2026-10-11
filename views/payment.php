<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (($_SESSION['role'] ?? '') !== 'PASSENGER' || empty($_SESSION['reference_id'])) { header('Location: /SmartMoveDB/login.php'); exit; }
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$pid = (int)$_SESSION['reference_id'];
$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
function payEsc($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
if (!$bookingId) { http_response_code(400); exit('Invalid booking ID.'); }
$stmt = $oracle_conn->prepare("SELECT b.BOOKING_ID,b.SEAT_NUMBER,b.STATUS,t.FARE_AMOUNT,r.SOURCE,r.DESTINATION,TO_CHAR(t.DEPARTURE_TIME,'YYYY-MM-DD HH24:MI:SS') DEPARTURE_TIME FROM BOOKING b JOIN TRIP t ON t.TRIP_ID=b.TRIP_ID JOIN ROUTE r ON r.ROUTE_ID=t.ROUTE_ID WHERE b.BOOKING_ID=:bid AND b.PASSENGER_ID=:pid");
$stmt->execute([':bid'=>$bookingId, ':pid'=>$pid]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) { http_response_code(404); exit('Booking not found.'); }
$booking = array_change_key_case($booking, CASE_LOWER);
$stmt = $oracle_conn->prepare("SELECT PAYMENT_ID,PAYMENT_METHOD,PAYMENT_STATUS,AMOUNT FROM PAYMENT WHERE BOOKING_ID=:bid ORDER BY PAYMENT_ID DESC");
$stmt->execute([':bid'=>$bookingId]);
$payments = array_map('array_change_key_case', $stmt->fetchAll(PDO::FETCH_ASSOC));
$paid = false; $pending = false; $demoPaid = false;
foreach ($payments as $payment) { $status = strtoupper(trim((string)$payment['payment_status'])); if ($status==='PAID') $paid=true; if ($status==='PENDING') $pending=true; if ($status==='DEMO PAID') $demoPaid=true; }
$canRequest = in_array(strtoupper((string)$booking['status']), ['PENDING','CONFIRMED'], true) && !$paid && !$pending && !$demoPaid;
$flash = $_SESSION['passenger_flash'] ?? null; unset($_SESSION['passenger_flash']);
include __DIR__ . '/../includes/header.php';
?>
<main class="container passenger-page">
<section class="panel">
<a href="/SmartMoveDB/views/passenger_dashboard.php#my-bookings">← Back to My Bookings</a>
<h1>Booking Payment</h1>
<?php if ($flash): ?><div class="<?= !empty($flash['ok']) ? 'success-alert' : 'alert' ?>" role="status"><?= payEsc($flash['message']) ?></div><?php endif; ?>
<p><strong>Booking:</strong> #<?= (int)$booking['booking_id'] ?> | <strong>Seat:</strong> <?= payEsc($booking['seat_number']) ?></p>
<p><strong>Route:</strong> <?= payEsc($booking['source']) ?> → <?= payEsc($booking['destination']) ?></p>
<p><strong>Departure:</strong> <?= payEsc($booking['departure_time']) ?></p>
<p><strong>Booking status:</strong> <?= payEsc($booking['status']) ?></p>
<h2>Amount: LKR <?= number_format((float)$booking['fare_amount'], 2) ?></h2>
<?php if ($paid): ?><div class="success-alert">Payment already recorded as Paid.</div>
<?php elseif ($demoPaid): ?><div class="success-alert">Card Demo completed. No real money was charged. This is not a verified payment.</div>
<?php elseif ($pending): ?><div class="alert">Cash payment request already pending. Please pay at the counter or boarding point. Staff must confirm receipt.</div>
<?php elseif (!$canRequest): ?><div class="alert">This booking cannot accept a new payment request.</div>
<?php else: ?>
<form method="post" action="/SmartMoveDB/views/payment_actions.php">
<input type="hidden" name="csrf_token" value="<?= payEsc($_SESSION['csrf_token']) ?>">
<input type="hidden" name="booking_id" value="<?= (int)$bookingId ?>">
<label style="display:block;margin:12px 0"><input type="radio" name="payment_method" value="Cash" checked> Cash — Pay at counter / boarding (Pending)</label>
<label style="display:block;margin:12px 0"><input type="radio" name="payment_method" value="Card"> Card — Simulated payment (Demo Paid)</label>
<p class="muted">Card is a demonstration only: no gateway, real charge, card number, expiry date, or CVV. Never enter real card information.</p>
<button type="submit" class="button">Continue with Selected Method</button>
</form>

<?php endif; ?>
<h2>Payment History</h2>
<div class="table-wrapper"><table><thead><tr><th>ID</th><th>Method</th><th>Amount</th><th>Status</th></tr></thead><tbody>
<?php foreach ($payments as $payment): ?><tr><td>#<?= (int)$payment['payment_id'] ?></td><td><?= payEsc($payment['payment_method']) ?></td><td>LKR <?= number_format((float)$payment['amount'],2) ?></td><td><?= payEsc($payment['payment_status']) ?></td></tr><?php endforeach; ?>
<?php if (!$payments): ?><tr><td colspan="4">No payment requests yet.</td></tr><?php endif; ?>
</tbody></table></div>
</section></main>
