<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
if (($_SESSION['role'] ?? '') !== 'ADMIN' || empty($_SESSION['user_id'])) { http_response_code(403); exit('Admin access only.'); }
function dm($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
$states=['Active','On Trip','On Leave','Inactive'];
$error=''; $drivers=[]; $trips=[]; $edit=null;
$flash=$_SESSION['dm_flash'] ?? ''; unset($_SESSION['dm_flash']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) throw new RuntimeException('Reload the page and try again.');
  $id=filter_var($_POST['driver_id'] ?? '0',FILTER_VALIDATE_INT);
  if ($id===false || $id<0) throw new RuntimeException('Invalid driver ID.');
  $name=trim((string)($_POST['driver_name'] ?? '')); $license=trim((string)($_POST['license_number'] ?? '')); $phone=trim((string)($_POST['phone'] ?? '')); $status=(string)($_POST['status'] ?? '');
  $username=trim((string)($_POST['username'] ?? '')); $password=(string)($_POST['password'] ?? '');
  if ($name==='' || strlen($name)>100 || $license==='' || strlen($license)>50 || !preg_match('/^[+0-9 ()-]{7,15}$/',$phone) || !in_array($status,$states,true)) throw new RuntimeException('Check name, license, phone (7–15 characters) and status.');
  if (($username==='' && $password!=='') || ($username!=='' && (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$username) || strlen($password)<8 || strlen($password)>72))) throw new RuntimeException('For a new login, enter a unique username and an 8–72 character password.');
  $oracle_conn->beginTransaction();
  if ($id>0) {
   $s=$oracle_conn->prepare('SELECT DRIVER_ID FROM DRIVER WHERE DRIVER_ID=:id FOR UPDATE'); $s->execute([':id'=>$id]);
   if (!$s->fetch()) throw new RuntimeException('Driver no longer exists.');
  }
  $s=$oracle_conn->prepare('SELECT COUNT(*) FROM DRIVER WHERE UPPER(LICENSE_NUMBER)=UPPER(:l) AND DRIVER_ID<>:id'); $s->execute([':l'=>$license,':id'=>$id]);
  if ((int)$s->fetchColumn()) throw new RuntimeException('License number is already registered.');
  if (in_array($status,['On Leave','Inactive'],true) && $id>0) {
   $s=$oracle_conn->prepare('SELECT COUNT(*) FROM TRIP WHERE DRIVER_ID=:id AND ARRIVAL_TIME>LOCALTIMESTAMP'); $s->execute([':id'=>$id]);
   if ((int)$s->fetchColumn()) throw new RuntimeException('Reassign ongoing/future trips before setting this status.');
  }
  if ($id>0) {
   $s=$oracle_conn->prepare('UPDATE DRIVER SET DRIVER_NAME=:n,LICENSE_NUMBER=:l,PHONE=:p,STATUS=:s WHERE DRIVER_ID=:id');
   $s->execute([':n'=>$name,':l'=>$license,':p'=>$phone,':s'=>$status,':id'=>$id]);
  } else {
   $s=$oracle_conn->prepare('INSERT INTO DRIVER (DRIVER_NAME,LICENSE_NUMBER,PHONE,STATUS) VALUES (:n,:l,:p,:s) RETURNING DRIVER_ID INTO :id');
   $s->bindValue(':n',$name); $s->bindValue(':l',$license); $s->bindValue(':p',$phone); $s->bindValue(':s',$status); $s->bindParam(':id',$id,PDO::PARAM_INT|PDO::PARAM_INPUT_OUTPUT,32); $s->execute();
  }
  if ($username!=='') {
   $s=$oracle_conn->prepare("SELECT COUNT(*) FROM SYSTEM_USERS WHERE ROLE='DRIVER' AND REFERENCE_ID=:id"); $s->execute([':id'=>$id]);
   if ((int)$s->fetchColumn()) throw new RuntimeException('This driver already has a login. Leave new login fields blank.');
   $s=$oracle_conn->prepare('SELECT COUNT(*) FROM SYSTEM_USERS WHERE LOWER(USERNAME)=LOWER(:u)'); $s->execute([':u'=>$username]);
   if ((int)$s->fetchColumn()) throw new RuntimeException('Username is already in use.');
   $s=$oracle_conn->prepare("INSERT INTO SYSTEM_USERS (USERNAME,PASSWORD_HASH,ROLE,REFERENCE_ID) VALUES (:u,:h,'DRIVER',:id)"); $s->execute([':u'=>$username,':h'=>password_hash($password,PASSWORD_DEFAULT),':id'=>$id]);
  }
  $oracle_conn->commit(); $_SESSION['dm_flash']='Driver saved successfully.'; header('Location: driver_management.php?edit='.$id); exit;
 } catch(Throwable $e) {
  if ($oracle_conn->inTransaction()) $oracle_conn->rollBack(); error_log('Driver management: '.$e->getMessage());
  $error=$e instanceof PDOException ? 'Unable to save. Check database constraints and server logs.' : $e->getMessage();
 }
}
try {
 $drivers=$oracle_conn->query("SELECT d.*, (SELECT MIN(u.USERNAME) FROM SYSTEM_USERS u WHERE u.ROLE='DRIVER' AND u.REFERENCE_ID=d.DRIVER_ID) USERNAME, (SELECT COUNT(*) FROM TRIP t WHERE t.DRIVER_ID=d.DRIVER_ID AND t.ARRIVAL_TIME>LOCALTIMESTAMP) UPCOMING FROM DRIVER d ORDER BY d.DRIVER_ID")->fetchAll();
 $selected=filter_var($_GET['edit'] ?? 0,FILTER_VALIDATE_INT);
 foreach($drivers as $d) if ((int)$d['driver_id']===$selected) $edit=$d;
 if ($edit) {
  $s=$oracle_conn->prepare("SELECT t.TRIP_ID,r.SOURCE,r.DESTINATION,v.PLATE_NUMBER,TO_CHAR(t.DEPARTURE_TIME,'YYYY-MM-DD HH24:MI') DEPARTURE_TIME,TO_CHAR(t.ARRIVAL_TIME,'YYYY-MM-DD HH24:MI') ARRIVAL_TIME FROM TRIP t JOIN ROUTE r ON r.ROUTE_ID=t.ROUTE_ID JOIN VEHICLE v ON v.VEHICLE_ID=t.VEHICLE_ID WHERE t.DRIVER_ID=:id ORDER BY t.DEPARTURE_TIME DESC FETCH FIRST 50 ROWS ONLY"); $s->execute([':id'=>$selected]); $trips=$s->fetchAll();
 }
} catch(Throwable $e) { error_log('Driver list: '.$e->getMessage()); $error='Unable to load drivers or assignments. Check Oracle connection and tables.'; }
$form=$edit ?? ['driver_id'=>0,'driver_name'=>'','license_number'=>'','phone'=>'','status'=>'Active'];
if ($_SERVER['REQUEST_METHOD']==='POST' && $error) foreach(array_keys($form) as $key) if (isset($_POST[$key]) && is_string($_POST[$key])) $form[$key]=$_POST[$key];
include __DIR__.'/../includes/header.php';
?>
<main class="container"><section class="panel"><span class="eyebrow">ADMIN CONTROL</span><h1>Driver Management</h1><p><a href="admin_dashboard.php">Admin Dashboard</a> · <a href="admin_driver_approvals.php">Registration Approvals</a></p>
<?php if($error): ?><div class="alert"><?=dm($error)?></div><?php endif; ?><?php if($flash): ?><div class="success-alert"><?=dm($flash)?></div><?php endif; ?>
<div class="table-wrapper"><table><thead><tr><th>ID</th><th>Driver</th><th>License</th><th>Phone</th><th>Status</th><th>Login</th><th>Ongoing / Future Trips</th><th>Action</th></tr></thead><tbody>
<?php foreach($drivers as $d): ?><tr><td><?=dm($d['driver_id'])?></td><td><?=dm($d['driver_name'])?></td><td><?=dm($d['license_number'])?></td><td><?=dm($d['phone'])?></td><td><?=dm($d['status'])?></td><td><?=dm($d['username'] ?? 'No login linked')?></td><td><?=dm($d['upcoming'])?></td><td><a href="?edit=<?= (int)$d['driver_id']?>#driver-form">Edit / Trips</a></td></tr><?php endforeach; ?>
<?php if(!$drivers && !$error): ?><tr><td colspan="8">No drivers found. Add a driver below or approve a registration.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel" id="driver-form"><h2><?= $edit?'Edit Driver':'Add Driver' ?></h2><form method="post" class="auth-form"><input type="hidden" name="csrf_token" value="<?=dm($_SESSION['csrf_token'])?>"><input type="hidden" name="driver_id" value="<?=dm($form['driver_id'])?>">
<label>Full Name<input name="driver_name" maxlength="100" value="<?=dm($form['driver_name'])?>" required></label><label>License Number<input name="license_number" maxlength="50" value="<?=dm($form['license_number'])?>" required></label><label>Phone<input name="phone" maxlength="15" value="<?=dm($form['phone'])?>" required></label><label>Status<select name="status"><?php foreach($states as $state): ?><option <?= $form['status']===$state?'selected':'' ?>><?=dm($state)?></option><?php endforeach; ?></select></label>
<?php if(!$edit || !$edit['username']): ?><p class="muted">Optional: create a login linked to this driver. Leave both fields blank to save the profile only.</p><label>New Username<input name="username" minlength="3" maxlength="50" autocomplete="off"></label><label>New Password<input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password"></label><?php endif; ?>
<button class="button">Save Driver</button><a href="driver_management.php">Add another driver</a></form></section>
<?php if($edit): ?><section class="panel"><h2>Assignments: <?=dm($edit['driver_name'])?></h2><p class="muted">Vehicle and route assignments are recorded per trip. Latest 50 trips.</p><div class="table-wrapper"><table><thead><tr><th>Trip</th><th>Route</th><th>Vehicle</th><th>Departure</th><th>Arrival</th></tr></thead><tbody><?php foreach($trips as $t): ?><tr><td><?=dm($t['trip_id'])?></td><td><?=dm($t['source'])?> → <?=dm($t['destination'])?></td><td><?=dm($t['plate_number'])?></td><td><?=dm($t['departure_time'])?></td><td><?=dm($t['arrival_time'])?></td></tr><?php endforeach; ?><?php if(!$trips): ?><tr><td colspan="5">No trips assigned to this driver.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?></main>
<?php include __DIR__.'/../includes/footer.php'; ?>
