<?php
require_once 'ykval-common.php';
require_once 'ykval-config.php';
require_once 'ykval-synclib.php';

$apiKey = '';

header("content-type: text/plain");

debug("Request: " . $_SERVER['QUERY_STRING']);

$conn = mysql_connect($baseParams['__YKVAL_DB_HOST__'],
		      $baseParams['__YKVAL_DB_USER__'],
		      $baseParams['__YKVAL_DB_PW__']);
if (!$conn) {
  sendResp(S_BACKEND_ERROR, $apiKey);
  exit;
}
if (!mysql_select_db($baseParams['__YKVAL_DB_NAME__'], $conn)) {
  sendResp(S_BACKEND_ERROR, $apiKey);
  exit;
}

//// Extract values from HTTP request
//
$h = getHttpVal('h', '');
$client = getHttpVal('id', 0);
$otp = getHttpVal('otp', '');
$otp = strtolower($otp);
$timestamp = getHttpVal('timestamp', 0);

//// Get Client info from DB
//
if ($client <= 0) {
  debug('Client ID is missing');
  sendResp(S_MISSING_PARAMETER, $apiKey);
  exit;
}

$cd = getClientData($conn, $client);
if ($cd == null) {
  debug('Invalid client id ' . $client);
  sendResp(S_NO_SUCH_CLIENT);
  exit;
}
debug("Client data:", $cd);

//// Check client signature
//
$apiKey = base64_decode($cd['secret']);

if ($h != '') {
  // Create the signature using the API key
  $a = array ();
  $a['id'] = $client;
  $a['otp'] = $otp;
  // include timestamp in signature if it exists
  if ($timestamp) $a['timestamp'] = $timestamp;
  $hmac = sign($a, $apiKey);

  // Compare it
  if ($hmac != $h) {
    debug('client hmac=' . $h . ', server hmac=' . $hmac);
    sendResp(S_BAD_SIGNATURE, $apiKey);
    exit;
  }
}

//// Sanity check OTP
//
if ($otp == '') {
  debug('OTP is missing');
  sendResp(S_MISSING_PARAMETER, $apiKey);
  exit;
}
if (strlen($otp) <= TOKEN_LEN) {
  debug('Too short OTP: ' . $otp);
  sendResp(S_BAD_OTP, $apiKey);
  exit;
}

//// Which YK-KSM should we talk to?
//
$urls = otp2ksmurls ($otp, $client);
if (!is_array($urls)) {
  sendResp(S_BACKEND_ERROR, $apiKey);
  exit;
}

//// Decode OTP from input
//
$otpinfo = KSMdecryptOTP($urls);
if (!is_array($otpinfo)) {
  sendResp(S_BAD_OTP, $apiKey);
  exit;
}
debug("Decrypted OTP:", $otpinfo);

//// Get Yubikey from DB
//
$devId = substr($otp, 0, strlen ($otp) - TOKEN_LEN);
$ad = getAuthData($conn, $devId);
if (!is_array($ad)) {
  debug('Discovered Yubikey ' . $devId);
  addNewKey($conn, $devId);
  $ad = getAuthData($conn, $devId);
  if (!is_array($ad)) {
    debug('Invalid Yubikey ' . $devId);
    sendResp(S_BACKEND_ERROR, $apiKey);
    exit;
  }
}
debug("Auth data:", $ad);
if ($ad['active'] != 1) {
  debug('De-activated Yubikey ' . $devId);
  sendResp(S_BAD_OTP, $apiKey);
  exit;
}

//// Check the session counter
//
$sessionCounter = $otpinfo["session_counter"]; // From the req
$seenSessionCounter = $ad['counter']; // From DB
if ($sessionCounter < $seenSessionCounter) {
  debug("Replay, session counter, seen=" . $seenSessionCounter .
	" this=" . $sessionCounter);
  sendResp(S_REPLAYED_OTP, $apiKey);
  exit;
}

//// Check the session use
//
$sessionUse = $otpinfo["session_use"]; // From the req
$seenSessionUse = $ad['sessionUse']; // From DB
if ($sessionCounter == $seenSessionCounter && $sessionUse <= $seenSessionUse) {
  debug("Replay, session use, seen=" . $seenSessionUse .
	' this=' . $sessionUse);
  sendResp(S_REPLAYED_OTP, $apiKey);
  exit;
}

//// Valid OTP, update database
//
$stmt = 'UPDATE yubikeys SET accessed=NOW()' .
  ', counter=' .$otpinfo['session_counter'] .
  ', sessionUse=' . $otpinfo['session_use'] .
  ', low=' . $otpinfo['low'] .
  ', high=' . $otpinfo['high'] .
  ' WHERE id=' . $ad['id'];
$r=query($conn, $stmt);

$stmt = 'SELECT accessed FROM yubikeys WHERE id=' . $ad['id'];
$r=query($conn, $stmt);
if (mysql_num_rows($r) > 0) {
  $row = mysql_fetch_assoc($r);
  mysql_free_result($r);
  $modified=DbTimeToUnix($row['accessed']);
 }  
 else {
   $modified=0;
 }

//// Queue sync requests
$sl = new SyncLib();
// We need the modifed value from the DB
$stmp = 'SELECT accessed FROM yubikeys WHERE id=' . $ad['id'];
query($conn, $stmt);
$sl->queue($modified, 
	   $otp, 
	   $devId, 
	   $otpinfo['session_counter'], 
	   $otpinfo['session_use'], 
	   $otpinfo['high'], 
	   $otpinfo['low']);
$required_answers=$sl->getNumberOfServers();
$syncres=$sl->sync($required_answers);
$answers=$sl->getNumberOfAnswers();
$valid_answers=$sl->getNumberOfValidAnswers();

debug("ykval-verify:notice:number of servers=" . $required_answers);
debug("ykval-verify:notice:number of answers=" . $answers);
debug("ykval-verify:notice:number of valid answers=" . $valid_answers);
if($syncres==False) {
# sync returned false, indicating that 
# either at least 1 answer marked OTP as invalid or
# there were not enough answers
  debug("ykval-verify:notice:Sync failed");
  if ($valid_answers!=$answers) {
    sendResp(S_REPLAYED_OTP, $apiKey);
    exit;
  } else {
    sendResp(S_NOT_ENOUGH_ANSWERS, $apiKey);
    exit;
  }
 }

//// Check the time stamp
//
if ($sessionCounter == $seenSessionCounter && $sessionUse > $seenSessionUse) {
  $ts = ($otpinfo['high'] << 16) + $otpinfo['low'];
  $seenTs = ($ad['high'] << 16) + $ad['low'];
  $tsDiff = $ts - $seenTs;
  $tsDelta = $tsDiff * TS_SEC;

  //// Check the real time
  //
  $lastTime = strtotime($ad['accessed']);
  $now = time();
  $elapsed = $now - $lastTime;
  $deviation = abs($elapsed - $tsDelta);

  // Time delta server might verify multiple OTPS in a row. In such case validation server doesn't 
  // have time to tick a whole second and we need to avoid division by zero. 
  if ($elapsed != 0) {
    $percent = $deviation/$elapsed;
  } else {
    $percent = 1;
  }
  debug("Timestamp seen=" . $seenTs . " this=" . $ts .
	" delta=" . $tsDiff . ' secs=' . $tsDelta .
	' accessed=' . $lastTime .' (' . $ad['accessed'] . ') now='
	. $now . ' (' . strftime("%Y-%m-%d %H:%M:%S", $now)
	. ') elapsed=' . $elapsed .
	' deviation=' . $deviation . ' secs or '.
	round(100*$percent) . '%');
  if ($deviation > TS_ABS_TOLERANCE && $percent > TS_REL_TOLERANCE) {
    debug("OTP failed phishing test");
    if (0) {
      sendResp(S_DELAYED_OTP, $apiKey);
      exit;
    }
  }
}

if ($timestamp==1){
  $extra['timestamp'] = ($otpinfo['high'] << 16) + $otpinfo['low'];
  $extra['sessioncounter'] = $sessionCounter;
  $extra['sessionuse'] = $sessionUse;
  sendResp(S_OK, $apiKey, $extra);
 } else {
  sendResp(S_OK, $apiKey);
 }  
?>
