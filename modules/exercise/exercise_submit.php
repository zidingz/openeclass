<?php

/* ========================================================================
 * Open eClass 4.0
 * E-learning and Course Management System
 * ========================================================================
 * Copyright 2003-2020  Greek Universities Network - GUnet
 * A full copyright notice can be read in "/info/copyright.txt".
 * For a full list of contributors, see "credits.txt".
 *
 * Open eClass is an open platform distributed in the hope that it will
 * be useful (without any warranty), under the terms of the GNU (General
 * Public License) as published by the Free Software Foundation.
 * The full license can be read in "/info/license/license_gpl.txt".
 *
 * Contact address: GUnet Asynchronous eLearning Group,
 *                  Network Operations Center, University of Athens,
 *                  Panepistimiopolis Ilissia, 15784, Athens, Greece
 *                  e-mail: info@openeclass.org
 * ======================================================================== */

include 'exercise.class.php';
include 'question.class.php';
include 'answer.class.php';
include 'exercise.lib.php';

$require_current_course = true;
$guest_allowed = true;

include '../../include/baseTheme.php';
require_once 'include/lib/textLib.inc.php';
require_once 'modules/gradebook/functions.php';
require_once 'modules/attendance/functions.php';
require_once 'modules/group/group_functions.php';
require_once 'game.php';
require_once 'analytics.php';

$pageName = $langExercicesView;
$picturePath = "courses/$course_code/image";

require_once 'include/lib/modalboxhelper.class.php';
require_once 'include/lib/multimediahelper.class.php';
ModalBoxHelper::loadModalBox();


if (!add_units_navigation()) {
    $navigation[] = array("url" => "index.php?course=$course_code", "name" => $langExercices);
}

function unset_exercise_var($exerciseId) {
    global $attempt_value;
    unset($_SESSION['objExercise'][$exerciseId]);
    unset($_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value]);
    unset($_SESSION['exerciseResult'][$exerciseId][$attempt_value]);
    unset($_SESSION['questionList'][$exerciseId][$attempt_value]);
    unset($_SESSION['password'][$exerciseId][$attempt_value]);
}
// setting a cookie in OnBeforeUnload event in order to redirect user to the exercises page in case of refresh
// as the synchronous ajax call in onUnload event doen't work the same in all browsers in case of refresh
// (It is executed after page load in Chrome and Mozilla and before page load in IE).
// In current functionality if user leaves the exercise for another module the cookie will expire anyway in 30 seconds
// or it will be unset by the exercises page (index.php). If user who left an exercise for another module
// visits through a direct link a specific execise page before the 30 seconds time frame
// he will be redirected to the exercises page (index.php)
if (isset($_COOKIE['inExercise'])) {
    setcookie("inExercise", "", time() - 3600);
    redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
}

// Identifying ajax request that cancels an active attempt
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        if (isset($_POST['action']) and $_POST['action'] == 'refreshSession') {
            // Does nothing just refreshes the session
            exit();
        }
        if (isset($_POST['action']) and $_POST['action'] == 'endExerciseNoSubmit') {

            $exerciseId = $_POST['eid'];
            $record_end_date = date('Y-m-d H:i:s', time());
            $eurid = $_POST['eurid'];
            Database::get()->query("UPDATE exercise_user_record SET record_end_date = ?t, attempt_status = ?d, secs_remaining = ?d
                    WHERE eurid = ?d", $record_end_date, ATTEMPT_CANCELED, 0, $eurid);
            Database::get()->query("DELETE FROM exercise_answer_record WHERE eurid = ?d", $eurid);
            unset_exercise_var($exerciseId);
            exit();
        }
}

// Check if an exercise ID exists in the URL
// and if so it gets the exercise object either by the session (if it exists there)
// or by initializing it using the exercise ID
if (isset($_REQUEST['exerciseId'])) {
    $exerciseId = intval($_REQUEST['exerciseId']);
    // Check if exercise object exists in session
    if (isset($_SESSION['objExercise'][$exerciseId])) {
        $objExercise = $_SESSION['objExercise'][$exerciseId];
    } else {
        // construction of Exercise
        $objExercise = new Exercise();
        // if the specified exercise is disabled (this only applies to students)
        // or doesn't exist, redirect and show error
        if (!$objExercise->read($exerciseId) || (!$is_editor && $objExercise->selectStatus($exerciseId)==0)) {
            session::Messages($langExerciseNotFound);
            if (isset($_REQUEST['unit'])) {
                redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
            } else {
                redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
            }
        }
        // saves the object into the session
        $_SESSION['objExercise'][$exerciseId] = $objExercise;
    }
} else {
    redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
}

// If the exercise is assigned to specific users / groups
if ($objExercise->assign_to_specific and !$is_editor) {
    $assignees = Database::get()->queryArray('SELECT user_id, group_id
        FROM exercise_to_specific WHERE exercise_id = ?d', $exerciseId);
    $accessible = false;
    foreach ($assignees as $item) {
        if ($item->user_id == $uid) {
            $accessible = true;
            break;
        } elseif ($item->group_id) {
            if (!isset($groups)) {
                $groups = user_group_info($uid, $course_id);
            }
            if (isset($groups[$item->group_id])) {
                $accessible = true;
                break;
            }
        }
    }
    if (!$accessible) {
        Session::Messages($langNoAccessPrivilages);
        if (isset($_REQUEST['unit'])) {
            redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
        } else {
            redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
        }
    }
}

// Initialize attempts timestamp
if (isset($_POST['attempt_value']) && !isset($_GET['eurId'])) {
    $attempt_value = $_POST['attempt_value'];
} elseif (isset($_GET['eurId'])) { // reinitialize paused attempt
    // If there is a paused attempt get it
    $eurid = $_GET['eurId'];
    $paused_attempt = Database::get()->querySingle("SELECT eurid, record_start_date, secs_remaining FROM exercise_user_record WHERE eurid = ?d AND eid = ?d AND attempt_status = ?d AND uid = ?d", $eurid, $exerciseId, ATTEMPT_PAUSED, $uid);
    if ($paused_attempt) {
        $objDateTime = new DateTime($paused_attempt->record_start_date);
        $attempt_value = $objDateTime->getTimestamp();
    } else {
        if (isset($_REQUEST['unit'])) {
            redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
        } else {
            redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
        }
    }
} else {
    $objDateTime = new DateTime('NOW');
    $attempt_value = $objDateTime->getTimestamp();
}

if (!isset($_POST['acceptAttempt']) and (!isset($_POST['formSent']))) {
    // If the exercise is password protected
    $password = $objExercise->selectPasswordLock();
    if ($password && !$is_editor) {
        if(!isset($_SESSION['password'][$exerciseId][$attempt_value])) {
            if (isset($_POST['password']) && $password === $_POST['password']) {
                $_SESSION['password'][$exerciseId][$attempt_value] = 1;
            } else {
                Session::Messages($langCaptchaWrong);
                if (isset($_REQUEST['unit'])) {
                    redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
                } else {
                    redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
                }
            }
        }
    }
}
// If the exercise is IP protected
$ips = $objExercise->selectIPLock();
if ($ips && !$is_editor){
    $user_ip = Log::get_client_ip();
    if(!match_ip_to_ip_or_cidr($user_ip, explode(',', $ips))){
        Session::Messages($langIPHasNoAccess);
        if (isset($_REQUEST['unit'])) {
            redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
        } else {
            redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
        }
    }
}
// If the user has clicked on the "Cancel" button,
// end the exercise and return to the exercise list
if (isset($_POST['buttonCancel'])) {

    $eurid = $_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value];
    $exercisetotalweight = $objExercise->selectTotalWeighting();
    Database::get()->query("UPDATE exercise_user_record SET record_end_date = NOW(), attempt_status = ?d, total_score = 0, total_weighting = ?d
        WHERE eurid = ?d", ATTEMPT_CANCELED, $exercisetotalweight, $eurid);
    Database::get()->query("DELETE FROM exercise_answer_record WHERE eurid = ?d", $eurid);
    unset_exercise_var($exerciseId);
    Session::Messages($langAttemptWasCanceled);
    if (isset($_REQUEST['unit'])) {
        redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
    } else {
        redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
    }
}

load_js('tools.js');

$exerciseTitle = $objExercise->selectTitle();
$exerciseDescription = $objExercise->selectDescription();
$randomQuestions = $objExercise->isRandom();
$exerciseType = $objExercise->selectType();
$exerciseTempSave = $objExercise->selectTempSave();
$exerciseTimeConstraint = (int) $objExercise->selectTimeConstraint();
$exerciseAllowedAttempts = $objExercise->selectAttemptsAllowed();
$exercisetotalweight = $objExercise->selectTotalWeighting();

$temp_CurrentDate = $recordStartDate = time();
$exercise_StartDate = new DateTime($objExercise->selectStartDate());
$exercise_EndDate = $objExercise->selectEndDate();
$exercise_EndDate = isset($exercise_EndDate) ? new DateTime($objExercise->selectEndDate()) : $exercise_EndDate;
$choice = isset($_POST['choice']) ? $_POST['choice'] : '';

// If there are answers in the session get them
if (isset($_SESSION['exerciseResult'][$exerciseId][$attempt_value])) {
    $exerciseResult = $_SESSION['exerciseResult'][$exerciseId][$attempt_value];
} else {
    if (isset($paused_attempt)) {
        $exerciseResult = $_SESSION['exerciseResult'][$exerciseId][$attempt_value] = $objExercise->get_attempt_results_array($eurid);
    } else {
        $exerciseResult = array();
    }
}

// exercise has ended or hasn't been enabled yet due to declared dates or was submitted automatically due to expiring time
$autoSubmit = isset($_POST['autoSubmit']) && $_POST['autoSubmit'] == 'true';
if ($temp_CurrentDate < $exercise_StartDate->getTimestamp() or (isset($exercise_EndDate) && ($temp_CurrentDate >= $exercise_EndDate->getTimestamp())) or $autoSubmit) {
    // if that happens during an active attempt
    if (isset($_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value])) {
        $eurid = $_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value];
        $record_end_date = date('Y-m-d H:i:s', time());
        $objExercise->save_unanswered();
        $objExercise->record_answers($choice, $exerciseResult, 'update');
        $totalScore = Database::get()->querySingle("SELECT SUM(weight) AS weight FROM exercise_answer_record WHERE eurid = ?d", $eurid)->weight;
        if ($objExercise->isRandom()) {
            $totalWeighting = Database::get()->querySingle("SELECT SUM(weight) AS weight FROM exercise_question WHERE id IN (
                                          SELECT question_id FROM exercise_answer_record WHERE eurid = ?d)", $eurid)->weight;
        } else {
            $totalWeighting = $objExercise->selectTotalWeighting();
        }
        $unmarked_free_text_nbr = Database::get()->querySingle("SELECT count(*) AS count FROM exercise_answer_record WHERE weight IS NULL AND eurid = ?d", $eurid)->count;
        $attempt_status = ($unmarked_free_text_nbr > 0) ? ATTEMPT_PENDING : ATTEMPT_COMPLETED;
        Database::get()->query("UPDATE exercise_user_record SET record_end_date = ?t, total_score = ?f, attempt_status = ?d,
                        total_weighting = ?f WHERE eurid = ?d", $record_end_date, $totalScore, $attempt_status, $totalWeighting, $eurid);
        unset_exercise_var($exerciseId);
        Session::Messages($langExerciseExpiredTime);
        if (isset($_REQUEST['unit'])) {
            redirect_to_home_page('modules/units/view.php?course='.$course_code.'&eurId='.$eurid.'&res_type=exercise_results&unit='.$_REQUEST['unit']);
        } else {
            redirect_to_home_page('modules/exercise/exercise_result.php?course='.$course_code.'&eurId='.$eurid);
        }
    } else {
        unset_exercise_var($exerciseId);
        Session::Messages($langExerciseExpired);
        if (isset($_REQUEST['unit'])) {
            redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
        } else {
            redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
        }
    }
}


// If question list exists in the Session get it for there
// else get it using the appropriate object method and save it to the session
if (isset($_SESSION['questionList'][$exerciseId][$attempt_value])) {
    $questionList = $_SESSION['questionList'][$exerciseId][$attempt_value];
} else {
    if (isset($paused_attempt)) {
        $record_question_ids = Database::get()->queryArray("SELECT question_id FROM exercise_answer_record WHERE eurid = ?d GROUP BY question_id, q_position ORDER BY q_position", $paused_attempt->eurid);
        $i = 1;
        foreach ($record_question_ids as $row) {
            $questionList[$i] = $row->question_id;
            $i++;
        }
    } else {
        // selects the list of question ID
        $questionList = $randomQuestions ? $objExercise->selectRandomList() : $objExercise->selectQuestionList();
    }
    // saves the question list into the session if there are questions
    if (count($questionList)) {
        $_SESSION['questionList'][$exerciseId][$attempt_value] = $questionList;
    } else {
        unset_exercise_var($exerciseId);
    }
}

$nbrQuestions = count($questionList);


// determine begin time:
// either from a previews attempt meaning that user hasn't submitted his answers permanently
// and exerciseTimeConstrain hasn't yet passed,
// either start a new attempt and count now() as begin time.

if (isset($_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value]) || isset($paused_attempt)) {

    $eurid = isset($paused_attempt) ? $_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value] = $paused_attempt->eurid : $_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value];
    $recordStartDate = Database::get()->querySingle("SELECT record_start_date FROM exercise_user_record WHERE eurid = ?d", $eurid)->record_start_date;
    $recordStartDate = strtotime($recordStartDate);
    // if exerciseTimeConstrain has not passed yet calculate the remaining time
    if ($exerciseTimeConstraint > 0) {
        $timeleft = isset($paused_attempt) ? $paused_attempt->secs_remaining : ($exerciseTimeConstraint * 60) - ($temp_CurrentDate - $recordStartDate);
    }
} elseif (!isset($_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value]) && $nbrQuestions > 0) {
    $attempt = Database::get()->querySingle("SELECT COUNT(*) AS count FROM exercise_user_record WHERE eid = ?d AND uid= ?d", $exerciseId, $uid)->count;

    // Check if allowed number of attempts exceeded and if so redirect
   if ($exerciseAllowedAttempts > 0 && $attempt >= $exerciseAllowedAttempts) {
        unset_exercise_var($exerciseId);
        Session::Messages($langExerciseMaxAttemptsReached);
       if (isset($_REQUEST['unit'])) {
           redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
       } else {
           redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
       }
   } else {
        if ($exerciseAllowedAttempts > 0 && !isset($_POST['acceptAttempt'])) {
            $left_attempts = $exerciseAllowedAttempts - $attempt;
            if (isset($_REQUEST['unit'])) {
                $form_next_link = "{$urlServer}modules/units/view.php?course=$course_code&res_type=exercise&exerciseId=$exerciseId&unit=$_REQUEST[unit]";
                $form_cancel_link = "{$urlServer}modules/units/index.php?course=$course_code&id=$_REQUEST[unit]";
            } else {
                $form_next_link = "{$urlServer}modules/exercise/exercise_submit.php?course=$course_code&exerciseId=$exerciseId";
                $form_cancel_link = "{$urlServer}modules/exercise/index.php?course=$course_code";
            }
            $tool_content .= "<div class='alert alert-warning text-center'>" .
                ($left_attempts == 1? $langExerciseAttemptLeft: sprintf($langExerciseAttemptsLeft, $left_attempts)) .
                ' ' . $langExerciseAttemptContinue . "</div>
                <div class='text-center'>
                    <form action='$form_next_link' method='post'>
                        <input class='btn btn-primary' id='submit' type='submit' name='acceptAttempt' value='$langContinue'>
                        <a href='$form_cancel_link' class='btn btn-default'>$langCancel</a>
                    </form>
                </div>";
            unset_exercise_var($exerciseId);
            draw($tool_content, 2, null, $head_content);
            exit;
         }
        // count this as an attempt by saving it as an incomplete record, if there are any available attempts left
        $start = date('Y-m-d H:i:s', $attempt_value);
        if ($exerciseTimeConstraint) {
            $timeleft = $exerciseTimeConstraint * 60;
        }
        $eurid = Database::get()->query("INSERT INTO exercise_user_record (eid, uid, record_start_date, total_score, total_weighting, attempt, attempt_status)
                        VALUES (?d, ?d, ?t, 0, 0, ?d, 0)", $exerciseId, $uid, $start, $attempt + 1)->lastInsertID;
        $_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value] = $eurid;
   }
}
if ($exercise_EndDate) {
    $exerciseTimeLeft = $exercise_EndDate->getTimestamp() - $temp_CurrentDate;
    if ($exerciseTimeLeft < 3 * 3600) {
            if ((isset($timeleft) and $exerciseTimeLeft < $timeleft) or !isset($timeleft)) {
            // Display countdown of exercise remaining time if less than
            // user's remaining time or less than 3 hours away
            $timeleft = $exerciseTimeLeft;
        }
    }
}
$questionNum = count($exerciseResult) + 1;
// if the user has submitted the form
if (isset($_POST['formSent'])) {
    $time_expired = false;
    // checking if user's time expired
    if (isset($timeleft)) {
        $timeleft += 1; // Add 1 sec for leniency when submitting
        if ($timeleft < 0) {
            $time_expired = true;
        }
    }

    // inserts user's answers in the database and adds them in the $exerciseResult array which is returned
    $action = isset($paused_attempt) ? 'update' : 'insert';
    $exerciseResult = $objExercise->record_answers($choice, $exerciseResult, $action);
    $questionNum = count($exerciseResult) + 1;

    $_SESSION['exerciseResult'][$exerciseId][$attempt_value] = $exerciseResult;

    // if it is a non-sequential exercise OR
    // if it is a sequential exercise in the last question OR the time has expired
    if ($exerciseType == SINGLE_PAGE_TYPE && !isset($_POST['buttonSave']) ||
        $exerciseType == MULTIPLE_PAGE_TYPE && (isset($_POST['buttonFinish']) || $time_expired)) {
        if (isset($_POST['secsRemaining'])) {
            $secs_remaining = $_POST['secsRemaining'];
        } else {
            $secs_remaining = 0;
        }
        $eurid = $_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value];
        $record_end_date = date('Y-m-d H:i:s', time());
        $totalScore = Database::get()->querySingle("SELECT SUM(weight) AS weight FROM exercise_answer_record WHERE eurid = ?d", $eurid)->weight;
        if (empty($totalScore) or ($totalScore < 0)) {
            $totalScore = 0.00;
        }

        if ($objExercise->isRandom()) {
            $totalWeighting = Database::get()->querySingle("SELECT SUM(weight) AS weight FROM exercise_question WHERE id IN (
                                          SELECT question_id FROM exercise_answer_record WHERE eurid = ?d)", $eurid)->weight;
        } else {
            $totalWeighting = $objExercise->selectTotalWeighting();
        }

        // In sequential exercise we must add to the DB the non-given answers
        // to questions the student didn't answered
        if ($exerciseType == MULTIPLE_PAGE_TYPE) {
            $objExercise->save_unanswered();
        }
        $unmarked_free_text_nbr = Database::get()->querySingle("SELECT count(*) AS count FROM exercise_answer_record WHERE weight IS NULL AND eurid = ?d", $eurid)->count;
        $attempt_status = ($unmarked_free_text_nbr > 0) ? ATTEMPT_PENDING : ATTEMPT_COMPLETED;
        // record results of exercise
        Database::get()->query("UPDATE exercise_user_record SET record_end_date = ?t, total_score = ?f, attempt_status = ?d,
                                total_weighting = ?f, secs_remaining = ?d WHERE eurid = ?d", $record_end_date, $totalScore, $attempt_status, $totalWeighting, $secs_remaining, $eurid);

        if ($attempt_status == ATTEMPT_COMPLETED) {
            // update attendance book
            update_attendance_book($uid, $exerciseId, GRADEBOOK_ACTIVITY_EXERCISE);
            // update gradebook
            update_gradebook_book($uid, $exerciseId, $totalScore/$totalWeighting, GRADEBOOK_ACTIVITY_EXERCISE);
            // update user progress
            triggerGame($course_id, $uid, $exerciseId);
            triggerExerciseAnalytics($course_id, $uid, $exerciseId);
        }
        unset($objExercise);
        unset_exercise_var($exerciseId);
        // if time expired set flashdata
        if ($time_expired) {
            Session::Messages($langExerciseExpiredTime);
        } else {
            Session::Messages($langExerciseCompleted, 'alert-success');
        }
        if (isset($_REQUEST['unit'])) {
            redirect_to_home_page('modules/units/view.php?course='.$course_code.'&eurId='.$eurid.'&res_type=exercise_results&unit='.$_REQUEST['unit']);
        } else {
            redirect_to_home_page('modules/exercise/exercise_result.php?course='.$course_code.'&eurId='.$eurid);
        }
    }
    // if the user has clicked on the "Save & Exit" button
    // keeps the exercise in a pending/uncompleted state and returns to the exercise list
    if (isset($_POST['buttonSave']) && $exerciseTempSave) {
        $eurid = $_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value];
        $secs_remaining = isset($timeleft) ? $timeleft : 0;
        $totalScore = Database::get()->querySingle("SELECT SUM(weight) AS weight FROM exercise_answer_record WHERE eurid = ?d", $eurid)->weight;
        if ($objExercise->isRandom()) {
            $totalWeighting = Database::get()->querySingle("SELECT SUM(weight) AS weight FROM exercise_question WHERE id IN (
                                          SELECT question_id FROM exercise_answer_record WHERE eurid = ?d)", $eurid)->weight;
        } else {
            $totalWeighting = $objExercise->selectTotalWeighting();
        }
        // If we are currently in a previously paused attempt (so this is not
        // the first pause), unanswered are already saved in the DB and they
        // only need an update
        if (!isset($paused_attempt)) {
            $objExercise->save_unanswered(0); // passing 0 to save like unanswered
        }

        Database::get()->query("UPDATE exercise_user_record SET record_end_date = NOW(), total_score = ?f, total_weighting = ?f, attempt_status = ?d, secs_remaining = ?d
                WHERE eurid = ?d", floatval($totalScore), floatval($totalWeighting), ATTEMPT_PAUSED, $secs_remaining, $eurid);
        if ($exerciseType == MULTIPLE_PAGE_TYPE and isset($_POST['choice']) and is_array($_POST['choice'])) {
            // for sequential exercises, return to current question
            // by setting is_answered to a special value
            $qid = array_keys($_POST['choice']);
            Database::get()->query('UPDATE exercise_answer_record SET is_answered = 2
                WHERE eurid = ?d AND question_id = ?d', $eurid, $qid);
        }
        unset_exercise_var($exerciseId);
        if (isset($_REQUEST['unit'])) {
            redirect_to_home_page('modules/units/index.php?course='.$course_code.'&id='.$_REQUEST['unit']);
        } else {
            redirect_to_home_page('modules/exercise/index.php?course='.$course_code);
        }

    }
}

if (isset($timeleft)) {
    // Submit 10 sec earlier to account for delays when submitting etc.
    $timeleft -= 10;
    if ($timeleft <= 1) {
        $timeleft = 1;
    }
}
$exerciseDescription_temp = standard_text_escape($exerciseDescription);
$tool_content .= "<div class='panel panel-primary'>
  <div class='panel-heading'>
    <h3 class='panel-title'>" .
    (isset($timeleft)?
        "<div class='pull-right'>$langRemainingTime: <span id='progresstime'>$timeleft</span></div>" : '') .
      q_math($exerciseTitle) . "</h3>
  </div>";
if (!empty($exerciseDescription_temp)) {
    $tool_content .= "<div class='panel-body'>
        $exerciseDescription_temp
      </div>";
}
$tool_content .= "</div>";

if (isset($_REQUEST['unit'])) {
    $form_action_link = "{$urlServer}modules/units/view.php?res_type=exercise&amp;unit=$_REQUEST[unit]&amp;course=$course_code&amp;exerciseId=$exerciseId".(isset($paused_attempt) ? "&amp;eurId=$eurid" : "")."";
} else {
    $form_action_link = "$_SERVER[SCRIPT_NAME]?course=$course_code&amp;exerciseId=$exerciseId".(isset($paused_attempt) ? "&amp;eurId=$eurid" : "")."";
}

$tool_content .= "
  <form class='form-horizontal exercise' role='form' method='post' action='$form_action_link' autocomplete='off'>
  <input type='hidden' name='formSent' value='1'>
  <input type='hidden' name='attempt_value' value='$attempt_value'>
  <input type='hidden' name='nbrQuestions' value='$nbrQuestions'>";

if (isset($timeleft) && $timeleft > 0) {
  $tool_content .= "<input type='hidden' name='secsRemaining' id='secsRemaining' value='$timeleft' />";
}

$unansweredIds = $answeredIds = array();
$savedQuestion = null;

if ($exerciseType == MULTIPLE_PAGE_TYPE) {
    $eurid = $_SESSION['exerciseUserRecordID'][$exerciseId][$attempt_value];
    $r = Database::get()->querySingle('SELECT question_id FROM exercise_answer_record
        WHERE eurid = ?d AND is_answered = 2 LIMIT 1', $eurid);
    if ($r) {
        $savedQuestion = $r->question_id;
        Database::get()->query('UPDATE exercise_answer_record SET is_answered = 1
            WHERE eurid = ?d AND is_answered = 2', $eurid);
    }
}

if ($exerciseType == SINGLE_PAGE_TYPE) { // // display question numbering buttons
    $tool_content .= "<div style='margin-bottom: 20px;'>";
    $q_num = 0;
    foreach ($questionList as $q_id) {
        $q_num++;
        $q_temp = new Question();
        $q_temp->read($q_id);
        if (($q_temp->selectType() == UNIQUE_ANSWER or $q_temp->selectType() == MULTIPLE_ANSWER or $q_temp->selectType() == TRUE_FALSE)
            and array_key_exists($q_id, $exerciseResult) and $exerciseResult[$q_id] != 0) { // if question has answered button color is `blue``
            $class = 'btn btn-info';
            $label = $langHasAnswered;
        } elseif (($q_temp->selectType() == FILL_IN_BLANKS or $q_temp->selectType() == FILL_IN_BLANKS_TOLERANT)
            and array_key_exists($q_id, $exerciseResult)) {
            if (is_array($exerciseResult[$q_id])) {
                $class = 'btn btn-info';
                $label = $langHasAnswered;
                foreach ($exerciseResult[$q_id] as $key => $value) {
                    if (trim($value) == '') {  // check if we have filled all blanks
                        $class = 'btn btn-default';
                        $label = $langPendingAnswered;
                        break;
                    }
                }
            }
        } elseif ($q_temp->selectType() == FREE_TEXT
            and array_key_exists($q_id, $exerciseResult) and trim($exerciseResult[$q_id]) !== '') { // button color is `blue` if we have type anything
            $class = 'btn btn-info';
            $label = $langHasAnswered;
        } elseif ($q_temp->selectType() == MATCHING and array_key_exists($q_id, $exerciseResult)) {
            if (is_array($exerciseResult[$q_id])) {
                $class = 'btn btn-info';
                $label = $langHasAnswered;
                foreach ($exerciseResult[$q_id] as $key => $value) {
                    if ($value == 0) {  // check if we have done all matches
                        $class = 'btn btn-default';
                        $label = $langPendingAnswered;
                        break;
                    }
                }
            }
        } else {
            $class = 'btn btn-default';
            $label = $langPendingAnswered;
        }
        $tool_content .= "<span style='display: inline-block; margin-right: 10px; margin-bottom: 15px;' data-toggle='tooltip' data-placement='top' title='$label'>" .
                             "<a href='#qPanel$q_id' class='$class qNavButton' id='q_num$q_num'>$q_num</a>" .
                         "</span>";
    }
    $tool_content .= "</div>";
}


$i = 0;

foreach ($questionList as $questionId) {
    $i++;

    if (isset($_REQUEST['q_id'])) { // we come from pagination buttons
        $current_question_number = $_REQUEST['q_id']; // only number
        $questionId = $questionList[$current_question_number];
    } else if (isset($_REQUEST['questionId'])) { // we come from prev / next buttons
        if (isset($_REQUEST['prev'])) { // previous
            $current_question_number = array_search($_REQUEST['questionId'], $questionList)-1;
            $questionId = $questionList[$current_question_number];
        } else { // next
            $current_question_number = array_search($_REQUEST['questionId'], $questionList)+1;
            $questionId = $questionList[$current_question_number];
        }
    } else { // first time (default)
        $current_question_number = array_search($questionId, $questionList);
    }

    // check if question is actually answered
    $question = new Question();
    $question->read($questionId);

    if ($exerciseType == MULTIPLE_PAGE_TYPE) {
        // display question numbering buttons
        $tool_content .= "<div style='margin-bottom: 20px;'>";
        foreach ($questionList as $k => $q_id) {
            $answered = false;
            $q_id = $questionList[$k];
            $t_question = new Question();
            $t_question->read($q_id);
            $tool_content .= "<span style='display: inline-block; margin-right: 10px; margin-bottom: 15px;'>";
            if ($current_question_number == $k) { // we are in the current question
                $round_border = "border-radius: 70%;";
            } else {
                $round_border = '';
            }
            if (($t_question->selectType() == UNIQUE_ANSWER or $t_question->selectType() == MULTIPLE_ANSWER or $t_question->selectType() == TRUE_FALSE)
                and array_key_exists($q_id, $exerciseResult) and $exerciseResult[$q_id] != 0) { // if question has answered button color is `blue``
                $tool_content .= "<input class='btn btn-info' style='$round_border' type='submit' name='q_id' value='$k' data-toggle='tooltip' data-placement='top' title='$langHasAnswered'>";
                $answered = true;
            } elseif (($t_question->selectType() == FILL_IN_BLANKS or $t_question->selectType() == FILL_IN_BLANKS_TOLERANT)
                and array_key_exists($q_id, $exerciseResult)) {
                if (is_array($exerciseResult[$q_id])) {
                    $class = 'btn btn-info';
                    $label = $langHasAnswered;
                    $answered = true;
                    foreach ($exerciseResult[$q_id] as $key => $value) {
                        if (trim($value) == '') {  // check if we have filled all blanks
                            $class = 'btn btn-default';
                            $label = $langPendingAnswered;
                            $answered = false;
                            break;
                        }
                    }
                    $tool_content .= "<input class='$class' style='$round_border' type='submit' name='q_id' value='$k' data-toggle='tooltip' data-placement='top' title='$label'>";
                }
            } elseif ($t_question->selectType() == FREE_TEXT
                and array_key_exists($q_id, $exerciseResult) and trim($exerciseResult[$q_id]) !== '') { // button color is `blue` if we have type anything
                $tool_content .= "<input class='btn btn-info' type='submit' name='q_id' value='$k' data-toggle='tooltip' data-placement='top' title='$langHasAnswered'>";
                $answered = true;
            } elseif ($t_question->selectType() == MATCHING and array_key_exists($q_id, $exerciseResult)) {
                if (is_array($exerciseResult[$q_id])) {
                    $class = 'btn btn-info';
                    $label = $langHasAnswered;
                    $answered = true;
                    foreach ($exerciseResult[$q_id] as $key => $value) {
                        if ($value == 0) {  // check if we have done all matches
                            $class = 'btn btn-default';
                            $label = $langPendingAnswered;
                            $answered = false;
                            break;
                        }
                    }
                    $tool_content .= "<input class='$class' style='$round_border' type='submit' name='q_id' value='$k' data-toggle='tooltip' data-placement='top' title='$label'>";
                }
            } else {
                $tool_content .= "<input class='btn btn-default' style='$round_border' type='submit' name='q_id' value='$k' data-toggle='tooltip' data-placement='top' title='$langPendingAnswered'>"; // button color is `gray`
            }
            $tool_content .= "</span>";
            $k++;
            if (!$answered) {
                $unansweredIds[] = $q_id;
            }
        }
        $tool_content .= "</div>";
    }

    if (isset($exerciseResult[$questionId])) {
        $type = $question->type;
        $answer = $exerciseResult[$questionId];
        if ($type == FREE_TEXT) {
            if (trim($answer) !== '') {
                $answeredIds[] = $questionId;;
            }
        } elseif ($type == TRUE_FALSE or $type == UNIQUE_ANSWER) {
            if ($answer) {
                $answeredIds[] = $questionId;
            }
        } elseif ($type == FILL_IN_BLANKS or $type == FILL_IN_BLANKS_TOLERANT) {
            if (is_array($answer)) {
                foreach ($answer as $id => $blank) {
                    if (trim($blank) !== '') {
                        $answeredIds[] = $questionId;
                        break;
                    }
                }
            }
        } elseif ($type == MULTIPLE_ANSWER) {
            if (is_array($answer)) {
                unset($answer[0]);
                if (count($answer)) {
                    $answeredIds[] = $questionId;
                }
            }
        } elseif ($type == MATCHING) {
            if (array_filter($answer)) {
                $answeredIds[] = $questionId;
            }
        }
    }

    // show the question and its answers
    showQuestion($question, $exerciseResult, $current_question_number);

    // for sequential exercises quits the loop
    if ($exerciseType == MULTIPLE_PAGE_TYPE) {
        break;
    }
} // end foreach()

$disableCheck = 0;
if (!$questionList) {
    $tool_content .= "<div class='alert alert-warning'>$langNoQuestion</div>";
    if (isset($_REQUEST['unit'])) {
        $backlink = "index.php?course=$course_code&id=$_REQUEST[unit]";
    } else {
        $backlink = "index.php?course=$course_code";
    }

    $tool_content .= "<div class='pull-right'>
        <a href='$backlink' class='btn btn-default'>$langBack</a>
    </div>";
} else {
    if ($exerciseType == SINGLE_PAGE_TYPE || $nbrQuestions == $current_question_number) {
        $submitLabel = $langSubmit;
        $buttonName = "name='buttonFinish'";
    } else {
        $submitLabel = $langNext . ' &gt;';
        $disableCheck = 1;
        $buttonName = '';
    }
    $tool_content .= "<div class='pull-right' style='margin-top: 15px;'>
        <input class='btn btn-default' type='submit' name='buttonCancel' value='$langCancel'>&nbsp;";
        if ($exerciseType == MULTIPLE_PAGE_TYPE and $questionId != $questionList[1]) { // display `previous` button
            $prevLabel = '&lt; ' . $langPrevious;
            $tool_content .= "<input class='btn btn-primary blockUI' type='submit' name='prev' value='$prevLabel' >&nbsp;";
        }
        // display submit button
        $tool_content .= "<input class='btn btn-primary blockUI' type='submit' $buttonName value='$submitLabel'>";

        if ($exerciseType == MULTIPLE_PAGE_TYPE) {
            $tool_content .= "<input type='hidden' name='questionId' value='$questionId'>";
        }

    if ($exerciseTempSave && !($exerciseType == MULTIPLE_PAGE_TYPE && ($i == $nbrQuestions))) {
        $tool_content .= "&nbsp;<input class='btn btn-primary blockUI' type='submit' name='buttonSave' value='$langTemporarySave'>";
    }
    $tool_content .= "</div>";
}
$tool_content .= "</form>";

if ($questionList) {
    $refresh_time = (ini_get("session.gc_maxlifetime") - 10 ) * 1000;
    // Enable check for unanswered questions when displaying more than one question
    $head_content .= "<script type='text/javascript'>
        $(function () {
            exercise_init_countdown({
                warning: '". js_escape($langLeaveExerciseWarning) ."',
                unansweredQuestions: '". js_escape($langUnansweredQuestions) ."',
                oneUnanswered: '". js_escape($langUnansweredQuestionsWarningOne) ."',
                manyUnanswered: '". js_escape($langUnansweredQuestionsWarningMany) ."',
                question: '". js_escape($langUnansweredQuestionsQuestion) ."',
                submit: '". js_escape($langSubmit) ."',
                goBack: '". js_escape($langGoBackToEx) ."',
                refreshTime: $refresh_time,
                exerciseId: $exerciseId,
                answeredIds: ". json_encode($answeredIds) .",
                unansweredIds: ". json_encode($unansweredIds) .",
                attemptsAllowed: $exerciseAllowedAttempts,
                eurid: $eurid,
                disableCheck: $disableCheck
            });
            $('.qNavButton').click(function (e) {
                e.preventDefault();
                var panel = $($(this).attr('href'));
                $('.qPanel').removeClass('panel-info').addClass('panel-default');
                panel.removeClass('panel-default').addClass('panel-info');
                $('html').animate({ scrollTop: ($(panel).offset().top - 20) + 'px' });
            });
        });
</script>";
}
draw($tool_content, 2, null, $head_content);
