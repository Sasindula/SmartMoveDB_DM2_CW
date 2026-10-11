<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
if (($_SESSION['role'] ?? '')!=='ADMIN' || empty($_SESSION['user_id'])) { http_response_code(403); exit('Admin access required.'); }
function routeH($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$error=''; $routes=[]; $assignments=[]; $edit=null;
$success=$_SESSION['route_success'] ?? ''; unset($_SESSION['route_success']);
$form=['route_id'=>0,'source'=>'','destination'=>'','distance_km'=>''];
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) throw new RuntimeException('Reload the page and try again.');
  $action=$_POST['action'] ?? '';
  if (!in_array($action,['add','edit','delete'],true)) throw new RuntimeException('Invalid action.');
  $id=filter_var($_POST['route_id'] ?? '0',FILTER_VALIDATE_INT);
  if ($id===false || $id<0 || ($action!=='add' && $id<1)) throw new RuntimeException('Invalid route ID.');
  $form['route_id']=$id;
  if ($action!=='delete') {
   foreach(['source','destination','distance_km'] as $key) {
    if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('Invalid route fields.');
    $form[$key]=trim($_POST[$key]);
   }
   $source=preg_replace('/\s+/u',' ',$form['source']); $destination=preg_replace('/\s+/u',' ',$form['destination']);
   if (!$source || !$destination || strlen($source)>100 || strlen($destination)>100) throw new RuntimeException('Enter source and destination within 100 bytes each.');
   if (strcasecmp($source,$destination)===0) throw new RuntimeException('Source and destination must be different.');
   if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/',$form['distance_km']) || (float)$form['distance_km']<=0) throw new RuntimeException('Distance must be 0.01–9999.99 km, with at most two decimal places.');
  }
  $oracle_conn->beginTransaction();
  // Serializes duplicate detection and route mutations through this workflow.
  $oracle_conn->exec('LOCK TABLE ROUTE IN EXCLUSIVE MODE');
  if ($action!=='add') {
   $s=$oracle_conn->prepare('SELECT ROUTE_ID FROM ROUTE WHERE ROUTE_ID=:id'); $s->execute([':id'=>$id]);
   if (!$s->fetch()) throw new RuntimeException('Route no longer exists.');
   $s=$oracle_conn->prepare('SELECT COUNT(*) FROM TRIP WHERE ROUTE_ID=:id'); $s->execute([':id'=>$id]);
   if ((int)$s->fetchColumn()) throw new RuntimeException('This route has trips. Preserve its history; create a new route for changed locations or distance.');
  }
  if ($action==='delete') {
   $s=$oracle_conn->prepare('DELETE FROM ROUTE WHERE ROUTE_ID=:id'); $s->execute([':id'=>$id]); $message='Route deleted.';
  } else {
   $s=$oracle_conn->prepare('SELECT COUNT(*) FROM ROUTE WHERE LOWER(TRIM(SOURCE))=LOWER(:src) AND LOWER(TRIM(DESTINATION))=LOWER(:dst) AND ROUTE_ID<>:id');
   $s->execute([':src'=>$source,':dst'=>$destination,':id'=>$action==='add'?0:$id]);
   if ((int)$s->fetchColumn()) throw new RuntimeException('This source-to-destination route already exists. Use its existing entry.');
   $params=[':src'=>$source,':dst'=>$destination,':dist'=>$form['distance_km']];
   if ($action==='add') $s=$oracle_conn->prepare('INSERT INTO ROUTE (SOURCE,DESTINATION,DISTANCE_KM) VALUES (:src,:dst,:dist)');
   else { $s=$oracle_conn->prepare('UPDATE ROUTE SET SOURCE=:src,DESTINATION=:dst,DISTANCE_KM=:dist WHERE ROUTE_ID=:id'); $params[':id']=$id; }
   $s->execute($params); $message='Route saved.';
  }
  $oracle_conn->commit(); $_SESSION['route_success']=$message; header('Location: route_management.php'); exit;
 } catch(Throwable $e) {
  if ($oracle_conn->inTransaction()) $oracle_conn->rollBack(); error_log('Route management: '.$e->getMessage());
  $error=$e instanceof PDOException ? 'Unable to save route. It may be referenced by a trip; check server logs.' : $e->getMessage();
 }
}
try {
 $routes=$oracle_conn->query('SELECT r.ROUTE_ID,r.SOURCE,r.DESTINATION,r.DISTANCE_KM,(SELECT COUNT(*) FROM TRIP t WHERE t.ROUTE_ID=r.ROUTE_ID) TRIP_COUNT FROM ROUTE r ORDER BY r.ROUTE_ID DESC')->fetchAll();
 $assignments=$oracle_conn->query("SELECT t.ROUTE_ID,t.TRIP_ID,r.SOURCE,r.DESTINATION,d.DRIVER_NAME,v.PLATE_NUMBER,TO_CHAR(t.DEPARTURE_TIME,'YYYY-MM-DD HH24:MI') DEPARTURE_TIME,TO_CHAR(t.ARRIVAL_TIME,'YYYY-MM-DD HH24:MI') ARRIVAL_TIME,CASE WHEN t.ARRIVAL_TIME<=LOCALTIMESTAMP THEN 'Past' WHEN t.DEPARTURE_TIME<=LOCALTIMESTAMP THEN 'In progress' ELSE 'Upcoming' END SCHEDULE_STATE FROM TRIP t JOIN ROUTE r ON r.ROUTE_ID=t.ROUTE_ID JOIN DRIVER d ON d.DRIVER_ID=t.DRIVER_ID JOIN VEHICLE v ON v.VEHICLE_ID=t.VEHICLE_ID ORDER BY t.DEPARTURE_TIME DESC FETCH FIRST 200 ROWS ONLY")->fetchAll();
 if ($_SERVER['REQUEST_METHOD']!=='POST' && isset($_GET['edit'])) {
  $selected=filter_var($_GET['edit'],FILTER_VALIDATE_INT);
  foreach($routes as $r) if ((int)$r['route_id']===$selected) $edit=$r;
  if (!$edit) $error='Route not found.';
  elseif ((int)$edit['trip_count']>0) $error='Routes with trips cannot be edited.';
  else foreach(array_keys($form) as $key) $form[$key]=$edit[$key];
 }
} catch(Throwable $e) { error_log('Route listing: '.$e->getMessage()); $error='Unable to load routes or assigned trips. Check Oracle connection and tables.'; }
include __DIR__.'/../includes/header.php';
?>
<main class="container"><section class="panel"><span class="eyebrow">ADMIN CONTROL</span><h1>Route Management</h1><p><a href="admin_dashboard.php">Admin Dashboard</a> · <a href="trip_management.php">Trip Management</a></p><p class="muted">A route defines the journey's source, destination and distance. Assign the driver and vehicle when creating a trip on that route.</p>
<?php if($error): ?><div class="alert"><?=routeH($error)?></div><?php endif; ?><?php if($success): ?><div class="success-alert"><?=routeH($success)?></div><?php endif; ?>
<h2><?= (int)$form['route_id']>0?'Edit Route':'Add Route' ?></h2><form method="post" class="auth-form"><input type="hidden" name="csrf_token" value="<?=routeH($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="<?= (int)$form['route_id']>0?'edit':'add' ?>"><input type="hidden" name="route_id" value="<?=routeH($form['route_id'])?>"><label>Source<input name="source" maxlength="100" value="<?=routeH($form['source'])?>" required></label><label>Destination<input name="destination" maxlength="100" value="<?=routeH($form['destination'])?>" required></label><label>Distance (km)<input type="number" name="distance_km" min="0.01" max="9999.99" step="0.01" value="<?=routeH($form['distance_km'])?>" required></label><button class="button">Save Route</button><a href="route_management.php">Clear / Add New</a></form></section>
<section class="panel"><h2>Routes</h2><div class="table-wrapper"><table><thead><tr><th>ID</th><th>Source</th><th>Destination</th><th>Distance (km)</th><th>Trips</th><th>Actions</th></tr></thead><tbody><?php foreach($routes as $r): ?><tr><td><?=routeH($r['route_id'])?></td><td><?=routeH($r['source'])?></td><td><?=routeH($r['destination'])?></td><td><?=routeH($r['distance_km'])?></td><td><?=routeH($r['trip_count'])?></td><td><?php if((int)$r['trip_count']===0): ?><a href="?edit=<?= (int)$r['route_id']?>">Edit</a><form method="post" style="display:inline" onsubmit="return confirm('Delete this unused route?')"><input type="hidden" name="csrf_token" value="<?=routeH($_SESSION['csrf_token'])?>"><input type="hidden" name="route_id" value="<?= (int)$r['route_id']?>"><input type="hidden" name="action" value="delete"><button class="button secondary-button">Delete</button></form><?php else: ?><span class="muted">Trip history — protected</span><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$routes && !$error): ?><tr><td colspan="6">No routes found.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><h2>Drivers & Vehicles by Trip</h2><p class="muted">Latest 200 trips across all routes. A route can use different drivers and vehicles on different trips.</p><div class="table-wrapper"><table><thead><tr><th>Route</th><th>Trip</th><th>Driver</th><th>Vehicle Plate</th><th>Departure</th><th>Arrival</th><th>Schedule</th></tr></thead><tbody><?php foreach($assignments as $t): ?><tr><td><?=routeH($t['source'])?> → <?=routeH($t['destination'])?></td><td>#<?=routeH($t['trip_id'])?></td><td><?=routeH($t['driver_name'])?></td><td><?=routeH($t['plate_number'])?></td><td><?=routeH($t['departure_time'])?></td><td><?=routeH($t['arrival_time'])?></td><td><?=routeH($t['schedule_state'])?></td></tr><?php endforeach; ?><?php if(!$assignments && !$error): ?><tr><td colspan="7">No trips assigned. Open Trip Management to assign a driver and vehicle to a route.</td></tr><?php endif; ?></tbody></table></div></section></main>
<?php include __DIR__.'/../includes/footer.php'; ?>
