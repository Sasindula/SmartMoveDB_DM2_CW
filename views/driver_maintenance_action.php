<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
if (($_SESSION['role'] ?? '')!=='DRIVER' || empty($_SESSION['user_id']) || empty($_SESSION['reference_id'])) { http_response_code(403); exit('Driver access required.'); }
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); header('Allow: POST'); exit('Method not allowed.'); }
function maintenanceResult(bool $ok,string $message): void {
 $_SESSION['driver_flash']=['ok'=>$ok,'message'=>$message]; header('Location: /SmartMoveDB/views/driver_dashboard.php#maintenance'); exit;
}
function syncDriverMaintenance(array $record): void {
 global $mongo_conn,$mongo_database;
 if (!$mongo_conn instanceof MongoDB\Driver\Manager) throw new RuntimeException('MongoDB unavailable.');
 $bulk=new MongoDB\Driver\BulkWrite();
 $bulk->update(['maintenance_id'=>(int)$record['maintenance_id']],['$set'=>$record],['upsert'=>true,'multi'=>false]);
 $mongo_conn->executeBulkWrite($mongo_database.'.vehicle_maintenances',$bulk);
}
try {
 if (!is_string($_POST['maintenance_token'] ?? null) || empty($_SESSION['maintenance_token']) || !hash_equals($_SESSION['maintenance_token'],$_POST['maintenance_token'])) throw new RuntimeException('Reload the dashboard before submitting again.');
 $driverId=(int)$_SESSION['reference_id'];
 $action=$_POST['action'] ?? '';
 if (!in_array($action,['add','retry'],true)) throw new RuntimeException('Invalid action.');
 if ($action==='retry') {
  $id=filter_var($_POST['maintenance_id'] ?? null,FILTER_VALIDATE_INT);
  $pending=$_SESSION['maintenance_pending'][$id ?: 0] ?? null;
  if (!$pending || (int)$pending['driver_id']!==$driverId) throw new RuntimeException('No pending sync found for this record.');
  $vehicleId=(int)$pending['vehicle_id'];
 } else {
  $vehicleId=filter_var($_POST['vehicle_id'] ?? null,FILTER_VALIDATE_INT);
  if (!$vehicleId || $vehicleId<1) throw new RuntimeException('Select an assigned vehicle.');
 }
 $oracle_conn->beginTransaction();
 $s=$oracle_conn->prepare('SELECT STATUS FROM DRIVER WHERE DRIVER_ID=:id FOR UPDATE'); $s->execute([':id'=>$driverId]); $state=$s->fetchColumn();
 if ($state===false || $state==='Inactive') throw new RuntimeException('Driver account unavailable.');
 $s=$oracle_conn->prepare('SELECT v.PLATE_NUMBER FROM VEHICLE v WHERE v.VEHICLE_ID=:vid AND EXISTS (SELECT 1 FROM TRIP t WHERE t.VEHICLE_ID=v.VEHICLE_ID AND t.DRIVER_ID=:did)');
 $s->execute([':vid'=>$vehicleId,':did'=>$driverId]); $plate=$s->fetchColumn();
 if ($plate===false) throw new RuntimeException('Vehicle is not assigned to you through a trip.');
 if ($action==='add') {
  foreach(['maintenance_date','description','cost','service_center'] as $key) if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('Complete the maintenance form.');
  $date=$_POST['maintenance_date']; $description=trim($_POST['description']); $cost=$_POST['cost']; $serviceCenter=trim($_POST['service_center']);
  $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
  if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new RuntimeException('Enter a valid maintenance date.');
  $today=$oracle_conn->query("SELECT TO_CHAR(SYSDATE,'YYYY-MM-DD') FROM DUAL")->fetchColumn();
  if ($date>$today) throw new RuntimeException('Completed maintenance cannot be recorded with a future date.');
  if ($description==='' || strlen($description)>255 || strlen($serviceCenter)>100) throw new RuntimeException('Description is required (up to 255 bytes); service center must be up to 100 bytes.');
  if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/',$cost)) throw new RuntimeException('Cost must be 0–99999999.99 with at most two decimals.');
  $s=$oracle_conn->prepare("INSERT INTO MAINTENANCE (VEHICLE_ID,MAINTENANCE_DATE,DESCRIPTION,COST) VALUES (:v,TO_DATE(:dt,'YYYY-MM-DD'),:descr,:cost) RETURNING MAINTENANCE_ID INTO :id");
  $id=0; $s->bindValue(':v',$vehicleId,PDO::PARAM_INT); $s->bindValue(':dt',$date); $s->bindValue(':descr',$description); $s->bindValue(':cost',$cost); $s->bindParam(':id',$id,PDO::PARAM_INT|PDO::PARAM_INPUT_OUTPUT,32); $s->execute();
 } else {
  $s=$oracle_conn->prepare("SELECT TO_CHAR(MAINTENANCE_DATE,'YYYY-MM-DD') MAINTENANCE_DATE,DESCRIPTION,COST FROM MAINTENANCE WHERE MAINTENANCE_ID=:id AND VEHICLE_ID=:v FOR UPDATE");
  $s->execute([':id'=>$id,':v'=>$vehicleId]); $row=$s->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Maintenance record no longer exists.');
  $date=$row['maintenance_date']; $description=$row['description']; $cost=$row['cost']; $serviceCenter=$pending['service_center'];
 }
 $oracle_conn->commit();
 // Oracle is authoritative. A failed mirror write never invites another INSERT.
 $_SESSION['maintenance_pending'][$id]=['vehicle_id'=>$vehicleId,'driver_id'=>$driverId,'service_center'=>$serviceCenter];
 $_SESSION['maintenance_token']=bin2hex(random_bytes(32));
 try {
  syncDriverMaintenance(['maintenance_id'=>(int)$id,'vehicle_id'=>(int)$vehicleId,'vehicle_plate'=>(string)$plate,'maintenance_date'=>$date,'description'=>$description,'cost'=>(float)$cost,'service_center'=>$serviceCenter,'updated_at'=>new MongoDB\BSON\UTCDateTime()]);
  unset($_SESSION['maintenance_pending'][$id]);
  maintenanceResult(true,($action==='add'?'Maintenance #'.$id.' saved to Oracle and MongoDB.':'Maintenance #'.$id.' MongoDB sync completed.'));
 } catch(Throwable $e) {
  error_log('Driver maintenance MongoDB sync #'.$id.': '.$e->getMessage());
  maintenanceResult(false,'Maintenance #'.$id.' is saved in Oracle. MongoDB sync failed. Use Retry MongoDB Sync below; do not add the same record again.');
 }
} catch(Throwable $e) {
 if ($oracle_conn->inTransaction()) $oracle_conn->rollBack(); error_log('Driver maintenance: '.$e->getMessage());
 maintenanceResult(false,$e instanceof PDOException ? 'Unable to save maintenance. Check server logs.' : $e->getMessage());
}
