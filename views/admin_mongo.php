<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/passenger_mongo.php';
if (($_SESSION['role']??'')!=='ADMIN'||empty($_SESSION['user_id'])){http_response_code(403);exit('Admin access only.');}
// Convert MongoDB arrays and BSON values safely before escaping HTML.
function mh($value): string {
    if ($value instanceof \MongoDB\BSON\UTCDateTime) {
        $value = $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Colombo'))->format('Y-m-d H:i');
    } elseif ($value instanceof \MongoDB\BSON\ObjectId) {
        $value = (string)$value;
    } elseif ($value instanceof \MongoDB\Model\BSONArray || $value instanceof \MongoDB\Model\BSONDocument || is_array($value) || $value instanceof Traversable) {
        $items = [];
        foreach ($value as $item) {
            if (is_scalar($item) || $item === null) $items[] = (string)$item;
            else $items[] = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[object]';
        }
        $value = implode(', ', $items);
    }
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$errors=[];$notice='';$reviews=[];$announcements=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals((string)($_SESSION['csrf_token']??''),(string)($_POST['csrf_token']??'')))$errors[]='Invalid CSRF token.';
 elseif(!($mongo_conn instanceof MongoDB\Driver\Manager))$errors[]='MongoDB unavailable.';
 else try{
  $action=(string)($_POST['action']??'');
  if($action==='announce'){
   $title=trim((string)($_POST['title']??''));$message=trim((string)($_POST['message']??''));$audience=(string)($_POST['audience']??'');$priority=(string)($_POST['priority']??'Normal');
   if(mb_strlen($title)<3||mb_strlen($title)>120||mb_strlen($message)<5||mb_strlen($message)>2000||!in_array($audience,['PASSENGER','DRIVER','ALL'],true)||!in_array($priority,['Normal','High','Urgent'],true))throw new RuntimeException('Check announcement title, message, audience and priority.');
   $bulk=new MongoDB\Driver\BulkWrite();$bulk->insert(['title'=>$title,'message'=>$message,'audience'=>$audience,'priority'=>$priority,'published_date'=>new MongoDB\BSON\UTCDateTime(),'created_by'=>(int)$_SESSION['user_id']]);$mongo_conn->executeBulkWrite($mongo_database.'.travel_announcements',$bulk);$notice='Announcement published.';
  }elseif($action==='delete_review'||$action==='delete_announcement'){
   $id=(string)($_POST['id']??'');if(!preg_match('/^[0-9a-f]{24}$/iD',$id))throw new RuntimeException('Invalid record ID.');
   $collection=$action==='delete_review'?'passenger_reviews':'travel_announcements';$bulk=new MongoDB\Driver\BulkWrite();$bulk->delete(['_id'=>new MongoDB\BSON\ObjectId($id)],['limit'=>1]);$mongo_conn->executeBulkWrite($mongo_database.'.'.$collection,$bulk);$notice='Record deleted.';
  }else throw new RuntimeException('Invalid action.');
 }catch(Throwable $e){error_log('Admin Mongo action: '.$e->getMessage());$errors[]=$e instanceof RuntimeException?$e->getMessage():'MongoDB write failed. Check server logs.';}
}
if($mongo_conn instanceof MongoDB\Driver\Manager){try{$reviews=smMongoFind('passenger_reviews',[],['sort'=>['created_at'=>-1],'limit'=>30]);$announcements=smMongoFind('travel_announcements',[],['sort'=>['published_date'=>-1],'limit'=>30]);}catch(Throwable $e){error_log('Admin Mongo load: '.$e->getMessage());$errors[]='Could not read MongoDB collections.';}}else $errors[]='MongoDB connection unavailable.';
include __DIR__.'/../includes/header.php';
?>
<main class="container passenger-page"><section class="passenger-welcome"><div><span class="eyebrow">ADMIN / MONGODB</span><h1>MongoDB Management</h1><p class="muted">Publish announcements and moderate passenger reviews.</p></div><a class="button" href="/SmartMoveDB/views/admin_dashboard.php">← Admin Dashboard</a></section>
<?php foreach($errors as $err):?><div class="alert"><?=mh($err)?></div><?php endforeach;?><?php if($notice):?><div class="success-alert"><?=mh($notice)?></div><?php endif;?>
<section class="panel"><h2>Publish Travel Announcement</h2><form method="post" class="admin-mongo-form"><input type="hidden" name="csrf_token" value="<?=mh($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="announce"><label>Title<input name="title" maxlength="120" required></label><label>Audience<select name="audience" required><option value="PASSENGER">Passenger</option><option value="DRIVER">Driver</option><option value="ALL">Everyone</option></select></label><label>Priority<select name="priority"><option>Normal</option><option>High</option><option>Urgent</option></select></label><label>Message<textarea name="message" maxlength="2000" rows="4" required></textarea></label><button class="button" type="submit">Publish Announcement</button></form></section>
<section class="panel"><h2>Recent Announcements</h2><div class="admin-mongo-grid"><?php foreach($announcements as $a):?><article class="admin-mongo-item"><strong><?=mh($a['title']??'')?></strong> <span class="badge"><?=mh($a['audience']??'')?></span><p><?=mh($a['message']??'')?></p><small><?=mh(smMongoDate($a['published_date']??null))?></small><?php if(($a['_id']??null) instanceof MongoDB\BSON\ObjectId):?><form method="post" onsubmit="return confirm('Delete this announcement?')"><input type="hidden" name="csrf_token" value="<?=mh($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="id" value="<?=mh((string)$a['_id'])?>"><button class="button secondary-button" type="submit">Delete</button></form><?php endif;?></article><?php endforeach;?><?php if(!$announcements):?><p class="muted">No announcements.</p><?php endif;?></div></section>
<section class="panel"><h2>Passenger Reviews</h2><div class="admin-mongo-grid"><?php foreach($reviews as $r):?><article class="admin-mongo-item"><strong>Passenger #<?=mh($r['passenger_id']??'')?> · Trip #<?=mh($r['trip_id']??'')?></strong><p>Rating: <?=mh($r['rating']??'—')?> / 5</p><p><?=mh($r['review']??$r['comment']??$r['review_text']??'')?></p><small><?=mh(smMongoDate($r['created_at']??null))?></small><?php if(($r['_id']??null) instanceof MongoDB\BSON\ObjectId):?><form method="post" onsubmit="return confirm('Delete this review?')"><input type="hidden" name="csrf_token" value="<?=mh($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="delete_review"><input type="hidden" name="id" value="<?=mh((string)$r['_id'])?>"><button class="button secondary-button" type="submit">Remove Review</button></form><?php endif;?></article><?php endforeach;?><?php if(!$reviews):?><p class="muted">No reviews found.</p><?php endif;?></div></section></main>
<style>.admin-mongo-form{display:grid;gap:14px;max-width:650px}.admin-mongo-form label{display:grid;gap:6px;font-weight:600}.admin-mongo-form input,.admin-mongo-form select,.admin-mongo-form textarea{padding:12px;border:1px solid #ccd8e1;border-radius:8px;font:inherit}.admin-mongo-grid{display:grid;gap:14px;margin-top:18px}.admin-mongo-item{border:1px solid #e3eaf0;border-radius:12px;padding:16px;background:white}.admin-mongo-item p{white-space:pre-wrap;overflow-wrap:anywhere}.admin-mongo-item form{margin-top:12px}</style>
<?php include __DIR__.'/../includes/footer.php'; ?>
