<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/** TRACEGRAD Phase 3.4F - Role-aware Survey Analytics AI DOCX. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';
require_once __DIR__ . '/includes/shared/services/ai-research-docx-service.php';
require_once __DIR__ . '/includes/shared/services/survey-ai-research-payload-service.php';
$currentAdmin=tgRequireCurrentAdminSession($pdo,'admin-login.php');$adminId=(int)$currentAdmin['admin_id'];$roleId=(int)$currentAdmin['role_id'];if(!in_array($roleId,array(1,2),true)){header('Location: admin-login.php');exit;}
$token=trim((string)($_GET['report']??''));$reports=isset($_SESSION['tg_survey_ai_reports'])&&is_array($_SESSION['tg_survey_ai_reports'])?$_SESSION['tg_survey_ai_reports']:array();$report=($token!==''&&isset($reports[$token])&&is_array($reports[$token]))?$reports[$token]:null;if(!$report||(int)($report['admin_id']??0)!==$adminId||(int)($report['role_id']??1)!==$roleId){http_response_code(404);exit('This AI interpretation report is no longer available. Generate it again from Survey Analytics.');}if($roleId===2&&(int)($report['college_id']??0)!==(int)($currentAdmin['college_id']??0)){http_response_code(403);exit('This report does not belong to your assigned department.');}if(time()-(int)$report['created_at']>7200){unset($_SESSION['tg_survey_ai_reports'][$token]);http_response_code(410);exit('This AI interpretation report has expired. Generate a new report from Survey Analytics.');}
try{$researchData=tgSurveyResearchPayload($report);tgAiResearchDocxDownload($researchData,$roleId===1?'TRACEGRAD_Survey_Analytics_AI_Research_Article':'TRACEGRAD_Department_Survey_Analytics_AI_Research_Article');}catch(Throwable $e){error_log('TRACEGRAD survey analytics DOCX error: '.$e->getMessage());http_response_code(500);exit('TRACEGRAD could not generate the DOCX report. Enable PHP ZIP or PharData support and try again.');}
