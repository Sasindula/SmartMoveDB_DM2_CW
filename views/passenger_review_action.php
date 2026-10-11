<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (($_SESSION['role'] ?? '') !== 'PASSENGER' || empty($_SESSION['reference_id'])) {
    http_response_code(403); exit('Forbidden');
}
function reviewReturn(string $message, bool $ok = false): void {
    $_SESSION['passenger_flash'] = ['ok' => $ok, 'message' => $message];
    header('Location: /SmartMoveDB/views/passenger_dashboard.php#passenger-reviews'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400); exit('Invalid request');
}
$passengerId = (int)$_SESSION['reference_id'];
$tripId = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT);
$rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
$comment = trim((string)($_POST['comment'] ?? ''));
if (!$tripId || !$rating || $rating < 1 || $rating > 5 || $comment === '' || mb_strlen($comment) > 1000) reviewReturn('Invalid review details.');
try {
    $sql = "SELECT t.TRIP_ID, t.ROUTE_ID, t.VEHICLE_ID, t.DRIVER_ID, t.ARRIVAL_TIME, p.FIRST_NAME, p.LAST_NAME
            FROM BOOKING b JOIN TRIP t ON t.TRIP_ID=b.TRIP_ID
            JOIN PASSENGER p ON p.PASSENGER_ID=b.PASSENGER_ID
            WHERE b.PASSENGER_ID=:pid AND b.TRIP_ID=:tid AND UPPER(b.STATUS)='COMPLETED'";
    $stmt = $oracle_conn->prepare($sql);
    $stmt->execute([':pid'=>$passengerId, ':tid'=>$tripId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$trip) reviewReturn('Only your completed trips can be reviewed.');
    $trip = array_change_key_case($trip);
    if (!$mongo_conn instanceof \MongoDB\Driver\Manager) reviewReturn('MongoDB is unavailable.');
    $query = new \MongoDB\Driver\Query(['passenger_id'=>$passengerId, 'trip_id'=>(int)$tripId], ['limit'=>1]);
    $existing = $mongo_conn->executeQuery($mongo_database.'.passenger_reviews', $query)->toArray();
    if ($existing) reviewReturn('You already reviewed this trip.');
    $review = [
        'review_id'=>'REV'.strtoupper(bin2hex(random_bytes(8))),
        'passenger_id'=>$passengerId,
        'passenger_name'=>trim($trip['first_name'].' '.$trip['last_name']),
        'trip_id'=>(int)$tripId,
        'route_id'=>(int)$trip['route_id'],
        'vehicle_id'=>(int)$trip['vehicle_id'],
        'driver_id'=>(int)$trip['driver_id'],
        'rating'=>(int)$rating,
        'comment'=>$comment,
        'created_at'=>new \MongoDB\BSON\UTCDateTime(),
    ];
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->insert($review);
    $mongo_conn->executeBulkWrite($mongo_database.'.passenger_reviews', $bulk);
    reviewReturn('Your review was submitted.', true);
} catch (Throwable $e) {
    error_log('Passenger review submission: '.$e->getMessage());
    reviewReturn('Could not save review. Please try again.');
}
