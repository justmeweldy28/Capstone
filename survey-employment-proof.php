<?php
/** TRACEGRAD Phase 3.4G - secure Department Admin employment-proof viewer. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';
require_once __DIR__ . '/includes/shared/services/survey-review-service.php';

$admin=tgRequireCurrentAdminSession($pdo,'admin-login.php');
$roleId=(int)$admin['role_id'];
$collegeId=(int)($admin['college_id']??0);
if($roleId!==2){http_response_code(403);exit('Raw employment proof access is restricted to the responsible Department Administrator.');}
$proofId=isset($_GET['proof_id'])?(int)$_GET['proof_id']:0;
if($proofId<=0){http_response_code(400);exit('Invalid employment proof request.');}
$row=tgSurveyEmploymentProofForViewer($pdo,$proofId,$roleId,$collegeId);
if(!$row){http_response_code(404);exit('Employment proof was not found in your department scope.');}
$filename=trim((string)$row['stored_filename']);
if($filename===''||basename($filename)!==$filename||strpos($filename,'..')!==false){http_response_code(404);exit('Employment proof file is unavailable.');}
$path=__DIR__.'/assets/survey/'.$filename;
if(!is_file($path)||!is_readable($path)){http_response_code(404);exit('Employment proof file is missing from private storage.');}
$mime='';if(class_exists('finfo')){$fi=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$fi->file($path);}if($mime===''){$ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));$map=array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf');$mime=$map[$ext]??'';}
if(!in_array($mime,array('image/jpeg','image/png','image/webp','application/pdf'),true)){http_response_code(415);exit('Unsupported employment proof format.');}
$safe=str_replace(array('"',"\r","\n"),'',basename((string)($row['original_filename']?:$filename)));
while(ob_get_level()>0){@ob_end_clean();}
header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="'.$safe.'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');header('Expires: 0');readfile($path);exit;
