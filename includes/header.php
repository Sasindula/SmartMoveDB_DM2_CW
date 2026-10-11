<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$isPassenger = ($_SESSION['role'] ?? '') === 'PASSENGER';
$isDriver = ($_SESSION['role'] ?? '') === 'DRIVER';
$isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
function navEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SmartMove | <?= $isPassenger ? 'Passenger Dashboard' : ($isDriver ? 'Driver Dashboard' : 'Transport Dashboard') ?></title><link rel="stylesheet" href="/SmartMoveDB/assets/css/style.css"></head>
<body><header class="header"><div class="container navbar">
<a class="brand" href="<?= $isPassenger ? '/SmartMoveDB/views/passenger_dashboard.php' : ($isDriver ? '/SmartMoveDB/views/driver_dashboard.php' : '/SmartMoveDB/index.php') ?>"><span class="brand-icon">SM</span><span>Smart<span class="teal">Move</span></span></a>
<nav>
<?php if ($isPassenger): ?>
<a href="/SmartMoveDB/views/passenger_dashboard.php">Dashboard</a><a href="/SmartMoveDB/views/passenger_dashboard.php#find-trips">Find Trips</a><a href="/SmartMoveDB/views/passenger_dashboard.php#my-bookings">My Bookings</a><a href="/SmartMoveDB/views/passenger_dashboard.php#my-payments">Payments</a><a href="/SmartMoveDB/views/passenger_dashboard.php#my-profile">My Profile</a>
<?php elseif ($isDriver): ?>
<a href="/SmartMoveDB/views/driver_dashboard.php">Dashboard</a><a href="/SmartMoveDB/views/driver_dashboard.php#assigned-trips">My Trips</a><a href="/SmartMoveDB/views/driver_dashboard.php#maintenance">Maintenance</a><a href="/SmartMoveDB/views/driver_dashboard.php#vehicle-documents">Documents</a><a href="/SmartMoveDB/views/driver_dashboard.php#driver-announcements">Announcements</a>
<?php else: ?>
<?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?><a href="/SmartMoveDB/views/admin_driver_approvals.php">Driver Approvals</a><?php endif; ?><a href="/SmartMoveDB/index.php">Dashboard</a><a href="/SmartMoveDB/index.php#search">Find a Ride</a><a href="/SmartMoveDB/index.php#portals">Our Services</a><a href="/SmartMoveDB/index.php#announcements">Announcements</a>
<?php endif; ?>
</nav>
<?php if ($isPassenger): ?><div class="passenger-header-user"><span><?= navEscape($_SESSION['username'] ?? 'Passenger') ?></span><a href="/SmartMoveDB/logout.php" class="button">Logout</a></div><?php elseif ($isDriver): ?><div class="passenger-header-user"><span><?= navEscape($_SESSION['username'] ?? 'Driver') ?></span><a href="/SmartMoveDB/logout.php" class="button">Logout</a></div><?php elseif ($isAdmin): ?><div class="passenger-header-user"><span><?= navEscape($_SESSION['username'] ?? 'Admin') ?></span><a href="/SmartMoveDB/logout.php" class="button">Logout</a></div><?php else: ?><span class="header-badge">Transport Solutions</span><?php endif; ?>
</div></header>
