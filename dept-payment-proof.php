<?php
/** Secure Department Admin payment-proof viewer. */
require_once __DIR__ . '/includes/dept-admin-dashboard/auth.php';
require_once __DIR__ . '/includes/schema-compat.php';
tgRequireDefenseUpgradeSchema($pdo);
$orderId=max(0,(int)($_GET['order_id']??0)); if($orderId<=0){http_response_code(400);exit('Invalid order.');}
$stmt=$pdo->prepare("SELECT p.proof_of_payment FROM payments p JOIN gallery_orders o ON o.order_id=p.order_id JOIN gallery_images gi ON gi.image_id=o.image_id JOIN gallery_albums ga ON ga.album_id=gi.album_id WHERE o.order_id=? AND ga.college_id=? LIMIT 1");
$stmt->execute(array($orderId,$collegeId));$file=trim((string)$stmt->fetchColumn());
if($file===''||basename($file)!==$file||strpos($file,'..')!==false){http_response_code(404);exit('Payment proof is unavailable.');}
$path=__DIR__.'/assets/payments/'.$file;if(!is_file($path)||!is_readable($path)){http_response_code(404);exit('Payment proof file is missing.');}
$mime='';if(class_exists('finfo')){$fi=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$fi->file($path);}if($mime===''){$ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));$m=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];$mime=$m[$ext]??'';}
if(!in_array($mime,['image/jpeg','image/png','image/webp','application/pdf'],true)){http_response_code(415);exit('Unsupported proof format.');}
header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="'.str_replace(['"',"\r","\n"],'',$file).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, max-age=0');readfile($path);exit;
