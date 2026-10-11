<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
if (($_SESSION['role']??'')!=='ADMIN'||empty($_SESSION['user_id'])) { http_response_code(403); exit('Admin access only.'); }
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
function ah($v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$error='';$success='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))) $error='Invalid CSRF token.';
 else {
  $id=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT);
  $action=(string)($_POST['decision']??'');
  if (!$id||!in_array($action,['approve','reject'],true)) $error='Invalid request.';
  else {
   try {
    $oracle_conn->beginTransaction();
    $s=$oracle_conn->prepare('SELECT DRIVER_NAME,LICENSE_NUMBER,PHONE,USERNAME,PASSWORD_HASH,STATUS FROM DRIVER_REGISTRATION_REQUESTS WHERE REQUEST_ID=:id FOR UPDATE');
    $s->execute([':id'=>$id]);$r=$s->fetch(PDO::FETCH_ASSOC);
    if (!$r||$r['status']!=='Pending') throw new RuntimeException('Request is no longer pending.');
    if ($action==='approve') {
     $s=$oracle_conn->prepare('SELECT COUNT(*) FROM SYSTEM_USERS WHERE LOWER(USERNAME)=LOWER(:u)');$s->execute([':u'=>$r['username']]);
     if ((int)$s->fetchColumn()>0) throw new RuntimeException('Username already exists.');
     $s=$oracle_conn->prepare('SELECT COUNT(*) FROM DRIVER WHERE UPPER(LICENSE_NUMBER)=UPPER(:l)');$s->execute([':l'=>$r['license_number']]);
     if ((int)$s->fetchColumn()>0) throw new RuntimeException('License already exists.');
     $s=$oracle_conn->prepare("INSERT INTO DRIVER (DRIVER_NAME,LICENSE_NUMBER,PHONE,STATUS) VALUES (:n,:l,:p,'Active') RETURNING DRIVER_ID INTO :driver_id");
     $driverId=0;$s->bindValue(':n',$r['driver_name']);$s->bindValue(':l',$r['license_number']);$s->bindValue(':p',$r['phone']);
     $s->bindParam(':driver_id',$driverId,PDO::PARAM_INT|PDO::PARAM_INPUT_OUTPUT,32);$s->execute();
     $s=$oracle_conn->prepare("INSERT INTO SYSTEM_USERS (USERNAME,PASSWORD_HASH,ROLE,REFERENCE_ID,CREATED_AT) VALUES (:u,:h,'DRIVER',:d,SYSTIMESTAMP)");
     $s->execute([':u'=>$r['username'],':h'=>$r['password_hash'],':d'=>$driverId]);
    }
    $status=$action==='approve'?'Approved':'Rejected';
    $s=$oracle_conn->prepare('UPDATE DRIVER_REGISTRATION_REQUESTS SET STATUS=:s,REVIEWED_AT=SYSTIMESTAMP,REVIEWED_BY=:admin WHERE REQUEST_ID=:id');
    $s->execute([':s'=>$status,':admin'=>(int)$_SESSION['user_id'],':id'=>$id]);
    $oracle_conn->commit();$success='Request '.$status.'.';
   } catch(Throwable $e) {if($oracle_conn->inTransaction())$oracle_conn->rollBack();error_log('Driver approval: '.$e->getMessage());$error=$e instanceof RuntimeException?$e->getMessage():'Could not process request. Check Oracle setup and logs.';}
  }
 }
}
$requests=[];
try {$s=$oracle_conn->query("SELECT REQUEST_ID,DRIVER_NAME,LICENSE_NUMBER,PHONE,USERNAME,TO_CHAR(CREATED_AT,'YYYY-MM-DD HH24:MI') CREATED_AT FROM DRIVER_REGISTRATION_REQUESTS WHERE STATUS='Pending' ORDER BY CREATED_AT");$requests=$s->fetchAll(PDO::FETCH_ASSOC);} catch(Throwable $e){error_log('Approval list: '.$e->getMessage());$error='Could not load requests. Run SQL migration first.';}
include __DIR__.'/../includes/header.php';
?>
<main class="container"><section class="panel"><span class="eyebrow">ADMIN CONTROL</span><h2>Driver Registration Approvals</h2><p class="muted">Review identity and license details before approval. Only approved drivers receive a login account.</p>
<?php if($error):?><div class="alert"><?=ah($error)?></div><?php endif;?><?php if($success):?><div class="success-alert"><?=ah($success)?></div><?php endif;?>
<div class="table-wrapper"><table><thead><tr><th>Request</th><th>Driver</th><th>License</th><th>Phone</th><th>Username</th><th>Submitted</th><th>Decision</th></tr></thead><tbody>
<?php foreach($requests as $r):?><tr><td>#<?=ah($r['request_id'])?></td><td><?=ah($r['driver_name'])?></td><td><?=ah($r['license_number'])?></td><td><?=ah($r['phone'])?></td><td><?=ah($r['username'])?></td><td><?=ah($r['created_at'])?></td><td><form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?=ah($_SESSION['csrf_token'])?>"><input type="hidden" name="request_id" value="<?=ah($r['request_id'])?>"><button class="button" name="decision" value="approve" onclick="return confirm('Approve this driver?')">Approve</button><button class="button secondary-button" name="decision" value="reject" onclick="return confirm('Reject this application?')">Reject</button></form></td></tr><?php endforeach;?>
<?php if(!$requests):?><tr><td colspan="7">No pending driver requests.</td></tr><?php endif;?></tbody></table></div></section></main>
<?php include __DIR__.'/../includes/footer.php';?>
