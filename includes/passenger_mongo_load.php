<?php
require_once __DIR__ . '/../includes/passenger_mongo.php';
$myReviews = []; $travelAnnouncements = []; $reviewableTrips = [];
try {
    $myReviews = smMongoFind('passenger_reviews', ['passenger_id' => $passengerId], ['sort' => ['created_at' => -1], 'limit' => 20]);
    $travelAnnouncements = smMongoFind('travel_announcements', ['audience' => 'PASSENGER'], ['sort' => ['published_date' => -1], 'limit' => 15]);
} catch (Throwable $e) {
    error_log('Passenger MongoDB read: ' . $e->getMessage());
    $errors[] = 'Reviews and announcements are temporarily unavailable.';
}
$reviewedTripIds = [];
foreach ($myReviews as $review) $reviewedTripIds[(int)($review['trip_id'] ?? 0)] = true;
foreach ($bookings as $booking) {
    $tripId = (int)$booking['trip_id'];
    if (strtolower((string)$booking['status']) === 'completed' && !isset($reviewedTripIds[$tripId])) {
        $reviewableTrips[$tripId] = $booking;
    }
}
