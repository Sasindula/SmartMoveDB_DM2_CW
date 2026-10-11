<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/passenger_mongo.php';
require_once __DIR__ . '/../includes/vehicle_document_storage.php';
if (($_SESSION['role'] ?? '') !== 'DRIVER' || empty($_SESSION['user_id']) || empty($_SESSION['reference_id'])) { header('Location: /SmartMoveDB/login.php'); exit; }
$accessCheck=$oracle_conn->prepare('SELECT STATUS FROM DRIVER WHERE DRIVER_ID=:id');
$accessCheck->execute([':id'=>(int)$_SESSION['reference_id']]);
$accessStatus=$accessCheck->fetchColumn();
if ($accessStatus===false || $accessStatus==='Inactive') { http_response_code(403); exit('Driver account unavailable. Contact the administrator.'); }
$driverId=(int)$_SESSION['reference_id'];
$_SESSION['maintenance_token'] ??= bin2hex(random_bytes(32));
$flash=$_SESSION['driver_flash'] ?? null; unset($_SESSION['driver_flash']);
$errors=[]; $driver=null; $trips=[]; $maintenance=[]; $docs=[]; $announcements=[]; $maintenanceTotal=null;
function dh($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
try {
    $s=$oracle_conn->prepare('SELECT DRIVER_ID, DRIVER_NAME, LICENSE_NUMBER, PHONE, STATUS FROM DRIVER WHERE DRIVER_ID=:id');
    $s->execute([':id'=>$driverId]); $driver=$s->fetch(PDO::FETCH_ASSOC);
    if (!$driver) { http_response_code(403); exit('Driver profile not found.'); }
} catch(Throwable $e) { error_log('Driver profile: '.$e->getMessage()); http_response_code(500); exit('Unable to load driver profile.'); }
try {
    // Matches the DRIVER_ID / ROUTE_ID / VEHICLE_ID relations used by Fetch_Driver_Schedule.
    $s=$oracle_conn->prepare("SELECT t.TRIP_ID,t.VEHICLE_ID,r.SOURCE,r.DESTINATION,v.PLATE_NUMBER,v.VEHICLE_TYPE, TO_CHAR(t.DEPARTURE_TIME,'YYYY-MM-DD HH24:MI') DEPARTURE_TIME, TO_CHAR(t.ARRIVAL_TIME,'YYYY-MM-DD HH24:MI') ARRIVAL_TIME, CASE WHEN t.ARRIVAL_TIME <= LOCALTIMESTAMP THEN 1 ELSE 0 END AS ARRIVED, (SELECT COUNT(*) FROM BOOKING b WHERE b.TRIP_ID=t.TRIP_ID AND b.STATUS='Confirmed') CONFIRMED_COUNT FROM TRIP t JOIN ROUTE r ON r.ROUTE_ID=t.ROUTE_ID JOIN VEHICLE v ON v.VEHICLE_ID=t.VEHICLE_ID WHERE t.DRIVER_ID=:id ORDER BY t.DEPARTURE_TIME DESC FETCH FIRST 40 ROWS ONLY");
    $s->execute([':id'=>$driverId]); $trips=$s->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) { error_log('Driver trips: '.$e->getMessage()); $errors[]='Assigned trips unavailable.'; }
$assignedVehicles=[]; $vehicleIds=[];
try {
 $s=$oracle_conn->prepare('SELECT DISTINCT v.VEHICLE_ID,v.PLATE_NUMBER FROM VEHICLE v JOIN TRIP t ON t.VEHICLE_ID=v.VEHICLE_ID WHERE t.DRIVER_ID=:id ORDER BY v.PLATE_NUMBER');
 $s->execute([':id'=>$driverId]); $assignedVehicles=$s->fetchAll(PDO::FETCH_ASSOC);
 $vehicleIds=array_map('intval',array_column($assignedVehicles,'vehicle_id'));
} catch(Throwable $e) { error_log('Assigned vehicles: '.$e->getMessage()); $errors[]='Assigned vehicles unavailable.'; }
$maintenanceTotals=[];
try {
 $s=$oracle_conn->prepare("SELECT m.MAINTENANCE_ID,m.VEHICLE_ID,v.PLATE_NUMBER,TO_CHAR(m.MAINTENANCE_DATE,'YYYY-MM-DD') MAINTENANCE_DATE,m.DESCRIPTION,m.COST FROM MAINTENANCE m JOIN VEHICLE v ON v.VEHICLE_ID=m.VEHICLE_ID WHERE EXISTS (SELECT 1 FROM TRIP t WHERE t.VEHICLE_ID=m.VEHICLE_ID AND t.DRIVER_ID=:id) ORDER BY m.MAINTENANCE_DATE DESC,m.MAINTENANCE_ID DESC FETCH FIRST 100 ROWS ONLY");
 $s->execute([':id'=>$driverId]); $maintenance=$s->fetchAll(PDO::FETCH_ASSOC);
 $s=$oracle_conn->prepare('SELECT v.VEHICLE_ID,v.PLATE_NUMBER,COUNT(m.MAINTENANCE_ID) RECORD_COUNT,NVL(SUM(m.COST),0) TOTAL_COST FROM VEHICLE v LEFT JOIN MAINTENANCE m ON m.VEHICLE_ID=v.VEHICLE_ID WHERE EXISTS (SELECT 1 FROM TRIP t WHERE t.VEHICLE_ID=v.VEHICLE_ID AND t.DRIVER_ID=:id) GROUP BY v.VEHICLE_ID,v.PLATE_NUMBER ORDER BY v.PLATE_NUMBER');
 $s->execute([':id'=>$driverId]); $maintenanceTotals=$s->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) { error_log('Driver maintenance: '.$e->getMessage()); $errors[]='Maintenance information unavailable.'; }
try {
    if ($mongo_conn instanceof MongoDB\Driver\Manager) {
        if ($vehicleIds) $docs=smMongoFind('vehicle_documents', ['vehicle_id'=>['$in'=>$vehicleIds]], ['sort'=>['uploaded_at'=>-1], 'limit'=>30]);
        $announcements=smMongoFind('travel_announcements', ['audience'=>['$in'=>['DRIVER','ALL']]], ['sort'=>['published_date'=>-1], 'limit'=>15]);
    } else $errors[]='MongoDB unavailable. Oracle features are still accessible.';
} catch(Throwable $e) { error_log('Driver MongoDB: '.$e->getMessage()); $errors[]='MongoDB documents or announcements unavailable.'; }
include __DIR__.'/../includes/header.php';
?>
<main class="container passenger-page driver-page">
<section class="passenger-welcome"><div><span class="eyebrow">SMARTMOVE DRIVER PORTAL</span><h1>Welcome, <?= dh($driver['driver_name']) ?>!</h1><p class="muted">Assigned schedules, vehicle maintenance and driver announcements.</p></div><a class="button" href="#assigned-trips">My Trips →</a></section>
<?php if($flash): ?><div class="<?= $flash['ok']?'success-alert':'alert' ?>" role="status"><?= dh($flash['message']) ?></div><?php endif; ?>
<?php foreach($errors as $err): ?><div class="alert"><?= dh($err) ?></div><?php endforeach; ?>
<section class="stats"><article class="stat-card"><div><strong><?= count($trips) ?></strong><span>Assigned Trips (latest 40)</span></div></article><article class="stat-card"><div><strong><?= count($vehicleIds) ?></strong><span>Assigned Vehicles</span></div></article><article class="stat-card"><div><strong><?= dh($driver['status']) ?></strong><span>Duty Status</span></div></article></section>
<section class="panel" id="driver-profile"><div class="section-heading"><div><span class="eyebrow">ORACLE DATABASE</span><h2>Driver Profile & Duty Status</h2></div></div><div class="profile-grid"><div><small>Driver ID</small><strong>#<?= $driverId ?></strong></div><div><small>License</small><strong><?= dh($driver['license_number']) ?></strong></div><div><small>Phone</small><strong><?= dh($driver['phone']) ?></strong></div></div><form method="post" action="/SmartMoveDB/views/driver_actions.php" class="driver-inline-form"><input type="hidden" name="csrf_token" value="<?= dh($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="duty"><label>Update Duty Status <select name="status" required><?php foreach(['Active','On Trip','On Leave'] as $state): ?><option value="<?= dh($state) ?>" <?= $driver['status']===$state?'selected':'' ?>><?= dh($state) ?></option><?php endforeach; ?></select></label><button class="button" type="submit">Save Status</button></form><p class="muted">Leave requires ongoing and future trips to be reassigned first.</p></section>
<section class="panel" id="assigned-trips"><div class="section-heading"><div><span class="eyebrow">ORACLE DATABASE</span><h2>Assigned Trips</h2></div></div><div class="table-wrapper"><table><thead><tr><th>Trip</th><th>Route</th><th>Vehicle</th><th>Departure</th><th>Arrival</th><th>Action</th></tr></thead><tbody><?php foreach($trips as $trip): ?><tr><td>#<?= (int)$trip['trip_id'] ?></td><td><?= dh($trip['source']) ?> → <?= dh($trip['destination']) ?></td><td><?= dh($trip['vehicle_type']) ?><small><?= dh($trip['plate_number']) ?></small></td><td><?= dh($trip['departure_time']) ?></td><td><?= dh($trip['arrival_time']) ?></td><td><?php if((int)$trip['arrived']===1 && (int)$trip['confirmed_count']>0): ?><form method="post" action="/SmartMoveDB/views/driver_actions.php" onsubmit="return confirm('Mark confirmed bookings for this completed trip as Completed?')"><input type="hidden" name="csrf_token" value="<?= dh($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="trip_id" value="<?= (int)$trip['trip_id'] ?>"><button type="submit" class="button">Complete Trip (<?= (int)$trip['confirmed_count'] ?>)</button></form><?php else: ?><span class="muted"><?= (int)$trip['arrived']===0?'Not yet arrived':'No confirmed bookings' ?></span><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$trips): ?><tr><td colspan="6">No trips assigned.</td></tr><?php endif; ?></tbody></table></div><p class="muted">Only confirmed bookings on your own trips, after the scheduled arrival time, can be completed. The Passenger Reviews section then becomes available.</p></section>
<section class="panel" id="maintenance"><div class="section-heading"><div><span class="eyebrow">VEHICLE MAINTENANCE</span><h2>Maintenance Records</h2></div></div>
<?php foreach(($_SESSION['maintenance_pending'] ?? []) as $pendingId=>$pending): if ((int)$pending['driver_id']!==$driverId) continue; ?>
<form method="post" action="/SmartMoveDB/views/driver_maintenance_action.php" class="driver-inline-form"><input type="hidden" name="maintenance_token" value="<?=dh($_SESSION['maintenance_token'])?>"><input type="hidden" name="action" value="retry"><input type="hidden" name="maintenance_id" value="<?= (int)$pendingId?>"><span>Oracle record #<?= (int)$pendingId?> needs MongoDB sync.</span><button class="button">Retry MongoDB Sync</button></form>
<?php endforeach; ?>
<?php if($assignedVehicles): ?>
<h3>Add Completed Maintenance</h3><form method="post" action="/SmartMoveDB/views/driver_maintenance_action.php" class="auth-form"><input type="hidden" name="maintenance_token" value="<?=dh($_SESSION['maintenance_token'])?>"><input type="hidden" name="action" value="add">
<label>Assigned Vehicle<select name="vehicle_id" required><?php foreach($assignedVehicles as $vehicle): ?><option value="<?= (int)$vehicle['vehicle_id']?>"><?=dh($vehicle['plate_number'])?></option><?php endforeach; ?></select></label>
<label>Maintenance Date<input type="date" name="maintenance_date" required></label><label>Description<input name="description" maxlength="255" required></label><label>Cost (LKR)<input type="number" name="cost" min="0" max="99999999.99" step="0.01" required></label><label>Service Center (optional)<input name="service_center" maxlength="100"></label><button class="button">Save Maintenance</button></form>
<?php else: ?><p class="muted">Ask the administrator to assign a vehicle through a trip before adding maintenance.</p><?php endif; ?>
<h3>Total Cost by Vehicle</h3><div class="table-wrapper"><table><thead><tr><th>Vehicle</th><th>All Records</th><th>Total Cost (LKR)</th></tr></thead><tbody><?php foreach($maintenanceTotals as $total): ?><tr><td><?=dh($total['plate_number'])?></td><td><?= (int)$total['record_count']?></td><td><?=number_format((float)$total['total_cost'],2)?></td></tr><?php endforeach; ?><?php if(!$maintenanceTotals): ?><tr><td colspan="3">No assigned vehicle totals available.</td></tr><?php endif; ?></tbody></table></div>
<h3>Maintenance History — Latest 100</h3><div class="table-wrapper"><table><thead><tr><th>Record</th><th>Vehicle</th><th>Date</th><th>Description</th><th>Cost (LKR)</th></tr></thead><tbody><?php foreach($maintenance as $m): ?><tr><td>#<?= (int)$m['maintenance_id']?></td><td><?=dh($m['plate_number'])?></td><td><?=dh($m['maintenance_date'])?></td><td><?=dh($m['description'])?></td><td><?=number_format((float)$m['cost'],2)?></td></tr><?php endforeach; ?><?php if(!$maintenance): ?><tr><td colspan="5">No maintenance records for assigned vehicles.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel" id="vehicle-documents"><div class="section-heading"><div><span class="eyebrow">MONGODB</span><h2>Vehicle Documents</h2></div></div>
<?php if($assignedVehicles && $mongo_conn instanceof MongoDB\Driver\Manager): ?>
<form method="post" action="/SmartMoveDB/views/driver_document_upload.php" enctype="multipart/form-data" class="auth-form">
<input type="hidden" name="csrf_token" value="<?=dh($_SESSION['csrf_token'])?>">
<label>Assigned Vehicle<select name="vehicle_id" required><?php foreach($assignedVehicles as $vehicle): ?><option value="<?= (int)$vehicle['vehicle_id']?>"><?=dh($vehicle['plate_number'])?></option><?php endforeach; ?></select></label>
<label>Document Type<select name="document_type" required><?php foreach(['Insurance Policy','Revenue License','Emission Test','Service Report','Other'] as $type): ?><option><?=dh($type)?></option><?php endforeach; ?></select></label>
<label>Expiry Date (optional)<input type="date" name="expiry_date"></label>
<label>Notes (optional)<input name="notes" maxlength="500"></label>
<label>PDF Document (up to 5 MB)<input type="file" name="document" accept="application/pdf,.pdf" required></label>
<button class="button">Upload Document</button></form>
<?php elseif(!$assignedVehicles): ?><p class="muted">Ask the administrator to assign a vehicle through a trip before uploading documents.</p><?php else: ?><p class="muted">Uploads are unavailable while MongoDB is offline.</p><?php endif; ?>
<div class="sm-mongo-list"><?php foreach($docs as $doc): ?><article class="sm-mongo-item"><strong><?=dh($doc['document_type'] ?? 'Document')?></strong><p>Vehicle: <?=dh($doc['vehicle_plate'] ?? $doc['vehicle_id'] ?? '')?></p><p>Document ID: <?=dh($doc['doc_id'] ?? '')?></p><small>Expiry: <?=dh(smMongoDate($doc['expiry_date'] ?? null) ?: 'Not specified')?></small>
<?php
$documentExists=false;
if (isset($doc['storage_filename']) && preg_match('/^DOC[a-f0-9]{32}\.pdf$/',(string)$doc['storage_filename'])) {
 $documentExists=is_file(smDocumentDirectory().'/'.$doc['storage_filename']);
} else {
 $url=(string)($doc['file_path'] ?? '');
 if (preg_match('~^/SmartMoveDB/uploads/docs/([A-Za-z0-9_.-]+\.pdf)$~i',$url,$match)) {
  $base=realpath(__DIR__.'/../uploads/docs'); $candidate=realpath(__DIR__.'/../uploads/docs/'.$match[1]);
  $documentExists=$base && $candidate && dirname($candidate)===$base && is_file($candidate);
 }
}
?>
<?php if($documentExists): ?><p><a href="/SmartMoveDB/views/driver_document_download.php?doc_id=<?=dh(rawurlencode((string)($doc['doc_id'] ?? '')))?>" target="_blank" rel="noopener noreferrer">Open Document</a></p><?php else: ?><p class="muted">Document not uploaded</p><?php endif; ?>
</article><?php endforeach; ?><?php if(!$docs): ?><p class="muted">No vehicle documents found for your assigned vehicles.</p><?php endif; ?></div></section>
<section class="panel" id="driver-announcements"><div class="section-heading"><div><span class="eyebrow">MONGODB</span><h2>Driver Announcements</h2></div></div><div class="sm-mongo-list"><?php foreach($announcements as $a): ?><article class="sm-mongo-item"><strong><?= dh($a['title'] ?? '') ?></strong><span class="badge"><?= dh($a['priority'] ?? 'Normal') ?></span><p><?= dh($a['message'] ?? '') ?></p><small><?= dh(smMongoDate($a['published_date'] ?? null)) ?></small></article><?php endforeach; ?><?php if(!$announcements): ?><p class="muted">No driver announcements available.</p><?php endif; ?></div></section>
</main><style>.driver-inline-form{display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:18px 0}.driver-inline-form label{display:grid;gap:7px}.driver-inline-form select{padding:10px;border:1px solid #ccd8e1;border-radius:8px}.driver-page .sm-mongo-list{display:grid;gap:12px;margin-top:14px}.driver-page .sm-mongo-item{border:1px solid #e3eaf0;border-radius:10px;padding:15px;background:white}.driver-page .sm-mongo-item p{margin:8px 0;overflow-wrap:anywhere}.driver-page .sm-mongo-item small{color:#657585}.driver-page .sm-mongo-item .badge{margin-left:8px}</style>
<?php include __DIR__.'/../includes/footer.php'; ?>
