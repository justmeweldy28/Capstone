<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/** TRACEGRAD AI-Assisted Graduate Outcomes Research Article */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';
$currentAdmin=tgRequireCurrentAdminSession($pdo,'admin-login.php');
$roleId=(int)$currentAdmin['role_id']; if(!in_array($roleId,array(1,2),true)){http_response_code(403);exit('Unauthorized.');}
$adminId=(int)$currentAdmin['admin_id'];
$format=strtolower(trim((string)($_GET['format']??'html'))); if($format==='word')$format='docx'; if(!in_array($format,array('html','docx'),true))$format='html';
function tg_art_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function tg_art_list($values){$out=array();foreach((array)$values as $v){$v=trim((string)$v);if($v!=='')$out[]=$v;}return $out;}

$source='TRACEGRAD Local Analytics'; $model=''; $warning=''; $interpretation=array('summary'=>'','findings'=>array(),'actions'=>array(),'caveats'=>array());
$scopeLabel=''; $surveyLabel='Current survey context'; $metrics=array(); $resultRows=array(); $distributionRows=array();

try {
    if($roleId===1){
        require_once __DIR__.'/includes/shared/services/super-admin-ai-analytics-service.php';
        $filters=array(
            'college_id'=>max(0,(int)($_GET['college_id']??0)),
            'batch_year'=>max(0,(int)($_GET['batch_year']??0)),
            'survey_version_id'=>max(0,(int)($_GET['survey_version_id']??0)),
        );
        $snapshot=tgSuperAiBuildSnapshot($pdo,$filters);
        $ai=tgSuperAiAnalyticsGenerate($snapshot);
        $interpretation=$ai['interpretation']; $source=$ai['source']; $model=(string)($ai['model']??''); $warning=(string)($ai['warning']??'');
        $scopeLabel=(string)($snapshot['scope']['label']??'Institution-wide');
        if(!empty($snapshot['scope']['survey_version'])) $surveyLabel=(string)$snapshot['scope']['survey_version']['version_name'].' · '.(string)$snapshot['scope']['survey_version']['status'];
        $k=$snapshot['kpis'];
        $metrics=array(
            array('Total alumni',(int)$k['total_alumni'],'records'),
            array('Recorded employed / self-employed / freelancer',(int)$k['employed'],'records'),
            array('Employment rate',(int)$k['employment_rate'],'%'),
            array('Survey submitted',(int)$k['survey_submitted'],'records'),
            array('Survey completion rate',(int)$k['survey_completion_rate'],'%'),
            array('Active alumni accounts',(int)$k['active_accounts'],'records'),
            array('Account activation rate',(int)$k['account_activation_rate'],'%'),
            array('Missing employment data',(int)$k['missing_employment'],'records'),
            array('Incomplete contact data',(int)$k['missing_contact'],'records'),
        );
        foreach((array)$snapshot['college_performance'] as $r) $resultRows[]=array((string)$r['college_code'],(int)$r['total'],(int)$r['employment_rate'].'%',(int)$r['survey_rate'].'%');
        foreach((array)$snapshot['employment_distribution'] as $r) $distributionRows[]=array((string)($r['label']??$r['employment_status']??'Unknown'),(int)($r['total']??0));
    } else {
        require_once __DIR__.'/includes/shared/services/dept-openai-analytics-service.php';
        $collegeId=(int)($_SESSION['admin_college_id']??0); if($collegeId<=0)throw new RuntimeException('Department assignment missing.');
        $surveyVersion=max(0,(int)($_GET['survey_version_id']??($_GET['survey_version']??0)));
        $m=tgDeptAiBuildMetrics($pdo,$collegeId,$surveyVersion); $metricsRaw=$m;
        $ai=tgDeptAiInterpretWithOpenAI($m);
        if(!empty($ai['ok'])){$interpretation=$ai['interpretation'];$source='OpenAI Assisted';$model=(string)$ai['model'];}
        else{$interpretation=tgDeptAiLocalInterpretation($m);$source='TRACEGRAD Local Analytics';$warning=(string)($ai['reason']??'AI service unavailable.');}
        $scopeLabel=(string)($m['scope']['college_code']??'Department').' — '.(string)($m['scope']['college_name']??'Department scope');
        $surveyLabel=(string)($m['survey_version']['label']??'Current survey context');
        $metrics=array(
            array('Total alumni',(int)$m['total_alumni'],'records'),array('Active programs',(int)$m['active_programs'],'programs'),
            array('Working alumni',(int)$m['working_alumni'],'records'),array('Employment rate',number_format((float)$m['employment_rate'],1),'%'),
            array('Survey responded',(int)$m['survey_responded'],'records'),array('Survey response rate',number_format((float)$m['survey_response_rate'],1),'%'),
            array('Survey completed',(int)$m['survey_completed'],'records'),array('Survey completion rate',number_format((float)$m['survey_completion_rate'],1),'%'),
            array('Contact completeness',number_format((float)$m['contact_completeness_rate'],1),'%'),array('Employment proof coverage',number_format((float)$m['proof_coverage_rate'],1),'%'),
        );
        foreach((array)$m['programs'] as $r)$resultRows[]=array((string)$r['code'],(int)$r['total'],number_format((float)$r['employment_rate'],1).'%',number_format((float)$r['survey_response_rate'],1).'%');
        foreach((array)$m['employment_status'] as $label=>$count)$distributionRows[]=array((string)$label,(int)$count);
    }
} catch(Throwable $e){ error_log('TRACEGRAD research article error: '.$e->getMessage()); http_response_code(500); exit('The research article could not be prepared.'); }

$findings=tg_art_list(isset($interpretation['findings']) ? $interpretation['findings'] : array());
$actions=tg_art_list(isset($interpretation['actions']) ? $interpretation['actions'] : array());
$caveats=tg_art_list(isset($interpretation['caveats']) ? $interpretation['caveats'] : array());
$summary=trim((string)(isset($interpretation['summary']) ? $interpretation['summary'] : ''));

$totalAlumni=0; $employmentRate=null; $surveyRate=null; $activeAccounts=null;
foreach($metrics as $mrow){
    if($mrow[0]==='Total alumni')$totalAlumni=(int)$mrow[1];
    if($mrow[0]==='Employment rate')$employmentRate=$mrow[1];
    if($mrow[0]==='Active alumni accounts')$activeAccounts=$mrow[1];
    if(in_array($mrow[0],array('Survey completion rate','Survey response rate'),true)&&$surveyRate===null)$surveyRate=$mrow[1];
}
$abstract='This administrative tracer-study article summarizes aggregate graduate outcomes recorded in TRACEGRAD for the selected scope ('.$scopeLabel.'). The analysis covers '.$totalAlumni.' alumni records and reports descriptive indicators for employment, survey participation, account/data readiness, and related institutional measures. Official counts and rates were calculated by TRACEGRAD using PHP/MySQL; AI was used only to assist the narrative interpretation of those aggregate statistics.';
if($employmentRate!==null)$abstract.=' The recorded employment rate was '.$employmentRate.'%';
if($surveyRate!==null)$abstract.=' and the selected survey coverage indicator was '.$surveyRate.'%';
$abstract.='. Findings are descriptive and should not be interpreted as causal evidence.';

$title='Graduate Outcomes and Tracer-Study Indicators: An AI-Assisted Descriptive Analysis of TRACEGRAD Data';
$summaryRows=array(
    array('Total Alumni', number_format($totalAlumni)),
    array('Employment Rate', $employmentRate!==null ? (string)$employmentRate.'%' : 'Not available'),
    array('Survey Coverage', $surveyRate!==null ? (string)$surveyRate.'%' : 'Not available'),
    array('Active Alumni Accounts', $activeAccounts!==null ? number_format((int)$activeAccounts) : 'Scope dependent'),
    array('Analytical Scope', $scopeLabel),
    array('Survey Context', $surveyLabel),
);

$keyIndicatorRows=array();
foreach($metrics as $mrow){
    $keyIndicatorRows[]=array((string)$mrow[0],trim((string)$mrow[1].' '.(string)$mrow[2]));
}
$performanceRows=array();
foreach($resultRows as $row){
    $performanceRows[]=array_values($row);
}
$statusRows=array();
foreach($distributionRows as $row){
    $statusRows[]=array((string)$row[0],number_format((int)$row[1]));
}

$objectives=array(
    'Describe the selected alumni population using official TRACEGRAD aggregate indicators.',
    'Summarize recorded employment and tracer-survey participation outcomes for the selected scope.',
    'Identify evidence-based follow-up priorities while preserving the distinction between official statistics and AI-assisted interpretation.',
);
$notes=array(
    'Institution: Iloilo State University of Fisheries Science and Technology – San Enrique Campus.',
    'Analytical scope: '.$scopeLabel.'.',
    'Survey context: '.$surveyLabel.'.',
    'Official numerical results were calculated by TRACEGRAD from PHP/MySQL database queries.',
    'AI assistance was limited to aggregate narrative interpretation; no alumni names, student IDs, personal contacts, private addresses, uploaded proofs, or exact workplace coordinates were supplied to the AI model.',
);
foreach($caveats as $caveat){$notes[]=$caveat;}

$researchData=array(
    'title'=>$title,
    'subtitle'=>'TRACEGRAD Alumni Analytics Research Article',
    'generated'=>date('F j, Y'),
    'source'=>$source.($model!==''?' · '.$model:''),
    'generated_for'=>$roleId===1?'Super Administrator':'Department Administrator',
    'scope'=>$scopeLabel.' | Survey: '.$surveyLabel,
    'warning'=>$warning,
    'summary_rows'=>$summaryRows,
    'sections'=>array(
        array('heading'=>'Abstract','paragraphs'=>array($abstract)),
        array('heading'=>'Introduction','paragraphs'=>array(
            'Graduate tracer information supports institutional understanding of alumni participation, employment outcomes, job-course alignment, and data completeness. TRACEGRAD centralizes these records for Iloilo State University of Fisheries Science and Technology – San Enrique Campus and provides role-scoped analytics for administrative review.',
            'This research-style article translates the selected Alumni Analytics scope into a structured institutional report without inferring information beyond the records stored in TRACEGRAD.'
        )),
        array('heading'=>'Objectives','items'=>$objectives),
        array('heading'=>'Methodology / Data Source','paragraphs'=>array(
            'Descriptive administrative-data analysis was performed using alumni records available in TRACEGRAD at the time of generation. Employment indicators use the latest recorded employment entry according to TRACEGRAD analytics logic, while survey indicators use the selected tracer-survey context.',
            'Official counts, proportions, and distributions were calculated server-side using PHP/MySQL. The AI component received aggregate statistics only and was asked to summarize patterns, practical actions, and limitations.'
        )),
        array('heading'=>'Results and Discussion','paragraphs'=>array(
            $summary,
            'The following tables contain the same official indicators shown in this browser preview and included in the downloaded DOCX file.'
        ),'tables'=>array(
            array('title'=>'Key Alumni Indicators','headers'=>array('Indicator','Value'),'rows'=>$keyIndicatorRows),
            array('title'=>'Program / Department Performance','headers'=>array('Scope','Alumni','Employment Rate','Survey Rate'),'rows'=>$performanceRows),
            array('title'=>'Employment Status Distribution','headers'=>array('Status','Recorded Alumni'),'rows'=>$statusRows),
        )),
        array('heading'=>'Key Findings','items'=>$findings),
        array('heading'=>'Conclusion','paragraphs'=>array('The selected TRACEGRAD scope provides a current descriptive view of graduate participation and outcomes. The reported indicators can support tracer-study monitoring, data-quality follow-up, program review, and alumni engagement. Because the database is continuously updated, this article represents a time-specific administrative snapshot rather than a fixed longitudinal conclusion.')),
        array('heading'=>'Recommendations','items'=>$actions ? $actions : array('Continue routine monitoring and verify data completeness before acting on observed patterns.')),
        array('heading'=>'References / Data Notes','items'=>$notes),
    ),
);

$base=$_GET;
unset($base['format']);
$q=http_build_query($base);
$format=strtolower(trim((string)(isset($_GET['format']) ? $_GET['format'] : 'html')));
if($format==='word')$format='docx';
if($format==='docx'){
    require_once __DIR__.'/includes/shared/services/ai-research-docx-service.php';
    try{
        // The same canonical payload is used for preview and downloaded DOCX.
        tgAiResearchDocxDownload($researchData,'TRACEGRAD_Alumni_Analytics_AI_Research_Article');
    }catch(Throwable $e){
        error_log('TRACEGRAD research DOCX export error: '.$e->getMessage());
        http_response_code(500);
        exit('The DOCX research article could not be generated. Enable PHP ZIP or PharData support and try again.');
    }
}

$researchBackUrl=$roleId===1?'admin-dashboard.php?tab=analytics&focus=alumni':'dept-admin-dashboard.php?tab=analytics';
$researchBackLabel=$roleId===1?'Back to Alumni Analytics':'Back to Department Analytics';
$researchDownloadUrl='analytics-research-report.php?'.($q!==''?$q.'&':'').'format=docx';
$researchRegenerate=array('url'=>'analytics-research-report.php'.($q!==''?'?'.$q:''));
$researchFilterJson='{}';
require __DIR__.'/includes/shared/views/ai-research-article-preview.php';
