<?php
require_once __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (strtoupper((string)($_SESSION['role'] ?? '')) !== 'ADMIN' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Admin access only.');
}

function vehicleH($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function vehicleRedirect(string $key, string $text): void {
    $_SESSION[$key] = $text;
    header('Location: vehicle_management.php', true, 303);
    exit;
}

$_SESSION['vehicle_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['vehicle_csrf'];
$message = $_SESSION['vehicle_message'] ?? '';
$error = $_SESSION['vehicle_error'] ?? '';
unset($_SESSION['vehicle_message'], $_SESSION['vehicle_error']);
$types = ['Bus', 'Van', 'Other'];
$statuses = ['Active', 'Maintenance', 'Inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid security token. Refresh and try again.');
    }
    try {
        $action = (string)($_POST['action'] ?? '');
        $id = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);

        if ($action === 'delete') {
            if ($id === false || $id === null || $id < 1) {
                throw new RuntimeException('Invalid vehicle ID.');
            }
            $stmt = $oracle_conn->prepare('DELETE FROM VEHICLE WHERE VEHICLE_ID = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Vehicle not found.');
            }
            vehicleRedirect('vehicle_message', 'Vehicle deleted successfully.');
        }

        if (!in_array($action, ['add', 'edit'], true)) {
            throw new RuntimeException('Invalid action.');
        }
        if ($action === 'edit' && ($id === false || $id === null || $id < 1)) {
            throw new RuntimeException('Invalid vehicle ID.');
        }

        $plate = strtoupper(trim((string)($_POST['plate_number'] ?? '')));
        $type = trim((string)($_POST['vehicle_type'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'Active'));
        $rawCapacity = trim((string)($_POST['capacity'] ?? ''));
        $capacity = filter_var($rawCapacity, FILTER_VALIDATE_INT);

        if ($plate === '' || strlen($plate) > 30) {
            throw new RuntimeException('Enter a plate number (maximum 30 characters).');
        }
        if (!in_array($type, $types, true)) {
            throw new RuntimeException('Vehicle type must be Bus, Van, or Other.');
        }
        if ($capacity === false || $capacity < 1 || $capacity > 1000) {
            throw new RuntimeException('Capacity must be a whole number between 1 and 1000.');
        }
        if (!in_array($status, $statuses, true)) {
            throw new RuntimeException('Invalid vehicle status.');
        }

        // Use separate SQL statements to avoid driver-specific NULL bind issues.
        if ($action === 'add') {
            $duplicate = $oracle_conn->prepare('SELECT COUNT(*) FROM VEHICLE WHERE UPPER(PLATE_NUMBER) = :plate');
            $duplicate->execute(['plate' => $plate]);
        } else {
            $duplicate = $oracle_conn->prepare('SELECT COUNT(*) FROM VEHICLE WHERE UPPER(PLATE_NUMBER) = :plate AND VEHICLE_ID <> :id');
            $duplicate->execute(['plate' => $plate, 'id' => $id]);
        }
        if ((int)$duplicate->fetchColumn() > 0) {
            throw new RuntimeException('A vehicle with this plate number already exists.');
        }

        if ($action === 'add') {
            $stmt = $oracle_conn->prepare('INSERT INTO VEHICLE (PLATE_NUMBER, VEHICLE_TYPE, CAPACITY, STATUS) VALUES (:plate, :type, :capacity, :status)');
            $stmt->execute(['plate' => $plate, 'type' => $type, 'capacity' => $capacity, 'status' => $status]);
            vehicleRedirect('vehicle_message', 'Vehicle added successfully.');
        }

        $stmt = $oracle_conn->prepare('UPDATE VEHICLE SET PLATE_NUMBER = :plate, VEHICLE_TYPE = :type, CAPACITY = :capacity, STATUS = :status WHERE VEHICLE_ID = :id');
        $stmt->execute(['plate' => $plate, 'type' => $type, 'capacity' => $capacity, 'status' => $status, 'id' => $id]);
        $exists = $oracle_conn->prepare('SELECT COUNT(*) FROM VEHICLE WHERE VEHICLE_ID = :id');
        $exists->execute(['id' => $id]);
        if ((int)$exists->fetchColumn() === 0) {
            throw new RuntimeException('Vehicle not found.');
        }
        vehicleRedirect('vehicle_message', 'Vehicle updated successfully.');
    } catch (Throwable $e) {
        error_log('Vehicle management: ' . $e->getMessage());
        if ($e instanceof PDOException) {
            $detail = $e->getMessage();
            if (strpos($detail, 'ORA-02292') !== false) {
                $friendly = 'Cannot delete this vehicle: it is linked to trips or maintenance records.';
            } elseif (strpos($detail, 'ORA-00001') !== false) {
                $friendly = 'Duplicate value: this plate number may already exist.';
            } elseif (strpos($detail, 'ORA-02290') !== false) {
                $friendly = 'Oracle rejected a value due to a CHECK constraint. Verify vehicle type, status and capacity.';
            } elseif (strpos($detail, 'ORA-12899') !== false) {
                $friendly = 'A field value is longer than the Oracle column allows.';
            } elseif (strpos($detail, 'ORA-01400') !== false) {
                $friendly = 'A required database field is missing.';
            } else {
                $friendly = 'Database operation failed. Check the PHP error log for the ORA error code.';
            }
            vehicleRedirect('vehicle_error', $friendly);
        }
        vehicleRedirect('vehicle_error', $e->getMessage());
    }
}

$vehicles = [];
try {
    $vehicles = $oracle_conn->query('SELECT VEHICLE_ID, PLATE_NUMBER, VEHICLE_TYPE, CAPACITY, STATUS FROM VEHICLE ORDER BY VEHICLE_ID DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Vehicle listing: ' . $e->getMessage());
    $error = 'Unable to load vehicles.';
}
include __DIR__ . '/../includes/header.php';
?>
<main class="container passenger-page" style="padding-top:24px;padding-bottom:48px">
    <section class="panel">
        <div class="section-heading"><div><span class="eyebrow">SMARTMOVE ADMIN PORTAL</span><h1>Vehicle Management</h1><p class="muted">Register and manage Oracle vehicles.</p></div></div>
        <p><a class="button" href="admin_dashboard.php">← Back to Admin Dashboard</a></p>
        <?php if ($message): ?><div role="status" style="padding:12px;margin:12px 0;background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:8px"><?= vehicleH($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div role="alert" style="padding:12px;margin:12px 0;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px"><?= vehicleH($error) ?></div><?php endif; ?>
        <h2>Add New Vehicle</h2>
        <form method="post" action="vehicle_management.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end">
            <input type="hidden" name="csrf" value="<?= vehicleH($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <label>Plate Number<input name="plate_number" placeholder="CAB-1234" maxlength="30" required style="width:100%"></label>
            <label>Vehicle Type<select name="vehicle_type" required style="width:100%"><option value="">Select type</option><?php foreach ($types as $t): ?><option value="<?= vehicleH($t) ?>"><?= vehicleH($t) ?></option><?php endforeach; ?></select></label>
            <label>Capacity<input name="capacity" type="number" min="1" max="1000" step="1" required style="width:100%"></label>
            <label>Status<select name="status" style="width:100%"><?php foreach ($statuses as $s): ?><option value="<?= vehicleH($s) ?>"><?= vehicleH($s) ?></option><?php endforeach; ?></select></label>
            <button class="button" type="submit">Add Vehicle</button>
        </form>
    </section>
    <section class="panel">
        <h2>Registered Vehicles</h2>
        <div class="table-wrapper"><table><thead><tr><th>ID</th><th>Plate Number</th><th>Type</th><th>Capacity</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($vehicles as $v): $vid = (int)$v['vehicle_id']; ?>
            <tr><td><?= $vid ?></td><td><?= vehicleH($v['plate_number']) ?></td><td><?= vehicleH($v['vehicle_type']) ?></td><td><?= (int)$v['capacity'] ?></td><td><?= vehicleH($v['status']) ?></td><td><a href="#edit-<?= $vid ?>">Edit</a> &nbsp;
            <form method="post" action="vehicle_management.php" style="display:inline" onsubmit="return confirm('Delete this vehicle?');"><input type="hidden" name="csrf" value="<?= vehicleH($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="vehicle_id" value="<?= $vid ?>"><button type="submit">Delete</button></form></td></tr>
        <?php endforeach; ?>
        <?php if (!$vehicles): ?><tr><td colspan="6">No vehicles found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <?php foreach ($vehicles as $v): $vid = (int)$v['vehicle_id']; ?>
    <section class="panel" id="edit-<?= $vid ?>">
        <h3>Edit Vehicle #<?= $vid ?> — <?= vehicleH($v['plate_number']) ?></h3>
        <form method="post" action="vehicle_management.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end">
            <input type="hidden" name="csrf" value="<?= vehicleH($csrf) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="vehicle_id" value="<?= $vid ?>">
            <label>Plate Number<input name="plate_number" value="<?= vehicleH($v['plate_number']) ?>" maxlength="30" required style="width:100%"></label>
            <label>Vehicle Type<select name="vehicle_type" required style="width:100%"><?php foreach ($types as $t): ?><option value="<?= vehicleH($t) ?>" <?= $v['vehicle_type'] === $t ? 'selected' : '' ?>><?= vehicleH($t) ?></option><?php endforeach; ?></select></label>
            <label>Capacity<input name="capacity" type="number" min="1" max="1000" step="1" value="<?= (int)$v['capacity'] ?>" required style="width:100%"></label>
            <label>Status<select name="status" style="width:100%"><?php foreach ($statuses as $s): ?><option value="<?= vehicleH($s) ?>" <?= $v['status'] === $s ? 'selected' : '' ?>><?= vehicleH($s) ?></option><?php endforeach; ?></select></label>
            <button class="button" type="submit">Save Changes</button>
        </form>
    </section>
    <?php endforeach; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
