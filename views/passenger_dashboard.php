<?php

require_once __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (($_SESSION['role'] ?? '') !== 'PASSENGER' || empty($_SESSION['user_id']) || empty($_SESSION['reference_id'])) {

    header('Location: /SmartMoveDB/login.php'); exit;

}

$passengerId = (int)$_SESSION['reference_id'];

$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['passenger_flash'] ?? null;

if (!$flash && isset($_SESSION['success'])) $flash = ['ok'=>true, 'message'=>$_SESSION['success']];

if (!$flash && isset($_SESSION['error'])) $flash = ['ok'=>false, 'message'=>$_SESSION['error']];

unset($_SESSION['passenger_flash'], $_SESSION['success'], $_SESSION['error']);

function ph($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function lowerRows(array $rows): array { return array_map('array_change_key_case', $rows); }

$errors = []; $passenger = null; $bookings = []; $payments = []; $trips = [];

$origin = trim((string)($_GET['origin'] ?? ''));

$destination = trim((string)($_GET['destination'] ?? ''));

try {

    $s = $oracle_conn->prepare('SELECT PASSENGER_ID, FIRST_NAME, LAST_NAME, EMAIL, PHONE, REGISTRATION_DATE FROM PASSENGER WHERE PASSENGER_ID = :pid');

    $s->execute([':pid' => $passengerId]);

    $p = $s->fetch(PDO::FETCH_ASSOC);

    if (!$p) { http_response_code(403); exit('Passenger profile not found.'); }

    $passenger = array_change_key_case($p);

} catch (Throwable $e) { error_log('Passenger profile: '.$e->getMessage()); http_response_code(500); exit('Unable to load passenger profile.'); }

try {

    $s = $oracle_conn->prepare("SELECT b.BOOKING_ID, b.TRIP_ID, b.SEAT_NUMBER, TO_CHAR(b.BOOKING_DATE, 'YYYY-MM-DD') AS BOOKING_DATE, b.STATUS, TO_CHAR(t.DEPARTURE_TIME, 'YYYY-MM-DD HH24:MI:SS') AS DEPARTURE_TIME, TO_CHAR(t.ARRIVAL_TIME, 'YYYY-MM-DD HH24:MI:SS') AS ARRIVAL_TIME, t.FARE_AMOUNT, r.SOURCE, r.DESTINATION, CASE WHEN t.DEPARTURE_TIME>LOCALTIMESTAMP THEN 1 ELSE 0 END CANCELLABLE, (SELECT COUNT(*) FROM PAYMENT pay WHERE pay.BOOKING_ID=b.BOOKING_ID) PAYMENT_COUNT FROM BOOKING b JOIN TRIP t ON t.TRIP_ID = b.TRIP_ID JOIN ROUTE r ON r.ROUTE_ID = t.ROUTE_ID WHERE b.PASSENGER_ID = :pid ORDER BY b.BOOKING_DATE DESC, b.BOOKING_ID DESC");

    $s->execute([':pid' => $passengerId]); $bookings = lowerRows($s->fetchAll(PDO::FETCH_ASSOC));

} catch (Throwable $e) { error_log('Passenger bookings: '.$e->getMessage()); $errors[] = 'Bookings could not be loaded.'; }

try {

    $s = $oracle_conn->prepare("SELECT p.PAYMENT_ID, p.BOOKING_ID, p.AMOUNT, p.PAYMENT_METHOD, p.PAYMENT_STATUS, TO_CHAR(p.PAYMENT_DATE, 'YYYY-MM-DD HH24:MI:SS') AS PAYMENT_DATE FROM PAYMENT p JOIN BOOKING b ON b.BOOKING_ID = p.BOOKING_ID WHERE b.PASSENGER_ID = :pid ORDER BY p.PAYMENT_DATE DESC, p.PAYMENT_ID DESC");

    $s->execute([':pid' => $passengerId]); $payments = lowerRows($s->fetchAll(PDO::FETCH_ASSOC));

} catch (Throwable $e) { error_log('Passenger payments: '.$e->getMessage()); $errors[] = 'Payments could not be loaded.'; }

// The supplied vehicle schema uses CAPACITY. Seat codes use four seats per row.
$capacityColumn='CAPACITY';
function seatCode(int $index): string {
 $row=intdiv($index,4)+1; $letters='';
 while ($row>0) { $row--; $letters=chr(65+$row%26).$letters; $row=intdiv($row,26); }
 return $letters.(1+$index%4);
}
$seatOptions = [];

try {

    $sql = "SELECT t.TRIP_ID, r.SOURCE, r.DESTINATION, TO_CHAR(t.DEPARTURE_TIME, 'YYYY-MM-DD HH24:MI:SS') AS DEPARTURE_TIME, TO_CHAR(t.ARRIVAL_TIME, 'YYYY-MM-DD HH24:MI:SS') AS ARRIVAL_TIME, t.FARE_AMOUNT, v.VEHICLE_TYPE, v.PLATE_NUMBER FROM TRIP t JOIN ROUTE r ON r.ROUTE_ID = t.ROUTE_ID JOIN VEHICLE v ON v.VEHICLE_ID = t.VEHICLE_ID JOIN DRIVER d ON d.DRIVER_ID=t.DRIVER_ID WHERE t.DEPARTURE_TIME > LOCALTIMESTAMP AND v.STATUS = 'Active' AND d.STATUS IN ('Active','On Trip') AND LOWER(r.SOURCE) LIKE LOWER(:origin) AND LOWER(r.DESTINATION) LIKE LOWER(:destination) ORDER BY t.DEPARTURE_TIME FETCH FIRST 20 ROWS ONLY";

    if ($capacityColumn !== null) {

        $sql = str_replace('v.PLATE_NUMBER FROM TRIP', 'v.PLATE_NUMBER, v.' . $capacityColumn . ' AS SEAT_CAPACITY FROM TRIP', $sql);

    }

    $s = $oracle_conn->prepare($sql); $s->execute([':origin'=>'%'.$origin.'%', ':destination'=>'%'.$destination.'%']);

    $trips = lowerRows($s->fetchAll(PDO::FETCH_ASSOC));

    $reservedQuery = $oracle_conn->prepare("SELECT UPPER(TRIM(SEAT_NUMBER)) AS SEAT_NUMBER FROM BOOKING WHERE TRIP_ID=:tid AND UPPER(TRIM(STATUS)) IN ('PENDING','CONFIRMED','COMPLETED')");

    foreach ($trips as $trip) {

        $tid = (int)$trip['trip_id'];

        $capacity = (int)($trip['seat_capacity'] ?? 0);

        if ($capacityColumn === null || $capacity < 1 || $capacity > 1000) { $seatOptions[$tid] = null; continue; }

        $reservedQuery->execute([':tid'=>$tid]);

        $occupied = array_flip(array_map('strtoupper', $reservedQuery->fetchAll(PDO::FETCH_COLUMN)));

        $validCodes=[];
        for ($i=0;$i<$capacity;$i++) $validCodes[seatCode($i)]=true;
        if (array_diff_key($occupied,$validCodes)) { $seatOptions[$tid]=null; continue; }
        $seatOptions[$tid] = [];

        for ($i=0; $i<$capacity; $i++) {

            $code = seatCode($i);

            if (!isset($occupied[$code])) $seatOptions[$tid][] = $code;

        }

    }

} catch (Throwable $e) { error_log('Passenger trip search: '.$e->getMessage()); $errors[] = 'Trip search is temporarily unavailable.'; }



$totalBookings = count($bookings); $upcoming = 0; $completed = 0;

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo'));

foreach ($bookings as $b) {

    $status = strtolower((string)$b['status']);

    if ($status === 'completed') $completed++;

    if (!in_array($status, ['cancelled','canceled','completed'], true) && !empty($b['departure_time'])) {

        try { if (DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $b['departure_time'], new DateTimeZone('Asia/Colombo')) > $now) $upcoming++; } catch (Throwable $e) {}

    }

}

$totalSpent = array_sum(array_map(static fn($p) => strtoupper((string)($p['payment_status'] ?? '')) === 'PAID' ? (float)$p['amount'] : 0, $payments));

require __DIR__ . '/../includes/passenger_mongo_load.php';
include __DIR__ . '/../includes/header.php';

?>

<main class="container passenger-page">

  <section class="passenger-welcome">

    <div><span class="eyebrow">SMARTMOVE PASSENGER PORTAL</span>

      <h1>Welcome, <?= ph($passenger['first_name']) ?>!</h1>

      <p class="muted">Find journeys and manage your own bookings and payments.</p>

    </div><a class="button" href="#find-trips">Find a Trip →</a>

  </section>

  <?php if ($flash): ?><div id="booking-message" class="<?= !empty($flash['ok']) ? 'success-alert' : 'alert' ?>" role="status"><?= ph($flash['message']) ?></div><?php endif; ?>

  <?php foreach ($errors as $message): ?><div class="alert"><?= ph($message) ?></div><?php endforeach; ?>

  <section class="stats" aria-label="My travel statistics">

    <?php foreach ([['◎','Total Bookings',$totalBookings],['◷','Upcoming Trips',$upcoming],['✓','Completed Trips',$completed],['Rs','Total Payments (LKR)',number_format($totalSpent,2)]] as $stat): ?>

      <article class="stat-card"><span class="stat-icon"><?= ph($stat[0]) ?></span><div><strong><?= ph($stat[2]) ?></strong><span><?= ph($stat[1]) ?></span></div></article>

    <?php endforeach; ?>

  </section>

  <section class="panel" id="find-trips">

    <div class="section-heading"><div><span class="eyebrow">PLAN YOUR JOURNEY</span><h2>Find Trips</h2></div></div>

    <form class="search-form" method="get" action="/SmartMoveDB/views/passenger_dashboard.php#find-trips">

      <label>Starting point<input name="origin" maxlength="100" placeholder="e.g. Galle" value="<?= ph($origin) ?>"></label>

      <label>Destination<input name="destination" maxlength="100" placeholder="e.g. Colombo" value="<?= ph($destination) ?>"></label>

      <button class="button" type="submit">Search Trips →</button><a class="reset-link" href="/SmartMoveDB/views/passenger_dashboard.php#find-trips">Reset</a>

    </form>

    <div class="table-wrapper"><table><thead><tr><th>Trip</th><th>Route</th><th>Vehicle</th><th>Departure</th><th>Fare</th><th>Book Seat</th></tr></thead><tbody>

    <?php foreach ($trips as $t): ?><tr><td>#<?= ph($t['trip_id']) ?></td><td><?= ph($t['source']) ?> → <?= ph($t['destination']) ?></td><td><?= ph($t['vehicle_type']) ?><small><?= ph($t['plate_number']) ?></small></td><td><?= ph($t['departure_time']) ?></td><td>LKR <?= number_format((float)$t['fare_amount'],2) ?></td><td>

      <?php $available = $seatOptions[(int)$t['trip_id']] ?? null; ?>

      <?php if ($available === null): ?>

        <span class="muted">Seat configuration needs administrator review</span>

      <?php elseif (!$available): ?>

        <span class="muted">Fully booked</span>

      <?php else: ?>

        <details class="seat-picker"><summary>Choose Seat (<?= count($available) ?> available)</summary>

          <form class="seat-book-form" method="post" action="/SmartMoveDB/views/passenger_actions.php">

            <input type="hidden" name="csrf_token" value="<?= ph($csrf) ?>">

            <input type="hidden" name="action" value="book">

            <input type="hidden" name="trip_id" value="<?= (int)$t['trip_id'] ?>">

            <div class="seat-front">Driver / Front</div>

            <div class="seat-map" role="group" aria-label="Choose an available seat">

              <?php for ($i=0; $i<(int)$t['seat_capacity']; $i++):

                $seat = seatCode($i); $free = in_array($seat, $available, true); ?>

                <label class="seat-cell <?= $free ? '' : 'occupied' ?>">

                  <input type="radio" name="seat_number" value="<?= ph($seat) ?>" <?= $free ? 'required' : 'disabled' ?>>

                  <span><?= ph($seat) ?></span>

                </label>

              <?php endfor; ?>

            </div>

            <p class="muted seat-hint">White: available · Grey: booked · Teal: selected</p>

            <button class="button" type="submit">Confirm Booking</button>

          </form>

        </details>

      <?php endif; ?>

    </td></tr><?php endforeach; ?>

    <?php if (!$trips): ?><tr><td colspan="6">No matching upcoming trips found.</td></tr><?php endif; ?>

    </tbody></table></div>

    <p class="muted passenger-note">Select an available seat on the seat map. Seats are rechecked on submission. Seat labels use four per row: A1-A4, B1-B4 and so on. New bookings are Pending; no payment is charged. Pending and confirmed seats remain reserved until cancelled. Bookings with payment records require support for cancellation.</p>

  </section>

  <section class="panel" id="my-bookings"><div class="section-heading"><div><span class="eyebrow">MY JOURNEYS</span><h2>My Bookings</h2></div><span class="badge"><?= $totalBookings ?> bookings</span></div>

    <div class="table-wrapper"><table><thead><tr><th>Booking</th><th>Route</th><th>Seat</th><th>Departure</th><th>Fare</th><th>Payment</th><th>Status</th><th>Action</th></tr></thead><tbody>

    <?php foreach ($bookings as $b): ?><tr><td>#<?= ph($b['booking_id']) ?></td><td><?= ph($b['source']) ?> → <?= ph($b['destination']) ?></td><td><?= ph($b['seat_number']) ?></td><td><?= ph($b['departure_time']) ?></td><td>LKR <?= number_format((float)$b['fare_amount'],2) ?></td><td><a href="/SmartMoveDB/views/payment.php?booking_id=<?= (int)$b['booking_id'] ?>">Payment</a> <span aria-hidden="true">|</span> <details class="booking-detail-view"><summary>View Booking</summary><div class="booking-detail-content"><strong>Booking #<?= ph($b['booking_id']) ?></strong><p><b>Route:</b> <?= ph($b['source']) ?> → <?= ph($b['destination']) ?></p><p><b>Seat:</b> <?= ph($b['seat_number']) ?></p><p><b>Departure:</b> <?= ph($b['departure_time']) ?></p><p><b>Arrival:</b> <?= ph($b['arrival_time']) ?></p><p><b>Fare:</b> LKR <?= number_format((float)$b['fare_amount'], 2) ?></p><p><b>Booking Status:</b> <?= ph($b['status']) ?></p><?php $bookingPayments = array_values(array_filter($payments, static fn($p) => (int)$p['booking_id'] === (int)$b['booking_id'])); ?><?php if ($bookingPayments): ?><?php foreach ($bookingPayments as $bp): ?><p><b>Payment:</b> <?= ph($bp['payment_method']) ?> — <?= ph($bp['payment_status']) ?><?php if (strtoupper((string)$bp['payment_status']) === 'DEMO PAID'): ?> (Demo only; no real payment)<?php endif; ?></p><?php endforeach; ?><?php else: ?><p><b>Payment:</b> Not requested</p><?php endif; ?></div></details></td><td><span class="badge"><?= ph($b['status']) ?></span></td><td><?php if (in_array(strtolower(trim((string)$b['status'])), ['pending','confirmed'], true) && (int)$b['cancellable']===1 && (int)$b['payment_count']===0): ?><form method="post" action="/SmartMoveDB/views/passenger_actions.php" onsubmit="return confirm('Cancel this booking?');"><input type="hidden" name="csrf_token" value="<?= ph($csrf) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>"><button type="submit" class="button secondary-button">Cancel</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>

    <?php if (!$bookings): ?><tr><td colspan="8">You have no bookings yet.</td></tr><?php endif; ?>

    </tbody></table></div>

  </section>

  <section class="panel" id="my-payments"><div class="section-heading"><div><span class="eyebrow">PAYMENT HISTORY</span><h2>My Payments</h2></div></div>

    <div class="table-wrapper"><table><thead><tr><th>Payment</th><th>Booking</th><th>Method</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead><tbody>

    <?php foreach ($payments as $p): ?><tr><td>#<?= ph($p['payment_id']) ?></td><td>#<?= ph($p['booking_id']) ?></td><td><?= ph($p['payment_method']) ?></td><td><?= ph($p['payment_date']) ?></td><td>LKR <?= number_format((float)$p['amount'],2) ?></td><td><?= ph($p['payment_status']) ?></td></tr><?php endforeach; ?>

    <?php if (!$payments): ?><tr><td colspan="6">No payments recorded yet.</td></tr><?php endif; ?>

    </tbody></table></div>

  </section>

  <?php include __DIR__ . '/../includes/passenger_mongo_sections.php'; ?>
  <section class="panel" id="my-profile"><div class="section-heading"><div><span class="eyebrow">ACCOUNT DETAILS</span><h2>My Profile</h2></div></div>

    <div class="profile-grid"><div><small>Full name</small><strong><?= ph($passenger['first_name'].' '.$passenger['last_name']) ?></strong></div><div><small>Username</small><strong><?= ph($_SESSION['username'] ?? '') ?></strong></div><div><small>Email</small><strong><?= ph($passenger['email']) ?></strong></div><div><small>Phone</small><strong><?= ph($passenger['phone']) ?></strong></div></div>

    <h3>Edit Profile</h3>

    <form class="passenger-edit-form" method="post" action="/SmartMoveDB/views/passenger_actions.php">

      <input type="hidden" name="csrf_token" value="<?= ph($csrf) ?>"><input type="hidden" name="action" value="profile">

      <label>First Name<input name="first_name" maxlength="50" required value="<?= ph($passenger['first_name']) ?>"></label>

      <label>Last Name<input name="last_name" maxlength="50" required value="<?= ph($passenger['last_name']) ?>"></label>

      <label>Email<input name="email" type="email" maxlength="100" required value="<?= ph($passenger['email']) ?>"></label>

      <label>Phone<input name="phone" type="tel" maxlength="15" required value="<?= ph($passenger['phone']) ?>"></label>

      <button class="button" type="submit">Save Profile</button>

    </form>

    <h3>Change Password</h3>

    <form class="passenger-edit-form" method="post" action="/SmartMoveDB/views/passenger_actions.php">

      <input type="hidden" name="csrf_token" value="<?= ph($csrf) ?>"><input type="hidden" name="action" value="password">

      <label>Current Password<input name="current_password" type="password" autocomplete="current-password" required></label>

      <label>New Password<input name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>

      <label>Confirm New Password<input name="confirm_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>

      <button class="button" type="submit">Change Password</button>

    </form>

  </section>

<style>

/* Booking details: no PDF or Composer required */

.booking-detail-view { display: inline-block; vertical-align: middle; margin-left: 6px; position: relative; }

.booking-detail-view summary { color: #087f78; cursor: pointer; font-weight: 600; white-space: nowrap; }

.booking-detail-content { margin-top: 10px; padding: 14px 16px; border: 1px solid #e3eaf0; border-radius: 10px; background: #fff; min-width: 245px; max-width: 340px; white-space: normal; box-shadow: 0 6px 18px rgba(19,44,67,.08); }

.booking-detail-content p { margin: 7px 0; font-size: 13px; }

</style>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
