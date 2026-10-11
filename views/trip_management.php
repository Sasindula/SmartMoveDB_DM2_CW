<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
if (($_SESSION['role'] ?? '')!=='ADMIN' || empty($_SESSION['user_id'])) { http_response_code(403); exit('Admin access only.'); }
function th($v): string { return htmlspecialchars((string)($v ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function tripTime(string $v): string {
 $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v);
 if (!$d || $d->format('Y-m-d\TH:i')!==$v) throw new RuntimeException('Enter a valid departure and arrival date/time.');
 return $d->format('Y-m-d H:i');
}
$error=''; $routes=[]; $drivers=[]; $vehicles=[]; $trips=[];
$flash=$_SESSION['trip_flash'] ?? ''; unset($_SESSION['trip_flash']);
$form=['trip_id'=>0,'route_id'=>'','driver_id'=>'','vehicle_id'=>'','departure_time'=>'','arrival_time'=>'','fare_amount'=>''];
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  foreach($form as $k=>$v) { if (!is_string($_POST[$k] ?? null)) throw new RuntimeException('Invalid form fields.'); $form[$k]=$_POST[$k]; }
  if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) throw new RuntimeException('Reload the page and try again.');
  $id=filter_var($form['trip_id'],FILTER_VALIDATE_INT);
  if ($id===false || $id<0) throw new RuntimeException('Invalid trip.');
  $ids=[];
  foreach(['route_id','driver_id','vehicle_id'] as $k) { $ids[$k]=filter_var($form[$k],FILTER_VALIDATE_INT); if (!$ids[$k] || $ids[$k]<1) throw new RuntimeException('Select a route, driver and vehicle.'); }
  $departure=tripTime($form['departure_time']); $arrival=tripTime($form['arrival_time']);
  if ($arrival<=$departure) throw new RuntimeException('Arrival must be after departure.');
  if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/',$form['fare_amount'])) throw new RuntimeException('Fare must be 0–999999.99 with at most two decimal places.');
  $oracle_conn->beginTransaction();
  if ($id>0) {
   $s=$oracle_conn->prepare('SELECT TRIP_ID FROM TRIP WHERE TRIP_ID=:id FOR UPDATE'); $s->execute([':id'=>$id]);
   if (!$s->fetch()) throw new RuntimeException('Trip no longer exists.');
   // Booking workflows must also lock this trip before inserting a booking.
   $s=$oracle_conn->prepare('SELECT COUNT(*) FROM BOOKING WHERE TRIP_ID=:id'); $s->execute([':id'=>$id]);
   if ((int)$s->fetchColumn()) throw new RuntimeException('This trip has booking history and cannot be edited. Create a separate trip instead.');
  }
  // Parent locks serialize assignments for the same resource in this workflow.
  $s=$oracle_conn->prepare('SELECT STATUS FROM DRIVER WHERE DRIVER_ID=:id FOR UPDATE'); $s->execute([':id'=>$ids['driver_id']]); $driverState=$s->fetchColumn();
  if (!in_array($driverState,['Active','On Trip'],true)) throw new RuntimeException('Driver must be Active or On Trip.');
  $s=$oracle_conn->prepare('SELECT STATUS FROM VEHICLE WHERE VEHICLE_ID=:id FOR UPDATE'); $s->execute([':id'=>$ids['vehicle_id']]);
  if ($s->fetchColumn()!=='Active') throw new RuntimeException('Vehicle must be Active.');
  $s=$oracle_conn->prepare('SELECT ROUTE_ID FROM ROUTE WHERE ROUTE_ID=:id'); $s->execute([':id'=>$ids['route_id']]);
  if (!$s->fetch()) throw new RuntimeException('Route no longer exists.');
  $s=$oracle_conn->prepare("SELECT COUNT(*) FROM TRIP WHERE TRIP_ID<>:id AND (DRIVER_ID=:driver OR VEHICLE_ID=:vehicle) AND DEPARTURE_TIME<TO_TIMESTAMP(:arrival,'YYYY-MM-DD HH24:MI') AND ARRIVAL_TIME>TO_TIMESTAMP(:departure,'YYYY-MM-DD HH24:MI')");
  $s->execute([':id'=>$id,':driver'=>$ids['driver_id'],':vehicle'=>$ids['vehicle_id'],':arrival'=>$arrival,':departure'=>$departure]);
  if ((int)$s->fetchColumn()) throw new RuntimeException('Driver or vehicle already has a trip during this time. Select another resource or time.');
  $params=[':r'=>$ids['route_id'],':d'=>$ids['driver_id'],':v'=>$ids['vehicle_id'],':dep'=>$departure,':arr'=>$arrival,':fare'=>$form['fare_amount']];
  if ($id>0) {
   $s=$oracle_conn->prepare("UPDATE TRIP SET ROUTE_ID=:r,DRIVER_ID=:d,VEHICLE_ID=:v,DEPARTURE_TIME=TO_TIMESTAMP(:dep,'YYYY-MM-DD HH24:MI'),ARRIVAL_TIME=TO_TIMESTAMP(:arr,'YYYY-MM-DD HH24:MI'),FARE_AMOUNT=:fare WHERE TRIP_ID=:id"); $params[':id']=$id;
  } else $s=$oracle_conn->prepare("INSERT INTO TRIP (ROUTE_ID,DRIVER_ID,VEHICLE_ID,DEPARTURE_TIME,ARRIVAL_TIME,FARE_AMOUNT) VALUES (:r,:d,:v,TO_TIMESTAMP(:dep,'YYYY-MM-DD HH24:MI'),TO_TIMESTAMP(:arr,'YYYY-MM-DD HH24:MI'),:fare)");
  $s->execute($params); $oracle_conn->commit(); $_SESSION['trip_flash']='Trip saved successfully.'; header('Location: trip_management.php'); exit;
 } catch(Throwable $e) {
  if ($oracle_conn->inTransaction()) $oracle_conn->rollBack(); error_log('Trip save: '.$e->getMessage());
  $error=$e instanceof PDOException ? 'Unable to save trip. Check database constraints and server logs.' : $e->getMessage();
 }
}
try {
 $routes=$oracle_conn->query('SELECT ROUTE_ID,SOURCE,DESTINATION FROM ROUTE ORDER BY SOURCE,DESTINATION')->fetchAll();
 $drivers=$oracle_conn->query('SELECT DRIVER_ID,DRIVER_NAME,LICENSE_NUMBER,STATUS FROM DRIVER ORDER BY DRIVER_NAME')->fetchAll();
 $vehicles=$oracle_conn->query('SELECT VEHICLE_ID,PLATE_NUMBER,VEHICLE_TYPE,CAPACITY,STATUS FROM VEHICLE ORDER BY PLATE_NUMBER')->fetchAll();
 $trips=$oracle_conn->query("SELECT t.TRIP_ID,t.ROUTE_ID,t.DRIVER_ID,t.VEHICLE_ID,t.FARE_AMOUNT,r.SOURCE,r.DESTINATION,d.DRIVER_NAME,v.PLATE_NUMBER,v.CAPACITY,TO_CHAR(t.DEPARTURE_TIME,'YYYY-MM-DD\"T\"HH24:MI') DEPARTURE_TIME,TO_CHAR(t.ARRIVAL_TIME,'YYYY-MM-DD\"T\"HH24:MI') ARRIVAL_TIME,(SELECT COUNT(*) FROM BOOKING b WHERE b.TRIP_ID=t.TRIP_ID) BOOKINGS,CASE WHEN t.ARRIVAL_TIME<=LOCALTIMESTAMP THEN 'Past' WHEN t.DEPARTURE_TIME<=LOCALTIMESTAMP THEN 'In progress' ELSE 'Upcoming' END SCHEDULE_STATE FROM TRIP t JOIN ROUTE r ON r.ROUTE_ID=t.ROUTE_ID JOIN DRIVER d ON d.DRIVER_ID=t.DRIVER_ID JOIN VEHICLE v ON v.VEHICLE_ID=t.VEHICLE_ID ORDER BY t.DEPARTURE_TIME DESC FETCH FIRST 200 ROWS ONLY")->fetchAll();
 if ($_SERVER['REQUEST_METHOD']!=='POST' && isset($_GET['edit'])) {
  $selected=filter_var($_GET['edit'],FILTER_VALIDATE_INT); $found=false;
  foreach($trips as $t) if ((int)$t['trip_id']===$selected) { foreach(array_keys($form) as $k) $form[$k]=$t[$k]; $found=true; }
  if (!$found) $error='Trip not found in the latest 200 trips.';
 }
} catch(Throwable $e) { error_log('Trip list: '.$e->getMessage()); $error='Unable to load trips. Check Oracle tables and BOOKING.'; }
include __DIR__.'/../includes/header.php';
?>
<main class="container"><section class="panel"><span class="eyebrow">ADMIN CONTROL</span><h1>Trip Management</h1><p><a href="admin_dashboard.php">Admin Dashboard</a> · <a href="driver_management.php">Drivers</a> · <a href="route_management.php">Routes</a></p>
<?php if($error): ?><div class="alert"><?=th($error)?></div><?php endif; ?><?php if($flash): ?><div class="success-alert"><?=th($flash)?></div><?php endif; ?>
<h2><?= (int)$form['trip_id']>0?'Edit Trip #'.th($form['trip_id']):'Add Trip' ?></h2><p class="muted">Times use your Oracle database's local time. A driver and vehicle are assigned to each trip. Arrival must be after departure; overlapping assignments are rejected.</p>
<form method="post" class="auth-form"><input type="hidden" name="csrf_token" value="<?=th($_SESSION['csrf_token'])?>"><input type="hidden" name="trip_id" value="<?=th($form['trip_id'])?>">
<label>Route<select name="route_id" required><option value="">Select route</option><?php foreach($routes as $r): ?><option value="<?= (int)$r['route_id']?>" <?= (int)$form['route_id']===(int)$r['route_id']?'selected':'' ?>><?=th($r['source'])?> → <?=th($r['destination'])?></option><?php endforeach; ?></select></label>
<label>Driver<select name="driver_id" required><option value="">Select driver</option><?php foreach($drivers as $d): ?><option value="<?= (int)$d['driver_id']?>" <?= (int)$form['driver_id']===(int)$d['driver_id']?'selected':'' ?> <?= !in_array($d['status'],['Active','On Trip'],true)?'disabled':'' ?>><?=th($d['driver_name'])?> — <?=th($d['license_number'])?> (<?=th($d['status'])?>)</option><?php endforeach; ?></select></label>
<label>Vehicle<select name="vehicle_id" required><option value="">Select vehicle</option><?php foreach($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id']?>" <?= (int)$form['vehicle_id']===(int)$v['vehicle_id']?'selected':'' ?> <?= $v['status']!=='Active'?'disabled':'' ?>><?=th($v['plate_number'])?> — <?=th($v['vehicle_type'])?>, <?=th($v['capacity'])?> seats (<?=th($v['status'])?>)</option><?php endforeach; ?></select></label>
<label>Departure<input type="datetime-local" name="departure_time" value="<?=th($form['departure_time'])?>" required></label><label>Arrival<input type="datetime-local" name="arrival_time" value="<?=th($form['arrival_time'])?>" required></label><label>Fare (LKR)<input type="number" name="fare_amount" min="0" max="999999.99" step="0.01" value="<?=th($form['fare_amount'])?>" required></label><button class="button">Save Trip</button><a href="trip_management.php">Clear / Add New</a></form></section>
<section class="panel"><h2>Trips — latest 200</h2><div class="table-wrapper"><table><thead><tr><th>Trip</th><th>Route</th><th>Driver</th><th>Vehicle</th><th>Departure</th><th>Arrival</th><th>Fare</th><th>Schedule</th><th>Bookings</th><th>Action</th></tr></thead><tbody><?php foreach($trips as $t): ?><tr><td>#<?=th($t['trip_id'])?></td><td><?=th($t['source'])?> → <?=th($t['destination'])?></td><td><?=th($t['driver_name'])?></td><td><?=th($t['plate_number'])?> (<?=th($t['capacity'])?> seats)</td><td><?=th(str_replace('T',' ',$t['departure_time']))?></td><td><?=th(str_replace('T',' ',$t['arrival_time']))?></td><td><?=number_format((float)$t['fare_amount'],2)?></td><td><?=th($t['schedule_state'])?></td><td><?=th($t['bookings'])?></td><td><?php if((int)$t['bookings']===0): ?><a href="?edit=<?= (int)$t['trip_id']?>">Edit</a><?php else: ?><span class="muted">Booking history — locked</span><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$trips && !$error): ?><tr><td colspan="10">No trips found. Add your first trip above.</td></tr><?php endif; ?></tbody></table></div></section></main>
<style>.auth-form select{padding:12px;border:1px solid #ccd8e1;border-radius:8px;width:100%;background:white}</style>
<?php include __DIR__.'/../includes/footer.php'; ?>
