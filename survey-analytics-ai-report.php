<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/** TRACEGRAD Phase 3.4F - Role-aware Survey Analytics AI Research Article Preview. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';
require_once __DIR__ . '/includes/shared/services/survey-ai-research-payload-service.php';
$currentAdmin=tgRequireCurrentAdminSession($pdo,'admin-login.php');$adminId=(int)$currentAdmin['admin_id'];$roleId=(int)$currentAdmin['role_id'];if(!in_array($roleId,array(1,2),true)){header('Location: admin-login.php');exit;}
$token=trim((string)($_GET['report']??''));$reports=isset($_SESSION['tg_survey_ai_reports'])&&is_array($_SESSION['tg_survey_ai_reports'])?$_SESSION['tg_survey_ai_reports']:array();$report=($token!==''&&isset($reports[$token])&&is_array($reports[$token]))?$reports[$token]:null;
if(!$report||(int)($report['admin_id']??0)!==$adminId||(int)($report['role_id']??1)!==$roleId){http_response_code(404);exit('This AI interpretation report is no longer available. Return to Survey Analytics and generate it again.');}
if($roleId===2&&(int)($report['college_id']??0)!==(int)($currentAdmin['college_id']??0)){http_response_code(403);exit('This report does not belong to your assigned department.');}
if(time()-(int)$report['created_at']>7200){unset($_SESSION['tg_survey_ai_reports'][$token]);http_response_code(410);exit('This AI interpretation report has expired. Generate a new report from Survey Analytics.');}
$researchData=tgSurveyResearchPayload($report);$researchBackUrl=$roleId===1?'admin-dashboard.php?tab=analytics&focus=survey':'dept-admin-dashboard.php?tab=survey-analytics';$researchBackLabel=$roleId===1?'Back to Survey Analytics':'Back to Department Survey Analytics';$researchDownloadUrl='survey-analytics-ai-docx.php?report='.rawurlencode($token);$researchRegenerate=array('endpoint'=>$roleId===1?'api/analytics/super-admin-survey-ai.php':'api/analytics/dept-admin-survey-ai.php','csrf'=>$_SESSION['csrf']??'');$researchFilterJson=json_encode($report['snapshot']['filters']??array(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);require __DIR__ . '/includes/shared/views/ai-research-article-preview.php';
