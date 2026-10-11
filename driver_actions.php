<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
if (($_SESSION['role'] ?? '') !== 'DRIVER' || empty($_SESSION['reference_id']) || empty($_SESSION['user_id'])) { header('Location: /SmartMoveDB/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$back = '/SmartMoveDB/views/driver_dashboard.php';
function driverResult(bool $ok, string $message): void { global $back; $_SESSION['driver_flash']=['ok'=>$ok,'message'=>$message]; header('Location: '.$back); exit; }
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) driverResult(false, 'Invalid security token. Reload and try again.');
$driverId = (int)$_SESSION['reference_id'];
try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'duty') {
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, ['Active','On Trip','On Leave'], true)) driverResult(false, 'Invalid driver status.');
        $s = $oracle_conn->prepare('BEGIN Update_Driver_Duty_State(:driver_id, :new_state); END;');
        $s->execute([':driver_id'=>$driverId, ':new_state'=>$status]);
        driverResult(true, 'Driver duty status updated.');
    }
    if ($action === 'complete') {
        $tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$tripId || $tripId < 1) driverResult(false, 'Invalid trip.');
        $oracle_conn->beginTransaction();
        $s = $oracle_conn->prepare('SELECT TRIP_ID FROM TRIP WHERE TRIP_ID=:tid AND DRIVER_ID=:did AND ARRIVAL_TIME <= LOCALTIMESTAMP FOR UPDATE');
        $s->execute([':tid'=>$tripId, ':did'=>$driverId]);
        if (!$s->fetch()) { $oracle_conn->rollBack(); driverResult(false, 'Only your own trips after their arrival time can be completed.'); }
        $s = $oracle_conn->prepare("SELECT COUNT(*) FROM BOOKING WHERE TRIP_ID=:tid AND STATUS='Confirmed'");
        $s->execute([':tid'=>$tripId]);
        $count = (int)$s->fetchColumn();
        if ($count === 0) { $oracle_conn->rollBack(); driverResult(false, 'No confirmed bookings to complete.'); }
        $s = $oracle_conn->prepare("UPDATE BOOKING SET STATUS='Completed' WHERE TRIP_ID=:tid AND STATUS='Confirmed'");
        $s->execute([':tid'=>$tripId]);
        $oracle_conn->commit();
        driverResult(true, $count.' confirmed booking(s) marked Completed. Passengers can now leave reviews.');
    }
    driverResult(false, 'Unknown action.');
} catch (Throwable $e) {
    if ($oracle_conn->inTransaction()) $oracle_conn->rollBack();
    error_log('Driver action: '.$e->getMessage());
    driverResult(false, 'Unable to complete request. Check database rules and server logs.');
}
