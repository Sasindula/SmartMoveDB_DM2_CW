<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/vehicle_document_storage.php';
if (($_SESSION['role'] ?? '')!=='DRIVER' || empty($_SESSION['user_id']) || empty($_SESSION['reference_id'])) { http_response_code(403); exit('Driver access required.'); }
function uploadResult(bool $ok,string $message): void {
 $_SESSION['driver_flash']=['ok'=>$ok,'message'=>$message]; header('Location: /SmartMoveDB/views/driver_dashboard.php#vehicle-documents'); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); header('Allow: POST'); exit('Method not allowed.'); }
$stored=null;
try {
 if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) throw new RuntimeException('Reload the dashboard and try again.');
 $vehicleId=filter_var($_POST['vehicle_id'] ?? null,FILTER_VALIDATE_INT);
 if (!$vehicleId || $vehicleId<1) throw new RuntimeException('Select an assigned vehicle.');
 $type=$_POST['document_type'] ?? '';
 if (!in_array($type,['Insurance Policy','Revenue License','Emission Test','Service Report','Other'],true)) throw new RuntimeException('Select a document type.');
 $notes=$_POST['notes'] ?? ''; $expiry=$_POST['expiry_date'] ?? '';
 if (!is_string($notes) || strlen($notes)>500 || !is_string($expiry)) throw new RuntimeException('Notes must be at most 500 bytes.');
 $expiryDate=null;
 if ($expiry!=='') {
  $date=DateTimeImmutable::createFromFormat('!Y-m-d',$expiry,new DateTimeZone('Asia/Colombo'));
  if (!$date || $date->format('Y-m-d')!==$expiry) throw new RuntimeException('Enter a valid expiry date.');
  $expiryDate=new MongoDB\BSON\UTCDateTime($date);
 }
 if (!$mongo_conn instanceof MongoDB\Driver\Manager) throw new RuntimeException('MongoDB is unavailable. Try again later.');
 $s=$oracle_conn->prepare("SELECT v.PLATE_NUMBER FROM VEHICLE v WHERE v.VEHICLE_ID=:vid AND EXISTS (SELECT 1 FROM TRIP t JOIN DRIVER d ON d.DRIVER_ID=t.DRIVER_ID WHERE t.VEHICLE_ID=v.VEHICLE_ID AND t.DRIVER_ID=:did AND d.STATUS<>'Inactive')");
 $s->execute([':vid'=>$vehicleId,':did'=>(int)$_SESSION['reference_id']]); $plate=$s->fetchColumn();
 if ($plate===false) throw new RuntimeException('You can upload only for vehicles assigned through your trips.');
 $file=$_FILES['document'] ?? null;
 if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) throw new RuntimeException('Select one PDF file.');
 if ($file['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Check PHP upload limits and select a PDF up to 5 MB.');
 if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid upload.');
 $size=filesize($file['tmp_name']);
 if ($size===false || $size<5 || $size>5*1024*1024) throw new RuntimeException('PDF must be no larger than 5 MB.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
 if ($mime!=='application/pdf' || file_get_contents($file['tmp_name'],false,null,0,5)!=='%PDF-') throw new RuntimeException('Only PDF documents are accepted.');
 $directory=smDocumentDirectory();
 if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Unable to create document storage. Contact the administrator.');
 $docId='DOC'.bin2hex(random_bytes(16)); $filename=$docId.'.pdf'; $stored=$directory.'/'.$filename;
 if (!move_uploaded_file($file['tmp_name'],$stored)) throw new RuntimeException('Unable to store document. Check folder permissions.');
 $record=['doc_id'=>$docId,'vehicle_id'=>$vehicleId,'vehicle_plate'=>(string)$plate,'document_type'=>$type,'file_path'=>'/SmartMoveDB/views/driver_document_download.php?doc_id='.$docId,'storage_filename'=>$filename,'images'=>[],'metadata'=>['notes'=>trim($notes)],'uploaded_by'=>(int)$_SESSION['user_id'],'driver_id'=>(int)$_SESSION['reference_id'],'uploaded_at'=>new MongoDB\BSON\UTCDateTime()];
 if ($expiryDate!==null) $record['expiry_date']=$expiryDate;
 $bulk=new MongoDB\Driver\BulkWrite(); $bulk->insert($record);
 $result=$mongo_conn->executeBulkWrite($mongo_database.'.vehicle_documents',$bulk);
 if ($result->getInsertedCount()!==1) throw new RuntimeException('Document metadata was not saved.');
 $stored=null; uploadResult(true,'PDF uploaded and saved to MongoDB.');
} catch(Throwable $e) {
 if ($stored!==null && is_file($stored)) unlink($stored);
 error_log('Driver document upload: '.$e->getMessage());
 uploadResult(false,$e instanceof RuntimeException && !$e instanceof MongoDB\Driver\Exception\Exception ? $e->getMessage() : 'Unable to upload document. Check server logs and MongoDB availability.');
}
