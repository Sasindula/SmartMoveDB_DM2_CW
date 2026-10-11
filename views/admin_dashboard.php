<?php

require_once __DIR__ . '/../config.php';

if (($_SESSION['role'] ?? '') !== 'ADMIN' || empty($_SESSION['user_id'])) {

    http_response_code(403);

    exit('Admin access only.');

}

// Request fresh dashboard data on each page load.

if (!headers_sent()) {

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    header('Pragma: no-cache');

    header('Expires: 0');

}

function admh($value): string {

    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

}

$errors = [];

$kpi = ['total_revenue' => 0, 'confirmed_bookings' => 0, 'active_vehicles' => 0, 'maintenance_cost' => 0];

$payment = [];

$routes = [];

$vehicles = [];

$pending = 0;

$reviewsCount = null;

$announcementsCount = null;

// Keep each report independent: a failing revenue/chart query must not hide maintenance totals.

try {

    $kpi = $oracle_conn->query("SELECT

        (SELECT NVL(SUM(AMOUNT), 0) FROM PAYMENT WHERE UPPER(PAYMENT_STATUS) = 'PAID') total_revenue,

        (SELECT COUNT(*) FROM BOOKING WHERE STATUS = 'Confirmed') confirmed_bookings,

        (SELECT COUNT(*) FROM VEHICLE WHERE STATUS = 'Active') active_vehicles,

        (SELECT NVL(SUM(COST), 0) FROM MAINTENANCE) maintenance_cost

        FROM DUAL")->fetch(PDO::FETCH_ASSOC) ?: $kpi;

} catch (Throwable $e) {

    error_log('Admin KPIs: ' . $e->getMessage());

    $errors[] = 'Some Oracle KPIs could not be loaded.';

}

try {

    $payment = $oracle_conn->query("SELECT PAYMENT_METHOD, SUM(AMOUNT) TOTAL

        FROM PAYMENT WHERE UPPER(PAYMENT_STATUS) = 'PAID'

        GROUP BY PAYMENT_METHOD ORDER BY TOTAL DESC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Admin payment report: ' . $e->getMessage());

    $errors[] = 'Payment report unavailable.';

}

try {

    $routes = $oracle_conn->query("SELECT r.SOURCE || ' → ' || r.DESTINATION ROUTE_NAME,

        NVL(SUM(CASE WHEN UPPER(p.PAYMENT_STATUS) = 'PAID' THEN p.AMOUNT ELSE 0 END), 0) REVENUE

        FROM ROUTE r

        LEFT JOIN TRIP t ON t.ROUTE_ID = r.ROUTE_ID

        LEFT JOIN BOOKING b ON b.TRIP_ID = t.TRIP_ID

        LEFT JOIN PAYMENT p ON p.BOOKING_ID = b.BOOKING_ID

        GROUP BY r.ROUTE_ID, r.SOURCE, r.DESTINATION

        ORDER BY REVENUE DESC FETCH FIRST 8 ROWS ONLY")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Admin routes report: ' . $e->getMessage());

    $errors[] = 'Route revenue report unavailable.';

}

try {

    // This query reads live totals directly from the same Oracle MAINTENANCE table

    // used by the Maintenance Management page.

    $vehicles = $oracle_conn->query("SELECT

        v.VEHICLE_ID, v.PLATE_NUMBER, v.VEHICLE_TYPE, v.STATUS,

        NVL(t.TRIPS, 0) AS TRIPS,

        NVL(m.COST, 0) AS COST,

        NVL(m.MAINTENANCE_COUNT, 0) AS MAINTENANCE_COUNT

        FROM VEHICLE v

        LEFT JOIN (

            SELECT VEHICLE_ID, COUNT(*) AS TRIPS FROM TRIP GROUP BY VEHICLE_ID

        ) t ON t.VEHICLE_ID = v.VEHICLE_ID

        LEFT JOIN (

            SELECT VEHICLE_ID, SUM(COST) AS COST,

                   COUNT(*) AS MAINTENANCE_COUNT

            FROM MAINTENANCE GROUP BY VEHICLE_ID

        ) m ON m.VEHICLE_ID = v.VEHICLE_ID

        ORDER BY v.VEHICLE_ID")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Admin vehicle maintenance report: ' . $e->getMessage());

    $errors[] = 'Vehicle maintenance report unavailable.';

}

// Refresh maintenance KPI independently, even if the combined KPI query failed.

try {

    $kpi['maintenance_cost'] = (float)$oracle_conn->query(

        'SELECT NVL(SUM(COST), 0) FROM MAINTENANCE'

    )->fetchColumn();

} catch (Throwable $e) {

    error_log('Admin maintenance KPI: ' . $e->getMessage());

    $errors[] = 'Maintenance total unavailable.';

}

try {

    $pending = (int)$oracle_conn->query("SELECT COUNT(*) FROM DRIVER_REGISTRATION_REQUESTS WHERE STATUS = 'Pending'")->fetchColumn();

} catch (Throwable $e) {

    error_log('Admin driver approvals: ' . $e->getMessage());

    $errors[] = 'Driver approvals table unavailable. Run registration SQL migration.';

}

$mongo = $mongo_manager ?? $mongo_conn ?? null;

if ($mongo instanceof MongoDB\Driver\Manager) {

    foreach (['passenger_reviews' => 'reviewsCount', 'travel_announcements' => 'announcementsCount'] as $collection => $variable) {

        try {

            $cmd = new MongoDB\Driver\Command(['count' => $collection]);

            $result = $mongo->executeCommand($mongo_database ?? 'SmartMoveDB', $cmd)->toArray();

            $$variable = (int)($result[0]->n ?? 0);

        } catch (Throwable $e) {

            error_log('Admin Mongo stats (' . $collection . '): ' . $e->getMessage());

            $errors[] = 'MongoDB ' . $collection . ' statistics unavailable.';

        }

    }

} else {

    $errors[] = 'MongoDB PHP extension or server unavailable.';

}

include __DIR__ . '/../includes/header.php';

?>

<main class="container passenger-page">

    <section class="passenger-welcome">

        <div>

            <span class="eyebrow">SMARTMOVE ADMIN PORTAL</span>

            <h1>Admin Analytics Dashboard</h1>

            <p class="muted">Live Oracle operations and MongoDB content overview.</p>

        </div>

        <a class="button" href="/SmartMoveDB/views/trip_management.php">Trip Management</a> <a class="button" href="/SmartMoveDB/views/driver_management.php">Driver Management</a> <a class="button" href="/SmartMoveDB/views/admin_driver_approvals.php">Driver Approvals (<?= (int)$pending ?>) →</a>

        <a class="button" href="/SmartMoveDB/views/maintenance_doc.php">Manage Maintenance →</a>

        <a class="button" href="/SmartMoveDB/views/route_management.php">Manage Routes →</a>
        <a class="button" href="/SmartMoveDB/views/vehicle_management.php">Manage Vehicles →</a>

    </section>

    <?php foreach ($errors as $e): ?>

        <div class="alert"><?= admh($e) ?></div>

    <?php endforeach; ?>

    <section class="stats">

        <?php foreach ([

            ['Total Revenue (LKR)', number_format((float)$kpi['total_revenue'], 2)],

            ['Confirmed Bookings', (int)$kpi['confirmed_bookings']],

            ['Active Vehicles', (int)$kpi['active_vehicles']],

            ['Maintenance Cost (LKR)', number_format((float)$kpi['maintenance_cost'], 2)]

        ] as $card): ?>

            <article class="stat-card"><div><strong><?= admh($card[1]) ?></strong><span><?= admh($card[0]) ?></span></div></article>

        <?php endforeach; ?>

    </section>

    <section class="stats">

        <article class="stat-card"><div><strong><?= (int)$pending ?></strong><span>Pending Driver Approvals</span></div></article>

        <article class="stat-card"><div><strong><?= $reviewsCount === null ? '—' : (int)$reviewsCount ?></strong><span>MongoDB Passenger Reviews</span></div></article>

        <article class="stat-card"><div><strong><?= $announcementsCount === null ? '—' : (int)$announcementsCount ?></strong><span>MongoDB Announcements</span></div></article>

    </section>

    <section class="panel">

        <div class="section-heading"><div><span class="eyebrow">ORACLE ANALYTICS</span><h2>Revenue by Payment Channel</h2></div></div>

        <div class="table-wrapper">

            <table>

                <thead><tr><th>Method</th><th>Paid Revenue (LKR)</th></tr></thead>

                <tbody>

                    <?php foreach ($payment as $r): ?>

                        <tr><td><?= admh($r['payment_method']) ?></td><td><?= number_format((float)$r['total'], 2) ?></td></tr>

                    <?php endforeach; ?>

                    <?php if (!$payment): ?><tr><td colspan="2">No paid payments recorded.</td></tr><?php endif; ?>

                </tbody>

            </table>

        </div>

        <canvas id="paymentChart" height="100"></canvas>

    </section>

    <section class="panel">

        <div class="section-heading"><div><span class="eyebrow">ORACLE ANALYTICS</span><h2>Top Performing Routes</h2></div></div>

        <div class="table-wrapper">

            <table>

                <thead><tr><th>Route</th><th>Paid Revenue (LKR)</th></tr></thead>

                <tbody>

                    <?php foreach ($routes as $r): ?>

                        <tr><td><?= admh($r['route_name']) ?></td><td><?= number_format((float)$r['revenue'], 2) ?></td></tr>

                    <?php endforeach; ?>

                    <?php if (!$routes): ?><tr><td colspan="2">No routes found.</td></tr><?php endif; ?>

                </tbody>

            </table>

        </div>

        <canvas id="routeChart" height="110"></canvas>

    </section>

    <section class="panel">

        <div class="section-heading"><div><span class="eyebrow">ORACLE DATABASE</span><h2>Vehicle Performance &amp; Maintenance</h2></div></div>

        <div class="table-wrapper">

            <table>

                <thead><tr><th>Plate</th><th>Type</th><th>Status</th><th>Assigned Trips</th><th>Maintenance Records</th><th>Maintenance Cost</th></tr></thead>

                <tbody>

                    <?php foreach ($vehicles as $v): ?>

                        <tr>

                            <td><?= admh($v['plate_number']) ?></td>

                            <td><?= admh($v['vehicle_type']) ?></td>

                            <td><?= admh($v['status']) ?></td>

                            <td><?= (int)$v['trips'] ?></td>

                            <td><?= (int)$v['maintenance_count'] ?></td>

                            <td>LKR <?= number_format((float)$v['cost'], 2) ?></td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$vehicles): ?><tr><td colspan="6">No vehicles found.</td></tr><?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <section class="panel">
        <div class="section-heading"><div><span class="eyebrow">ORACLE ANALYTICS</span><h2>Vehicle Maintenance Cost Chart</h2></div></div>
        <p class="muted">Live maintenance expenses by vehicle, calculated from Oracle records.</p>
        <div style="position:relative;max-width:980px;height:340px;margin:12px auto;">
            <canvas id="vehicleMaintenanceChart"></canvas>
        </div>
    </section>

    <section class="panel">

        <h2>Route Management</h2>

        <p class="muted">Add, edit, delete and view Oracle travel routes, including source, destination and distance.</p>

        <a class="button" href="/SmartMoveDB/views/route_management.php">Manage Routes →</a>

    </section>

    <section class="panel">
        <h2>Vehicle Management</h2>
        <p class="muted">Add, edit, delete and view Oracle vehicles, including plate number, type, capacity and status.</p>
        <a class="button" href="/SmartMoveDB/views/vehicle_management.php">Manage Vehicles →</a>
    </section>

    <section class="panel">

        <h2>Vehicle Maintenance Management</h2>

        <p class="muted">Add, update and review Oracle vehicle maintenance records.</p>

        <a class="button" href="/SmartMoveDB/views/maintenance_doc.php">Manage Maintenance →</a>

    </section>

    <section class="panel">

        <h2>MongoDB Content Management</h2>

        <p class="muted">Publish passenger/driver announcements and moderate passenger reviews.</p>

        <a class="button" href="/SmartMoveDB/views/admin_mongo.php">Open MongoDB Management →</a>

    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>

if (window.Chart) {

    const p = <?= json_encode($payment, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const r = <?= json_encode($routes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    new Chart(document.getElementById('paymentChart'), {

        type: 'doughnut',

        data: { labels: p.map(x => x.payment_method), datasets: [{ data: p.map(x => Number(x.total)) }] },

        options: { responsive: true, maintainAspectRatio: true }

    });

    const v = <?= json_encode($vehicles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const maintenanceCanvas = document.getElementById('vehicleMaintenanceChart');
    if (maintenanceCanvas && v.length) {
        new Chart(maintenanceCanvas, {
            type: 'bar',
            data: {
                labels: v.map(x => x.plate_number),
                datasets: [{ label: 'Maintenance Cost (LKR)', data: v.map(x => Number(x.cost) || 0), backgroundColor: '#0d9488', borderRadius: 5 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true } },
                scales: { y: { beginAtZero: true, ticks: { callback: value => 'LKR ' + Number(value).toLocaleString() } } }
            }
        });
    }
    new Chart(document.getElementById('routeChart'), {

        type: 'bar',

        data: { labels: r.map(x => x.route_name), datasets: [{ label: 'LKR', data: r.map(x => Number(x.revenue)) }] },

        options: { responsive: true, scales: { y: { beginAtZero: true } } }

    });

}

</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
