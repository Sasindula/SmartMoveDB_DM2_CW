<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/passenger_mongo.php';
require_once __DIR__.'/../includes/vehicle_document_storage.php';
if (($_SESSION['role'] ?? '')!=='DRIVER' || empty($_SESSION['user_id']) || empty($_SESSION['reference_id'])) { http_response_code(403); exit('Driver access required.'); }
try {
 $id=$_GET['doc_id'] ?? '';
 if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/',$id)) { http_response_code(400); exit('Invalid document ID.'); }
 if (!$mongo_conn instanceof MongoDB\Driver\Manager) throw new RuntimeException('MongoDB unavailable.');
 $docs=smMongoFind('vehicle_documents',['doc_id'=>$id],['limit'=>1]); $doc=$docs[0] ?? null;
 if (!$doc) { http_response_code(404); exit('Document not found.'); }
 $s=$oracle_conn->prepare("SELECT COUNT(*) FROM TRIP t JOIN DRIVER d ON d.DRIVER_ID=t.DRIVER_ID WHERE t.VEHICLE_ID=:v AND t.DRIVER_ID=:d AND d.STATUS<>'Inactive'");
 $s->execute([':v'=>(int)($doc['vehicle_id'] ?? 0),':d'=>(int)$_SESSION['reference_id']]);
 if (!(int)$s->fetchColumn()) { http_response_code(403); exit('This vehicle is not assigned to you.'); }
 if (isset($doc['storage_filename'])) {
  $filename=(string)$doc['storage_filename'];
  if (!preg_match('/^DOC[a-f0-9]{32}\.pdf$/',$filename)) throw new RuntimeException('Invalid stored document name.');
  $path=smDocumentDirectory().'/'.$filename;
 } else {
  // Allow the existing sample PDFs only from the project's docs folder.
  $url=(string)($doc['file_path'] ?? '');
  if (!preg_match('~^/SmartMoveDB/uploads/docs/([A-Za-z0-9_.-]+\.pdf)$~i',$url,$match)) { http_response_code(404); exit('Document not uploaded.'); }
  $base=realpath(__DIR__.'/../uploads/docs'); $path=realpath(__DIR__.'/../uploads/docs/'.$match[1]);
  if (!$base || !$path || dirname($path)!==$base) { http_response_code(404); exit('Document not uploaded.'); }
 }
 if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Document not uploaded or file is missing.'); }
 header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
 header('Content-Disposition: inline; filename="'.$id.'.pdf"'); header('Content-Length: '.filesize($path)); readfile($path); exit;
} catch(Throwable $e) { error_log('Document download: '.$e->getMessage()); http_response_code(503); exit('Document service unavailable. Try again later.'); }
