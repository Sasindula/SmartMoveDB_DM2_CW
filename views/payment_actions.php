<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (($_SESSION['role'] ?? '') !== 'PASSENGER' || empty($_SESSION['reference_id'])) { header('Location: /SmartMoveDB/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$bid = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
if (!$bid || $bid < 1) { http_response_code(400); exit('Invalid booking ID'); }
$redirect = '/SmartMoveDB/views/payment.php?booking_id=' . $bid;
function paymentRedirect(string $message, bool $ok=false): void { global $redirect; $_SESSION['passenger_flash']=['ok'=>$ok,'message'=>$message]; header('Location: '.$redirect); exit; }
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) paymentRedirect('Invalid request. Refresh the page.');
$method = (string)($_POST['payment_method'] ?? '');
if (!in_array($method, ['Cash','Card'], true)) paymentRedirect('Select Cash or Card Demo.');
$pid = (int)$_SESSION['reference_id'];
try {
    $oracle_conn->beginTransaction();
    // Lock booking to serialize concurrent requests from this application.
    $stmt = $oracle_conn->prepare("SELECT b.BOOKING_ID,b.STATUS,t.FARE_AMOUNT FROM BOOKING b JOIN TRIP t ON t.TRIP_ID=b.TRIP_ID WHERE b.BOOKING_ID=:bid AND b.PASSENGER_ID=:pid FOR UPDATE OF b.STATUS");
    $stmt->execute([':bid'=>$bid,':pid'=>$pid]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) throw new RuntimeException('Booking not found.');
    $booking = array_change_key_case($booking, CASE_LOWER);
    if (!in_array(strtoupper(trim((string)$booking['status'])), ['PENDING','CONFIRMED'], true)) throw new RuntimeException('This booking is not eligible for payment.');
    if ((float)$booking['fare_amount'] <= 0) throw new RuntimeException('Invalid trip fare.');
    $check = $oracle_conn->prepare("SELECT COUNT(*) FROM PAYMENT WHERE BOOKING_ID=:bid AND UPPER(TRIM(PAYMENT_STATUS)) IN ('PENDING','PAID','DEMO PAID')");
    $check->execute([':bid'=>$bid]);
    if ((int)$check->fetchColumn() > 0) throw new RuntimeException('An existing pending, paid, or demo-paid payment is already recorded for this booking.');
    $status = $method === 'Card' ? 'Demo Paid' : 'Pending';
    $insert = $oracle_conn->prepare("INSERT INTO PAYMENT (BOOKING_ID,AMOUNT,PAYMENT_METHOD,PAYMENT_DATE,PAYMENT_STATUS) VALUES (:bid,:amount,:method,SYSTIMESTAMP,:status)");
    $insert->bindValue(':bid', $bid, PDO::PARAM_INT);
    $insert->bindValue(':amount', number_format((float)$booking['fare_amount'],2,'.',''));
    $insert->bindValue(':method', $method);
    $insert->bindValue(':status', $status);
    $insert->execute();
    $oracle_conn->commit();
    paymentRedirect($method === 'Card' ? 'Card demo completed (simulation only). No money was charged. Status: Demo Paid.' : 'Cash payment request saved as Pending. Pay at the counter; staff will confirm receipt.', true);
} catch (Throwable $e) {
    if ($oracle_conn->inTransaction()) $oracle_conn->rollBack();
    error_log('SmartMove payment request: '.$e->getMessage());
    paymentRedirect($e instanceof RuntimeException ? $e->getMessage() : 'Could not save payment request. Check server logs.');
}
