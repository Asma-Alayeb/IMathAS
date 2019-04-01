<?php
/*
 * IMathAS: Question autosaving Endpoint
 * (c) 2019 David Lippman
 *
 * Method: POST
 * Query string parameters:
 *  aid   Assessment ID
 *  cid   Course ID
 *  uid   Optional. Only allowed for teachers, to load student's assessment
 *
 * POST parameters:
 *  tosaveqn           stringified json, keyed by qn, value is array of part numbers
 *  lastloaded          stringified json keyed by qn
 *
 * Returns: partial assessInfo object, mainly including the scored question
 *          object, but may also update some assessInfo fields
 */

$init_skip_csrfp = true; // TODO: get CSRFP to work
require_once("../init.php");
require_once("./common_start.php");
require_once("./AssessInfo.php");
require_once("./AssessRecord.php");
require_once('./AssessUtils.php');

//error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// validate inputs
check_for_required('GET', array('aid', 'cid'));
check_for_required('POST', array('tosaveqn', 'lastloaded'));
$cid = Sanitize::onlyInt($_GET['cid']);
$aid = Sanitize::onlyInt($_GET['aid']);
if ($isteacher && isset($_GET['uid'])) {
  $uid = Sanitize::onlyInt($_GET['uid']);
} else {
  $uid = $userid;
}

$qns = json_decode($_POST['tosaveqn'], true);
$lastloaded = json_decode($_POST['lastloaded'], true);
$verification = json_decode($_POST['verification'], true);

if ($qns === null || $lastloaded === null) {
  echo '{"error": "invalid_params"}';
  exit;
}

$now = time();

// load settings
$assess_info = new AssessInfo($DBH, $aid, $cid, false);
$assess_info->loadException($uid, $isstudent, $studentinfo['latepasses'] , $latepasshrs, $courseenddate);
if ($isstudent) {
  $assess_info->applyTimelimitMultiplier($studentinfo['timelimitmult']);
}

// reject if not available
if ($assess_info->getSetting('available') === 'practice' && !empty($_POST['practice'])) {
  $in_practice = true;
} else if ($assess_info->getSetting('available') === 'yes') {
  $in_practice = false;
} else {
  echo '{"error": "not_avail"}';
  exit;
}


// load user's assessment record
$assess_record = new AssessRecord($DBH, $assess_info, $in_practice);
$assess_record->loadRecord($uid);

// make sure a record exists
if (!$assess_record->hasRecord() || !$assess_record->hasActiveAttempt()) {
  echo '{"error": "not_ready"}';
  exit;
}

// reject start if has current attempt, time limit expired, and is kick out
if (!$in_practice &&
  $assess_record->hasActiveAttempt() &&
  $assess_info->getSetting('timelimit') > 0 &&
  $assess_info->getSetting('timelimit_type') == 'kick_out' &&
  $now > $assess_record->getTimeLimitExpires() + 5  // TODO: adjust
) {
  echo '{"error": "timelimit_expired"}';
  exit;
}

// if there's no active assessment attempt, exit
if (!$assess_record->hasUnsubmittedAttempt()) {
  echo '{"error": "not_ready"}';
  exit;
}

$qids = $assess_record->getQuestionIds(array_keys($qns));

// load question settings and code
$assess_info->loadQuestionSettings($qids, false);

// If in practice, now we overwrite settings
if ($in_practice) {
  $assess_info->overridePracticeSettings();
}

// Verify confirmation values (to ensure it hasn't been submitted since)
if (!$assess_record->checkVerification($verification)) {
  // grab question settings data with HTML to update front-end
  $showscores = $assess_info->showScoresDuring();
  $assessInfoOut['questions'] = array();
  foreach ($qns as $qn) {
    $assessInfoOut['questions'][$qn] = $assess_record->getQuestionObject($qn, $showscores, true, true);
  }
  $assessInfoOut['error'] = "already_submitted";
  echo json_encode($assessInfoOut);
  exit;
}

// autosave the requested parts
foreach ($qns as $qn=>$parts) {
  $ok_to_save = $assess_record->isSubmissionAllowed($qn, $qids[$qn]);
  foreach ($parts as $part) {
    if ($ok_to_save === true || $ok_to_save[$part]) {
      $assess_record->setAutoSave($now, $qn, $part);
    }
  }
}

// save record if needed
$assess_record->saveRecordIfNeeded();

//output JSON object
echo '{"autosave": "done"}';