<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';
require_once __DIR__ . '/includes/shared/services/ai-research-docx-service.php';
require_once __DIR__ . '/includes/shared/services/survey-v1-unified-research-payload-service.php';
$currentAdmin=tgRequireCurrentAdminSession($pdo,'admin-login.php');$adminId=(int)$currentAdmin['admin_id'];$roleId=(int)$currentAdmin['role_id'];if(!in_array($roleId,array(1,2),true)){header('Location: admin-login.php');exit;}
$token=trim((string)(isset($_GET['report'])?$_GET['report']:''));$reports=isset($_SESSION['tg_v1_unified_ai_reports'])&&is_array($_SESSION['tg_v1_unified_ai_reports'])?$_SESSION['tg_v1_unified_ai_reports']:array();$report=($token!==''&&isset($reports[$token])&&is_array($reports[$token]))?$reports[$token]:null;
if(!$report||(int)(isset($report['admin_id'])?$report['admin_id']:0)!==$adminId||(int)(isset($report['role_id'])?$report['role_id']:0)!==$roleId){http_response_code(404);exit('This Version 1 AI interpretation report is no longer available. Generate it again from Analytics.');}
if($roleId===2&&(int)(isset($report['college_id'])?$report['college_id']:0)!==(int)(isset($currentAdmin['college_id'])?$currentAdmin['college_id']:0)){http_response_code(403);exit('This report does not belong to your assigned department.');}
if(time()-(int)$report['created_at']>7200){unset($_SESSION['tg_v1_unified_ai_reports'][$token]);http_response_code(410);exit('This Version 1 AI interpretation report has expired. Generate a new report from Analytics.');}
try{$data=tgSurveyV1UnifiedResearchPayload($report);$module=tgSurveyV1UnifiedAiNormalizeModule(isset($report['module'])?$report['module']:'comprehensive');$prefix=$roleId===1?'TRACEGRAD_V1_AI_Research_Report_'.$module:'TRACEGRAD_Department_V1_AI_Research_Report_'.$module;tgAiResearchDocxDownload($data,$prefix);}catch(Throwable $e){error_log('TRACEGRAD unified V1 analytics DOCX error: '.$e->getMessage());http_response_code(500);exit('TRACEGRAD could not generate the DOCX report. Enable PHP ZIP or PharData support and try again.');}
