<?php
$s=$oracle_conn->prepare('SELECT STATUS FROM DRIVER WHERE DRIVER_ID=:id');
$s->execute([':id'=>(int)($_SESSION['reference_id'] ?? 0)]);
$driverAccessStatus=$s->fetchColumn();
if ($driverAccessStatus===false || $driverAccessStatus==='Inactive') {
 http_response_code(403); exit('Driver account unavailable. Contact the administrator.');
}
