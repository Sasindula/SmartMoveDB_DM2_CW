<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function dashboardHtml($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$stats = [];
$trips = [];
$announcements = [];
$databaseError = '';
$announcementError = '';

$origin = is_string($_GET['origin'] ?? '')
    ? trim($_GET['origin'] ?? '')
    : '';

$destination = is_string($_GET['destination'] ?? '')
    ? trim($_GET['destination'] ?? '')
    : '';

try {
    $stats = $oracle_conn->query(
        "SELECT
            (SELECT COUNT(*) FROM VEHICLE
             WHERE STATUS = 'Active') AS ACTIVE_VEHICLES,
            (SELECT COUNT(*) FROM ROUTE) AS TOTAL_ROUTES,
            (SELECT COUNT(*) FROM PASSENGER) AS TOTAL_PASSENGERS,
            (SELECT COUNT(*) FROM TRIP
             WHERE DEPARTURE_TIME > LOCALTIMESTAMP) AS UPCOMING_TRIPS
         FROM DUAL"
    )->fetch(PDO::FETCH_ASSOC);

    $sql = "
        SELECT
            t.TRIP_ID,
            r.SOURCE,
            r.DESTINATION,
            v.VEHICLE_TYPE,
            v.PLATE_NUMBER,
            TO_CHAR(t.DEPARTURE_TIME, 'YYYY-MM-DD HH24:MI')
                AS DEPARTURE_TIME,
            t.FARE_AMOUNT,
            GET_FREE_SEAT_COUNT(t.TRIP_ID) AS FREE_SEATS

        FROM TRIP t
        JOIN ROUTE r ON r.ROUTE_ID = t.ROUTE_ID
        JOIN VEHICLE v ON v.VEHICLE_ID = t.VEHICLE_ID
        JOIN DRIVER d ON d.DRIVER_ID = t.DRIVER_ID

        WHERE t.DEPARTURE_TIME > LOCALTIMESTAMP
          AND v.STATUS = 'Active'
          AND d.STATUS IN ('Active', 'On Trip')
          AND LOWER(r.SOURCE) LIKE LOWER(:origin)
          AND LOWER(r.DESTINATION) LIKE LOWER(:destination)

        ORDER BY t.DEPARTURE_TIME
        FETCH FIRST 20 ROWS ONLY
    ";

    $stmt = $oracle_conn->prepare($sql);
    $stmt->execute([
        ':origin' => '%' . $origin . '%',
        ':destination' => '%' . $destination . '%'
    ]);

    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Main dashboard: ' . $e->getMessage());
    $databaseError = 'Unable to load transport information.';
}

try {
    if (isset($mongo_conn) && $mongo_conn !== null) {
        $query = new MongoDB\Driver\Query(
            ['audience' => 'PASSENGER'],
            [
                'sort' => ['published_date' => -1],
                'limit' => 3
            ]
        );

        $cursor = $mongo_conn->executeQuery(
            'SmartMoveDB.travel_announcements',
            $query
        );

        $announcements = $cursor->toArray();
    } else {
        $announcementError = 'Announcements are temporarily unavailable.';
    }
} catch (Throwable $e) {
    error_log('Dashboard announcements: ' . $e->getMessage());
    $announcementError = 'Announcements are temporarily unavailable.';
}

include __DIR__ . '/includes/header.php';
?>

<main class="container">

    <section class="hero">
        <div>
            <span class="eyebrow">SMARTMOVE TRANSPORT SOLUTIONS</span>

            <h1>Your journey,<br><span>made simple.</span></h1>

            <p>
                Find routes, explore upcoming trips and stay informed
                about your next journey.
            </p>

            <a href="#search" class="button">Find Your Ride →</a>
        </div>

        <div class="hero-card">
            <span class="hero-symbol">↗</span>
            <h3>Move with confidence</h3>
            <p>Buses, vans and transport options in one place.</p>

            <div class="hero-tags">
                <span>Connected routes</span>
                <span>Clear schedules</span>
            </div>
        </div>
    </section>

    <?php if ($databaseError): ?>
        <div class="alert"><?= dashboardHtml($databaseError) ?></div>
    <?php endif; ?>

    <section class="stats">
        <?php
        $cards = [
            ['active_vehicles', 'Active Vehicles', '◈'],
            ['total_routes', 'Transport Routes', '↗'],
            ['total_passengers', 'Registered Passengers', '◎'],
            ['upcoming_trips', 'Upcoming Trips', '◷']
        ];
        ?>

        <?php foreach ($cards as [$key, $label, $symbol]): ?>
            <article class="stat-card">
                <span class="stat-icon"><?= $symbol ?></span>
                <div>
                    <strong>
                        <?= dashboardHtml($stats[$key] ?? '—') ?>
                    </strong>
                    <span><?= dashboardHtml($label) ?></span>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel" id="search">
        <div class="section-heading">
            <div>
                <span class="eyebrow">PLAN YOUR JOURNEY</span>
                <h2>Where would you like to go?</h2>
            </div>
        </div>

        <form method="GET" action="index.php#trips" class="search-form">
            <label>
                Starting point
                <input
                    type="text"
                    name="origin"
                    placeholder="e.g. Galle"
                    maxlength="100"
                    value="<?= dashboardHtml($origin) ?>"
                >
            </label>

            <label>
                Destination
                <input
                    type="text"
                    name="destination"
                    placeholder="e.g. Colombo"
                    maxlength="100"
                    value="<?= dashboardHtml($destination) ?>"
                >
            </label>

            <button class="button" type="submit">Search Trips →</button>
            <a class="reset-link" href="index.php#trips">Reset</a>
        </form>
    </section>

    <section class="panel" id="trips">
        <div class="section-heading">
            <div>
                <span class="eyebrow">AVAILABLE TRANSPORT</span>
                <h2>Upcoming trips</h2>
            </div>
            <span class="badge"><?= count($trips) ?> shown</span>
        </div>

        <?php if ($databaseError): ?>
            <p class="muted">Trip information could not be loaded.</p>

        <?php elseif (!$trips): ?>
            <div class="empty-state">
                <h3>No matching upcoming trips</h3>
                <p>Try another route or check back for new schedules.</p>
            </div>

        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Trip</th>
                            <th>Route</th>
                            <th>Vehicle</th>
                            <th>Departure</th>
                            <th>Seats Left</th>
                            <th>Fare</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($trips as $trip): ?>
                            <tr>
                                <td>
                                    #<?= dashboardHtml($trip['trip_id']) ?>
                                </td>

                                <td class="route-cell">
                                    <?= dashboardHtml($trip['source']) ?>
                                    <span class="teal">→</span>
                                    <?= dashboardHtml($trip['destination']) ?>
                                </td>

                                <td>
                                    <?= dashboardHtml($trip['vehicle_type']) ?>
                                    <small>
                                        <?= dashboardHtml($trip['plate_number']) ?>
                                    </small>
                                </td>

                                <td>
                                    <?= dashboardHtml($trip['departure_time']) ?>
                                </td>

                                <td>
                                    <span class="badge">
                                        <?= dashboardHtml($trip['free_seats']) ?>
                                    </span>
                                </td>

                                <td class="fare">
                                    LKR <?= number_format(
                                        (float)$trip['fare_amount'], 2
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section id="portals">
        <div class="section-heading">
            <div>
                <span class="eyebrow">ONE CONNECTED PLATFORM</span>
                <h2>SmartMove services</h2>
            </div>
        </div>

        <div class="service-grid">
            <article class="service-card">
                <span class="service-icon">◎</span>
                <h3>Passenger Hub</h3>
                <p>Bookings, travel history and passenger profiles.</p>

                <a href="/SmartMoveDB/login.php" class="button">
                    Passenger Login →
                </a>
            </article>

            <article class="service-card">
                <span class="service-icon">↗</span>
                <h3>Driver Portal</h3>
                <p>Assigned routes, vehicles and trip schedules.</p>

                <a href="/SmartMoveDB/login.php" class="button">
                    Driver Login →
                </a>
            </article>

            <article class="service-card">
                <span class="service-icon">▥</span>
                <h3>Admin Analytics</h3>
                <p>Transport operations, payments and business reports.</p>

                <a href="/SmartMoveDB/login.php" class="button">
                    Admin Login →
                </a>
            </article>
        </div>
    </section>

    <section class="panel" id="announcements">
        <div class="section-heading">
            <div>
                <span class="eyebrow">LATEST UPDATES</span>
                <h2>Travel announcements</h2>
            </div>
        </div>

        <?php if ($announcementError): ?>
            <p class="muted">
                <?= dashboardHtml($announcementError) ?>
            </p>

        <?php elseif (!$announcements): ?>
            <p class="muted">No passenger announcements yet.</p>

        <?php else: ?>
            <?php foreach ($announcements as $announcement): ?>
                <article class="announcement">
                    <span class="badge">
                        <?= dashboardHtml(
                            $announcement->priority ?? 'Update'
                        ) ?>
                    </span>

                    <div>
                        <h3>
                            <?= dashboardHtml($announcement->title ?? '') ?>
                        </h3>
                        <p>
                            <?= dashboardHtml($announcement->message ?? '') ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>