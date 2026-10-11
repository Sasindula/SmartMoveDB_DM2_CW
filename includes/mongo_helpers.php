<?php
// Uses the existing MongoDB\Driver\Manager from config.php.
function smMongoFind(string $collection, array $filter, array $options = []): array {
    global $mongo_conn, $mongo_database;
    if (!$mongo_conn instanceof \MongoDB\Driver\Manager) return [];
    $query = new \MongoDB\Driver\Query($filter, $options);
    $cursor = $mongo_conn->executeQuery($mongo_database . '.' . $collection, $query);
    return array_map(static fn($doc) => (array)$doc, $cursor->toArray());
}
function smMongoDate($value): string {
    if ($value instanceof \MongoDB\BSON\UTCDateTime) return $value->toDateTime()->setTimezone(new \DateTimeZone('Asia/Colombo'))->format('Y-m-d H:i');
    return '';
}
