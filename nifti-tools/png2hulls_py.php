<?php
require_once('../shared-lib/callPython.php');

$request = getServerRequest();
$rpc = callPython(__DIR__.DIRECTORY_SEPARATOR.'png2hulls.py',$request,0,TRUE);
echo json_encode($rpc);
?>
