<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
function er($s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$error=''; $success='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 $name=trim((string)($_POST['driver_name']??'')); $license=trim((string)($_POST['license_number']??''));
 $phone=trim((string)($_POST['phone']??'')); $username=trim((string)($_POST['username']??''));
 $password=(string)($_POST['password']??''); $confirm=(string)($_POST['confirm_password']??'');
 if (!hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))) $error='Invalid request. Reload and retry.';
 elseif (!$name||!$license||!$phone||!$username||!$password) $error='Complete all fields.';
 elseif (strlen($name)>100||strlen($license)>50||strlen($phone)>20||strlen($username)>50||!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$username)) $error='Check field lengths and username (letters, digits, _, . or -).';
 elseif (strlen($password)<8||strlen($password)>72||$password!==$confirm) $error='Password must be 8–72 characters and match confirmation.';
 else {
  try {
   $s=$oracle_conn->prepare('SELECT COUNT(*) FROM SYSTEM_USERS WHERE LOWER(USERNAME)=LOWER(:u)'); $s->execute([':u'=>$username]);
   if ((int)$s->fetchColumn()>0) $error='Username is already in use.';
   else {
    $s=$oracle_conn->prepare('SELECT COUNT(*) FROM DRIVER WHERE UPPER(LICENSE_NUMBER)=UPPER(:l)'); $s->execute([':l'=>$license]);
    if ((int)$s->fetchColumn()>0) $error='License number is already registered.';
    else {
     $oracle_conn->beginTransaction();
     $s=$oracle_conn->prepare('INSERT INTO DRIVER_REGISTRATION_REQUESTS (DRIVER_NAME,LICENSE_NUMBER,PHONE,USERNAME,PASSWORD_HASH) VALUES (:n,:l,:p,:u,:h)');
     $s->execute([':n'=>$name,':l'=>$license,':p'=>$phone,':u'=>$username,':h'=>password_hash($password,PASSWORD_DEFAULT)]);
     $oracle_conn->commit(); $success='Registration submitted. Wait for Admin approval before signing in.';
     $_SESSION['csrf_token']=bin2hex(random_bytes(32));
    }
   }
  } catch(Throwable $e) { if ($oracle_conn->inTransaction()) $oracle_conn->rollBack(); error_log('Driver registration: '.$e->getMessage()); $error='Could not submit request. Username or license may already have a request, or database setup is missing.'; }
  }
}
include __DIR__.'/includes/header.php';
?>
<main class="container"><section class="panel auth-panel"><span class="eyebrow">SMARTMOVE DRIVER PORTAL</span><h2>Driver Registration</h2><p class="muted">Your application must be approved by an Admin before you can log in.</p>
<?php if($error): ?><div class="alert"><?=er($error)?></div><?php endif; ?>
<?php if($success): ?><div class="success-alert"><?=er($success)?></div><?php endif; ?>
<form class="auth-form" method="post"><input type="hidden" name="csrf_token" value="<?=er($_SESSION['csrf_token'])?>">
<label>Full Name<input name="driver_name" maxlength="100" required></label>
<label>License Number<input name="license_number" maxlength="50" required></label>
<label>Phone<input name="phone" maxlength="20" required></label>
<label>Username<input name="username" maxlength="50" minlength="3" pattern="[A-Za-z0-9_.-]+" required></label>
<label>Password<input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
<label>Confirm Password<input type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
<button class="button" type="submit">Submit for Approval</button></form><p><a href="/SmartMoveDB/login.php">Back to Login</a></p></section></main>
<?php include __DIR__.'/includes/footer.php'; ?>
