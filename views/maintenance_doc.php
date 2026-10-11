<?php

require_once __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Adapt this guard if your login stores the role under a different session key.

$role = $_SESSION['role'] ?? ($_SESSION['user']['role'] ?? $_SESSION['user_role'] ?? '');

if (strtoupper((string)$role) !== 'ADMIN') {

    http_response_code(403);

    exit('Admin access required. Please log in as an administrator.');

}

if (!isset($_SESSION['maintenance_csrf'])) {

    $_SESSION['maintenance_csrf'] = bin2hex(random_bytes(32));

}

$csrf = $_SESSION['maintenance_csrf'];

$message = $_SESSION['maintenance_flash'] ?? '';

$error = $_SESSION['maintenance_error'] ?? '';

unset($_SESSION['maintenance_flash'], $_SESSION['maintenance_error']);

function h($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }

function mongoScalar($value): string {

    if ($value === null) return '';

    if (is_scalar($value)) return (string)$value;

    if ($value instanceof MongoDB\BSON\UTCDateTime) return $value->toDateTime()->format('Y-m-d H:i:s');

    if ($value instanceof MongoDB\BSON\ObjectId) return (string)$value;

    if (is_array($value) || $value instanceof Traversable) {

        $parts = [];

        foreach ($value as $v) $parts[] = mongoScalar($v);

        return implode(', ', array_filter($parts, static fn($v) => $v !== ''));

    }

    return '';

}

function mongoField($doc, string $key): string {

    if (is_array($doc)) return mongoScalar($doc[$key] ?? null);

    if (is_object($doc)) return mongoScalar($doc->{$key} ?? null);

    return '';

}

function syncMongo($manager, string $db, int $id, int $vehicleId, string $date, string $description, float $cost, string $serviceCenter): void {

    $bulk = new MongoDB\Driver\BulkWrite();

    $bulk->update(['maintenance_id' => $id], ['$set' => [

        'maintenance_id' => $id,

        'vehicle_id' => $vehicleId,

        'maintenance_date' => $date,

        'description' => $description,

        'cost' => $cost,

        'service_center' => $serviceCenter,

        'updated_at' => new MongoDB\BSON\UTCDateTime()

    ]], ['multi' => false, 'upsert' => true]);

    $manager->executeBulkWrite($db . '.vehicle_maintenances', $bulk);

}

function removeMongo($manager, string $db, int $id): void {

    $bulk = new MongoDB\Driver\BulkWrite();

    $bulk->delete(['maintenance_id' => $id], ['limit' => 0]);

    $manager->executeBulkWrite($db . '.vehicle_maintenances', $bulk);

}

function flashRedirect(string $key, string $value): void {

    $_SESSION[$key] = $value;

    header('Location: maintenance_doc.php');

    exit;

}

$mongoManager = $mongo_manager ?? $mongo_conn ?? null;
$mongoReady = $mongoManager instanceof MongoDB\Driver\Manager;

$dbName = $mongo_database ?? 'SmartMoveDB';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {

        http_response_code(400);

        exit('Invalid security token. Refresh the page and try again.');

    }

    $action = (string)($_POST['action'] ?? '');

    $id = filter_var($_POST['maintenance_id'] ?? null, FILTER_VALIDATE_INT);

    $mongoWarning = '';

    try {

        if ($action === 'delete') {

            if (!$id || $id < 1) throw new RuntimeException('Invalid maintenance ID.');

            $stmt = $oracle_conn->prepare('DELETE FROM MAINTENANCE WHERE MAINTENANCE_ID = :record_id');

            $stmt->execute(['record_id' => $id]);

            if ($stmt->rowCount() < 1) throw new RuntimeException('Record not found.');

            if ($mongoReady) {

                try { removeMongo($mongoManager, $dbName, (int)$id); }

                catch (Throwable $e) { $mongoWarning = ' Oracle deleted, but MongoDB sync failed: ' . $e->getMessage(); }

            } else $mongoWarning = ' MongoDB connection unavailable.';

            flashRedirect($mongoWarning !== '' ? 'maintenance_error' : 'maintenance_flash', 'Maintenance record deleted.' . $mongoWarning);

        }

        if ($action !== 'add' && $action !== 'edit') throw new RuntimeException('Unknown action.');

        $vehicleId = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);

        $date = trim((string)($_POST['maintenance_date'] ?? ''));

        $description = trim((string)($_POST['description'] ?? ''));

        $serviceCenter = trim((string)($_POST['service_center'] ?? ''));

        $costRaw = trim((string)($_POST['cost'] ?? ''));

        if (!$vehicleId || $vehicleId < 1) throw new RuntimeException('Select a valid vehicle.');

        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (!$dt || $dt->format('Y-m-d') !== $date) throw new RuntimeException('Invalid date.');

        if (!is_numeric($costRaw) || (float)$costRaw < 0) throw new RuntimeException('Cost must be zero or greater.');

        $cost = (float)$costRaw;

        if (strlen($description) > 255 || strlen($serviceCenter) > 250) throw new RuntimeException('Description must be at most 255 bytes and service center at most 250 characters.');

        if ($action === 'add') {

            // Reserve an ID inside an Oracle transaction. This supports a conventional

            // numeric primary key without relying on PDO_OCI RETURNING output binds.

            // For GENERATED ALWAYS identity columns, use the identity-specific path below.

            $identity = 'NO';

            try {

                $identityStmt = $oracle_conn->query("SELECT IDENTITY_COLUMN FROM USER_TAB_COLUMNS WHERE TABLE_NAME = 'MAINTENANCE' AND COLUMN_NAME = 'MAINTENANCE_ID'");

                $identity = strtoupper((string)$identityStmt->fetchColumn());

            } catch (Throwable $ignored) { /* Older Oracle versions may lack IDENTITY_COLUMN */ }

                        if ($identity === 'YES') {

                // MAINTENANCE_ID is GENERATED BY DEFAULT AS IDENTITY.

                // Reserve an ID from Oracle's OWN identity sequence, then insert

                // that explicit ID. This avoids unreliable PDO_OCI output binds.

                $seqStmt = $oracle_conn->query("SELECT SEQUENCE_NAME FROM USER_TAB_IDENTITY_COLS WHERE TABLE_NAME = 'MAINTENANCE' AND COLUMN_NAME = 'MAINTENANCE_ID'");

                $sequence = strtoupper(trim((string)$seqStmt->fetchColumn()));

                if (!preg_match('/^[A-Z][A-Z0-9_$#]*$/', $sequence)) {

                    throw new RuntimeException('Oracle maintenance identity sequence was not found.');

                }

                $oracle_conn->beginTransaction();

                try {

                    $id = (int)$oracle_conn->query('SELECT "' . $sequence . '".NEXTVAL FROM DUAL')->fetchColumn();

                    if ($id < 1) throw new RuntimeException('Could not reserve Oracle maintenance ID.');

                    $stmt = $oracle_conn->prepare("INSERT INTO MAINTENANCE (MAINTENANCE_ID, VEHICLE_ID, MAINTENANCE_DATE, DESCRIPTION, COST) VALUES (:p_id, :p_vehicle, TO_DATE(:p_date, 'YYYY-MM-DD'), :p_desc, :p_cost)");

                    $stmt->execute(['p_id' => $id, 'p_vehicle' => $vehicleId, 'p_date' => $date, 'p_desc' => $description, 'p_cost' => $costRaw]);

                    $oracle_conn->commit();

                } catch (Throwable $e) {

                    if ($oracle_conn->inTransaction()) $oracle_conn->rollBack();

                    throw $e;

                }

} else {

                // Lock before MAX+1 so concurrent admin inserts cannot reserve the same ID.

                // Do not use this approach for high-volume systems; prefer a sequence.

                $oracle_conn->beginTransaction();

                try {

                    $oracle_conn->exec('LOCK TABLE MAINTENANCE IN EXCLUSIVE MODE');

                    $id = (int)$oracle_conn->query('SELECT NVL(MAX(MAINTENANCE_ID), 0) + 1 FROM MAINTENANCE')->fetchColumn();

                    $stmt = $oracle_conn->prepare("INSERT INTO MAINTENANCE (MAINTENANCE_ID, VEHICLE_ID, MAINTENANCE_DATE, DESCRIPTION, COST) VALUES (:p_id, :p_vehicle, TO_DATE(:p_date, 'YYYY-MM-DD'), :p_desc, :p_cost)");

                    $stmt->execute(['p_id' => $id, 'p_vehicle' => $vehicleId, 'p_date' => $date, 'p_desc' => $description, 'p_cost' => $costRaw]);

                    $check = $oracle_conn->prepare('SELECT COUNT(*) FROM MAINTENANCE WHERE MAINTENANCE_ID = :p_id');

                    $check->execute(['p_id' => $id]);

                    if ((int)$check->fetchColumn() !== 1) throw new RuntimeException('Oracle maintenance ID could not be verified.');

                    $oracle_conn->commit();

                } catch (Throwable $e) {

                    if ($oracle_conn->inTransaction()) $oracle_conn->rollBack();

                    throw $e;

                }

            }

        } else {

            if (!$id || $id < 1) throw new RuntimeException('Invalid maintenance ID.');

            $stmt = $oracle_conn->prepare("UPDATE MAINTENANCE SET VEHICLE_ID = :vehicle_id, MAINTENANCE_DATE = TO_DATE(:maintenance_date, 'YYYY-MM-DD'), DESCRIPTION = :description, COST = :cost WHERE MAINTENANCE_ID = :record_id");

            $stmt->execute(['vehicle_id' => $vehicleId, 'maintenance_date' => $date, 'description' => $description, 'cost' => $costRaw, 'record_id' => $id]);

            if ($stmt->rowCount() < 1) {

                $check = $oracle_conn->prepare('SELECT COUNT(*) FROM MAINTENANCE WHERE MAINTENANCE_ID = :record_id');

                $check->execute(['record_id' => $id]);

                if ((int)$check->fetchColumn() === 0) throw new RuntimeException('Record not found.');

            }

        }

        if ($mongoReady) {

            try { syncMongo($mongoManager, $dbName, (int)$id, (int)$vehicleId, $date, $description, $cost, $serviceCenter); }

            catch (Throwable $e) { $mongoWarning = ' Oracle saved, but MongoDB sync failed: ' . $e->getMessage(); }

        } else $mongoWarning = ' MongoDB connection unavailable.';

        flashRedirect($mongoWarning !== '' ? 'maintenance_error' : 'maintenance_flash', 'Maintenance record ' . ($action === 'add' ? 'added' : 'updated') . ' (ID ' . $id . ').' . $mongoWarning);

    } catch (Throwable $e) {

        flashRedirect('maintenance_error', $e->getMessage());

    }

}

$vehicles = [];

$records = [];

$centers = [];

try {

    $vehicles = $oracle_conn->query('SELECT VEHICLE_ID, PLATE_NUMBER, VEHICLE_TYPE FROM VEHICLE ORDER BY PLATE_NUMBER')->fetchAll(PDO::FETCH_ASSOC);

    $records = $oracle_conn->query("SELECT m.MAINTENANCE_ID, m.VEHICLE_ID, v.PLATE_NUMBER, v.VEHICLE_TYPE, TO_CHAR(m.MAINTENANCE_DATE, 'YYYY-MM-DD') AS M_DATE, m.DESCRIPTION, m.COST FROM MAINTENANCE m JOIN VEHICLE v ON v.VEHICLE_ID = m.VEHICLE_ID ORDER BY m.MAINTENANCE_DATE DESC, m.MAINTENANCE_ID DESC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) { $error = 'Oracle query failed: ' . $e->getMessage(); }

if ($mongoReady) {

    try {

        $query = new MongoDB\Driver\Query([], ['projection' => ['maintenance_id' => 1, 'service_center' => 1]]);

        foreach ($mongoManager->executeQuery($dbName . '.vehicle_maintenances', $query) as $doc) {

            $mid = mongoField($doc, 'maintenance_id');

            if (ctype_digit($mid)) $centers[(int)$mid] = mongoField($doc, 'service_center');

        }

    } catch (Throwable $e) { $error .= ($error ? ' ' : '') . 'MongoDB read failed: ' . $e->getMessage(); }

}

require_once __DIR__ . '/../includes/header.php';

?>

<style>
.maintenance-success { background-color: #d1e7dd !important; color: #0f5132 !important; border: 1px solid #badbcc !important; }
.maintenance-error { background-color: #f8d7da !important; color: #842029 !important; border: 1px solid #f5c2c7 !important; }
</style>
<style>
.maintenance-edit-overlay { display:none; position:fixed; inset:0; z-index:1055; background:rgba(0,0,0,.55); overflow-y:auto; padding:3rem 1rem; }
.maintenance-edit-overlay:target { display:block; }
.maintenance-edit-overlay .modal-dialog { margin:0 auto; max-width:520px; }
.maintenance-edit-overlay .modal-content { background:#fff; border-radius:.5rem; box-shadow:0 10px 40px rgba(0,0,0,.25); }
.maintenance-edit-overlay .modal-header, .maintenance-edit-overlay .modal-footer { display:flex; align-items:center; justify-content:space-between; padding:1rem; border-bottom:1px solid #dee2e6; }
.maintenance-edit-overlay .modal-footer { border-top:1px solid #dee2e6; border-bottom:0; justify-content:flex-end; gap:.5rem; }
.maintenance-edit-overlay .modal-body { padding:1rem; }
</style>
<div class="container mt-4 mb-5">

    <h2>Vehicle Maintenance Management</h2>

    <p class="text-muted">Oracle maintenance history with MongoDB service center details.</p>

    <?php if ($message): ?><div class="alert alert-success maintenance-success" role="alert"><?= h($message) ?></div><?php endif; ?>

    <?php if ($error): ?><div class="alert alert-danger maintenance-error" role="alert"><?= h($error) ?></div><?php endif; ?>

    <div class="card mb-4">

        <div class="card-header">Add Maintenance Record</div>

        <div class="card-body">

            <form method="post" action="maintenance_doc.php">

                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                <input type="hidden" name="action" value="add">

                <div class="row g-3">

                    <div class="col-md-4"><label class="form-label">Vehicle</label><select class="form-select" name="vehicle_id" required><option value="">Select vehicle</option><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>"><?= h($v['plate_number']) ?> (<?= h($v['vehicle_type']) ?>)</option><?php endforeach; ?></select></div>

                    <div class="col-md-4"><label class="form-label">Date</label><input class="form-control" type="date" name="maintenance_date" value="<?= date('Y-m-d') ?>" required></div>

                    <div class="col-md-4"><label class="form-label">Cost (LKR)</label><input class="form-control" type="number" min="0" step="0.01" name="cost" required></div>

                    <div class="col-md-6"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>

                    <div class="col-md-6"><label class="form-label">Service Center (MongoDB)</label><input class="form-control" type="text" name="service_center" maxlength="250"></div>

                </div>

                <button class="btn btn-success mt-3" type="submit">Add Maintenance</button>

            </form>

        </div>

    </div>

    <h3 id="maintenance-history">Maintenance History</h3>

    <div class="table-responsive"><table class="table table-striped table-bordered align-middle">

        <thead><tr><th>ID</th><th>Vehicle</th><th>Date</th><th>Description</th><th>Cost (LKR)</th><th>Service Center</th><th>Actions</th></tr></thead>

        <tbody>

        <?php foreach ($records as $r): $rid=(int)$r['maintenance_id']; ?>

            <tr>

                <td><?= $rid ?></td><td><?= h($r['plate_number']) ?> (<?= h($r['vehicle_type']) ?>)</td><td><?= h($r['m_date']) ?></td><td><?= h($r['description']) ?></td><td><?= number_format((float)$r['cost'], 2) ?></td><td><?= h(($centers[$rid] ?? '') !== '' ? $centers[$rid] : '—') ?></td>

                <td>

                    <a href="#edit<?= $rid ?>" class="btn btn-warning btn-sm">Edit</a>

                    <form method="post" action="maintenance_doc.php" class="d-inline" onsubmit="return confirm('Delete maintenance record #<?= $rid ?>?');">

                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="maintenance_id" value="<?= $rid ?>"><button class="btn btn-danger btn-sm" type="submit">Delete</button>

                    </form>

                </td>

            </tr>

        <?php endforeach; ?>

        <?php if (!$records): ?><tr><td colspan="7" class="text-center">No maintenance records found.</td></tr><?php endif; ?>

        </tbody>

    </table></div>

    <?php foreach ($records as $r): $rid=(int)$r['maintenance_id']; ?>

    <div class="maintenance-edit-overlay" id="edit<?= $rid ?>"><div class="modal-dialog"><div class="modal-content">

        <form method="post" action="maintenance_doc.php">

            <div class="modal-header"><h5 class="modal-title">Edit Maintenance #<?= $rid ?></h5><a href="#maintenance-history" class="btn-close" aria-label="Close"></a></div>

            <div class="modal-body">

                <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="maintenance_id" value="<?= $rid ?>">

                <div class="mb-3"><label class="form-label">Vehicle</label><select class="form-select" name="vehicle_id" required><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= (int)$v['vehicle_id'] === (int)$r['vehicle_id'] ? 'selected' : '' ?>><?= h($v['plate_number']) ?> (<?= h($v['vehicle_type']) ?>)</option><?php endforeach; ?></select></div>

                <div class="mb-3"><label class="form-label">Date</label><input class="form-control" type="date" name="maintenance_date" value="<?= h($r['m_date']) ?>" required></div>

                <div class="mb-3"><label class="form-label">Cost (LKR)</label><input class="form-control" type="number" min="0" step="0.01" name="cost" value="<?= h($r['cost']) ?>" required></div>

                <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description"><?= h($r['description']) ?></textarea></div>

                <div class="mb-3"><label class="form-label">Service Center (MongoDB)</label><input class="form-control" name="service_center" value="<?= h($centers[$rid] ?? '') ?>" maxlength="250"></div>

            </div>

            <div class="modal-footer"><a href="#maintenance-history" class="btn btn-secondary">Cancel</a><button class="btn btn-primary" type="submit">Save Changes</button></div>

        </form>

    </div></div></div>

    <?php endforeach; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
