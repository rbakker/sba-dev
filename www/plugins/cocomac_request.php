<?php
error_reporting(E_STRICT);
if ($_SERVER['HTTP_HOST'] == 'localhost') $cocomacRoot = 'http://localhost/cocomac/cocomac2/';
else $cocomacRoot = 'http://cocomac.g-node.org/cocomac2/';

function exitWithFailure($code,$msg) {
  if (headers_sent()) exit($code);
  header('Content-type: application/json; charset=utf-8');
  echo json_encode(array(
    'jsonrpc'=>'2.0',
    'error'=>array(
      'code'=>$code,
      'message'=>$msg
    )
  ));  
  exit($code);
}

if (isset($_REQUEST['originSites'])) { 
  $args[0] = $_REQUEST['originSites'];
} else { 
  exitWithFailure(2,'Undefined parameter "originSites"');
}
if (isset($_REQUEST['terminalSites'])) {
  $args[1] = $_REQUEST['originSites'];
} else {
  exitWithFailure(2,'Undefined parameter "terminalSites"');
}
$args[2] = array();
$args[3] = array('format'=>'SBA');
$href = $cocomacRoot.'public/phpRequest.php?LIB=cocomac_api&CMD=public_getAxonalProjections&ARGS='.json_encode($args);
try {
  $ans = file_get_contents($href,NULL);
} catch (Exception $e) {
  exitWithFailure(-32000,'Server error "'.$e->getMessage().'" for request '.$href);
}
if (!headers_sent()) {
  // check if the returned content is valid json
  header('Content-type: application/json; charset=utf-8');
  $validJson = @json_decode($ans,TRUE);
  if (isset($validJson)) {
    if (isset($validJson['errors'])) {
      exitWithFailure(1,array('Errors reported in response'=>$validJson['errors']));
    }
    // SUCCESS
    echo json_encode(array(
      'jsonrpc'=>'2.0',
      'result'=>$validJson
    ));
  } else {
    exitWithFailure(-32700,array('Invalid JSON in response "'.$ans.'"'));
  }
}
?>
