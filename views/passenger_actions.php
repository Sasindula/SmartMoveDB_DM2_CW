<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (($_SESSION['role'] ?? '') !== 'PASSENGER' || empty($_SESSION['reference_id']) || empty($_SESSION['user_id'])) {
    header('Location: /SmartMoveDB/login.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed');
}

$passengerId = (int)$_SESSION['reference_id'];
$action = (string)($_POST['action'] ?? '');
$token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    $_SESSION['passenger_flash'] = ['ok' => false, 'message' => 'Invalid form request. Refresh and try again.'];
    header('Location: passenger_dashboard.php'); exit;
}

function goBack(string $message, bool $success = false): void {
    $_SESSION['passenger_flash'] = ['ok' => $success, 'message' => $message];
    header('Location: passenger_dashboard.php#booking-message'); exit;
}
function validSeat(string $seat, int $capacity): bool {
    // Four seats per row: A1..A4, B1..B4, ... ; capacity may end mid-row.
    if ($capacity<1 || $capacity>1000 || strlen($seat)>10 || !preg_match('/^([A-Z]+)([1-4])$/', $seat, $m)) return false;
    $row = 0;
    foreach (str_split($m[1]) as $letter) $row = $row * 26 + ord($letter) - 64;
    $seatIndex = ($row - 1) * 4 + (int)$m[2];
    return $seatIndex >= 1 && $seatIndex <= $capacity;
}

try {
    if ($action === 'book' || $action === 'book_seat') {
        $tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT);
        $seat = strtoupper(trim((string)($_POST['seat_number'] ?? $_POST['seat'] ?? '')));
        if (!$tripId || $tripId<1 || !$seat) goBack('Choose a trip and an available seat.');

        $oracle_conn->beginTransaction();
        // Lock the trip so all booking requests through this endpoint serialize.
        $stmt = $oracle_conn->prepare(
            "SELECT t.TRIP_ID, TO_CHAR(t.DEPARTURE_TIME, 'YYYY-MM-DD HH24:MI:SS') AS DEPARTURE_TIME,
                    v.CAPACITY, v.STATUS AS VEHICLE_STATUS, d.STATUS AS DRIVER_STATUS, CASE WHEN t.DEPARTURE_TIME>LOCALTIMESTAMP THEN 1 ELSE 0 END AS BOOKABLE
             FROM TRIP t JOIN VEHICLE v ON v.VEHICLE_ID = t.VEHICLE_ID JOIN DRIVER d ON d.DRIVER_ID=t.DRIVER_ID
             WHERE t.TRIP_ID = :trip_id FOR UPDATE OF t.TRIP_ID"
        );
        $stmt->execute([':trip_id' => $tripId]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trip) throw new RuntimeException('Trip not found.');
        $trip = array_change_key_case($trip, CASE_LOWER);
        if (strtolower(trim((string)$trip['vehicle_status'])) !== 'active') {
            throw new RuntimeException('This vehicle is not available.');
        }
        if (!in_array($trip['driver_status'],['Active','On Trip'],true)) throw new RuntimeException('This driver is not available.');
        if ((int)$trip['bookable']!==1) throw new RuntimeException('This trip has already departed.');
        if (!validSeat($seat, (int)$trip['capacity'])) {
            throw new RuntimeException('Invalid seat for this vehicle.');
        }
        $stmt = $oracle_conn->prepare(
            "SELECT COUNT(*) FROM BOOKING
             WHERE TRIP_ID = :trip_id AND UPPER(TRIM(SEAT_NUMBER)) = :seat
               AND UPPER(TRIM(STATUS)) IN ('PENDING', 'CONFIRMED', 'COMPLETED')"
        );
        $stmt->execute([':trip_id' => $tripId, ':seat' => $seat]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Seat already booked. Please choose another.');
        }
        $stmt=$oracle_conn->prepare("SELECT SEAT_NUMBER FROM BOOKING WHERE TRIP_ID=:tid AND UPPER(TRIM(STATUS)) IN ('PENDING','CONFIRMED','COMPLETED')");
        $stmt->execute([':tid'=>$tripId]); $allocated=$stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach($allocated as $existingSeat) if (!validSeat(strtoupper(trim((string)$existingSeat)),(int)$trip['capacity'])) throw new RuntimeException('Seat records for this trip need administrator review before new bookings.');
        if (count($allocated)>=(int)$trip['capacity']) throw new RuntimeException('This trip is fully booked.');
        $stmt = $oracle_conn->prepare(
            "INSERT INTO BOOKING (TRIP_ID, PASSENGER_ID, SEAT_NUMBER, BOOKING_DATE, STATUS)
             VALUES (:trip_id, :passenger_id, :seat, SYSDATE, 'Pending') RETURNING BOOKING_ID INTO :booking_id"
        );
        $bookingId=0;
        $stmt->bindValue(':trip_id',$tripId,PDO::PARAM_INT); $stmt->bindValue(':passenger_id',$passengerId,PDO::PARAM_INT); $stmt->bindValue(':seat',$seat);
        $stmt->bindParam(':booking_id',$bookingId,PDO::PARAM_INT|PDO::PARAM_INPUT_OUTPUT,32); $stmt->execute();
        $oracle_conn->commit();
        goBack('Booking successful! Booking #' . ($bookingId ?: 'new') . ' | Seat ' . $seat . ' | Status: Pending. No payment charged.', true);
    }

    if ($action === 'cancel' || $action === 'cancel_booking') {
        $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$bookingId) goBack('Invalid booking.');
        $oracle_conn->beginTransaction();
        $stmt=$oracle_conn->prepare('SELECT TRIP_ID FROM BOOKING WHERE BOOKING_ID=:bid AND PASSENGER_ID=:pid');
        $stmt->execute([':bid'=>$bookingId,':pid'=>$passengerId]); $cancelTrip=$stmt->fetchColumn();
        if (!$cancelTrip) throw new RuntimeException('Booking not found.');
        $stmt=$oracle_conn->prepare('SELECT TRIP_ID FROM TRIP WHERE TRIP_ID=:tid FOR UPDATE');
        $stmt->execute([':tid'=>(int)$cancelTrip]);
        if (!$stmt->fetch()) throw new RuntimeException('Trip not found.');
        $stmt = $oracle_conn->prepare(
            "SELECT b.BOOKING_ID, b.STATUS, CASE WHEN t.DEPARTURE_TIME>LOCALTIMESTAMP THEN 1 ELSE 0 END AS CANCELLABLE,
                    TO_CHAR(t.DEPARTURE_TIME, 'YYYY-MM-DD HH24:MI:SS') AS DEPARTURE_TIME
             FROM BOOKING b JOIN TRIP t ON t.TRIP_ID = b.TRIP_ID
             WHERE b.BOOKING_ID = :booking_id AND b.PASSENGER_ID = :passenger_id
             FOR UPDATE OF b.STATUS"
        );
        $stmt->execute([':booking_id' => $bookingId, ':passenger_id' => $passengerId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new RuntimeException('Booking not found.');
        $booking = array_change_key_case($booking, CASE_LOWER);
        if (!in_array(strtolower((string)$booking['status']), ['pending', 'confirmed'], true)) {
            throw new RuntimeException('This booking cannot be cancelled.');
        }
        if ((int)$booking['cancellable']!==1) throw new RuntimeException('Departed trips cannot be cancelled here.');
        $stmt = $oracle_conn->prepare('SELECT COUNT(*) FROM PAYMENT WHERE BOOKING_ID = :booking_id');
        $stmt->execute([':booking_id' => $bookingId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('This booking has a payment. Contact support for cancellation/refund.');
        }
        $stmt = $oracle_conn->prepare(
            "UPDATE BOOKING SET STATUS = 'Cancelled'
             WHERE BOOKING_ID = :booking_id AND PASSENGER_ID = :passenger_id"
        );
        $stmt->execute([':booking_id' => $bookingId, ':passenger_id' => $passengerId]);
        $oracle_conn->commit();
        goBack('Booking cancelled successfully. The seat is available again.', true);
    }

    if ($action === 'update_profile' || $action === 'profile') {
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        if (!$first || !$last || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$phone ||
            strlen($first) > 50 || strlen($last) > 50 || strlen($email) > 100 || strlen($phone) > 15) {
            goBack('Please enter valid profile details.');
        }
        $stmt = $oracle_conn->prepare(
            'SELECT COUNT(*) FROM PASSENGER WHERE LOWER(EMAIL) = LOWER(:email) AND PASSENGER_ID <> :passenger_id'
        );
        $stmt->execute([':email' => $email, ':passenger_id' => $passengerId]);
        if ((int)$stmt->fetchColumn() > 0) goBack('Email already in use.');
        $stmt = $oracle_conn->prepare(
            'UPDATE PASSENGER SET FIRST_NAME=:first_name, LAST_NAME=:last_name, EMAIL=:email, PHONE=:phone WHERE PASSENGER_ID=:passenger_id'
        );
        $stmt->execute([':first_name'=>$first, ':last_name'=>$last, ':email'=>$email, ':phone'=>$phone, ':passenger_id'=>$passengerId]);
        goBack('Profile updated successfully.', true);
    }

    if ($action === 'change_password' || $action === 'password') {
        $old = (string)($_POST['current_password'] ?? $_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($new) < 8 || $new !== $confirm) goBack('New passwords must match and contain at least 8 characters.');
        $stmt = $oracle_conn->prepare(
            "SELECT USER_ID, PASSWORD_HASH FROM SYSTEM_USERS
             WHERE USER_ID=:user_id AND REFERENCE_ID=:passenger_id AND ROLE='PASSENGER'"
        );
        $stmt->execute([':user_id'=>(int)$_SESSION['user_id'], ':passenger_id'=>$passengerId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new RuntimeException('Account not found.');
        $user = array_change_key_case($user, CASE_LOWER);
        if (!password_verify($old, (string)$user['password_hash'])) goBack('Current password is incorrect.');
        $stmt = $oracle_conn->prepare('UPDATE SYSTEM_USERS SET PASSWORD_HASH=:hash WHERE USER_ID=:user_id');
        $stmt->execute([':hash'=>password_hash($new, PASSWORD_DEFAULT), ':user_id'=>(int)$user['user_id']]);
        goBack('Password changed successfully.', true);
    }

    goBack('Unknown action.');
} catch (Throwable $e) {
    if ($oracle_conn->inTransaction()) $oracle_conn->rollBack();
    error_log('Passenger action: ' . $e->getMessage());
    if ($e instanceof RuntimeException && !$e instanceof PDOException) goBack($e->getMessage());
    goBack('Database operation failed. Check server logs.');
}
