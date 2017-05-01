<?php
function getServerRequest() {
  $raw = array();
  if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $ok = parse_str($_SERVER['QUERY_STRING'], $raw);
  } else {
    $raw = $_REQUEST;
  }
  return $raw;
}

function pipe_exec($cmd, $input='') {
  $proc = proc_open($cmd, array(array('pipe', 'r'),array('pipe', 'w'),array('pipe', 'w')), $pipes);
  fwrite($pipes[0], $input);
  fclose($pipes[0]);

  $stdout = stream_get_contents($pipes[1]);
  fclose($pipes[1]);

  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[2]);

  $return_code = (int)proc_close($proc);
  return array($return_code, $stdout, $stderr);
}

function callPython($cmd,$request,$id=0,$jsonrpc2=FALSE,$python_command=NULL) {
  // if jsonrpc2 is TRUE then it is assumed that the python script
  // accepts the --jsonrpc2 argument, and returns json_encoded output 
  // according to the jsonrpc 2.0 standard (result or error field, id field).
  $args = array();
  foreach ($request as $k=>$v) {
    if (is_numeric($k)) $args[] = ' -'.preg_replace('[^\w\d-_]','',$v);
    else {
      $args[] = ' -'.preg_replace('[^\w\d-_]','',$k);
      $args[] = '='.escapeshellarg($v);
    }
  }
  if ($jsonrpc2) $args[] = ' --jsonrpc2';
  $cmdline = ($python_command ? $python_command : 'python').' '.escapeshellcmd($cmd).' '.implode('',$args);
  list($ec,$stdout,$stderr) = pipe_exec($cmdline);
  $error = NULL;
  $result = NULL;
  if ($jsonrpc2) {
    $response = json_decode($stdout,TRUE);
    if (isset($response)) {
      return $response; // pass on python rpc output
    } else {
      $ec = -32700;
      $stderr .= "\nInvalid json response in 'python ".$cmd."'.";
    }
  }
  if ($ec) {
    $error = array(
      'code'=>$ec,
      'message'=>"Uncaught exception in 'python ".$cmd."'.\n".$stderr,
      'data'=>array(
        'commandline'=>$cmdline,
        'stdout'=>$stdout
      )
    );
  } else {
    $result = $stdout;
  }
  $response = array(
    'jsonrpc'=>'2.0',
    'id'=>$id
  );
  if ($error) {
    $ans['error']['data']['commandline'] = $cmdline;

    $response['error'] = $error;
  }
  else $response['result'] = $result;
  return $response;
}

function validateRPC($response) {
  $result = NULL;
  if (isset($response['error'])) {
    $error = $response['error'];
    if (isset($response['message'])) {
      $message = $response['message'].'<br/>';
    } else {
      $message = 'RPC failed with error:<br/>';
    }
    $message .= htmlspecialchars(json_encode($error,JSON_PRETTY_PRINT),ENT_NOQUOTES);
    throw new Exception($message);
  } elseif (isset($response['result'])) {
    $result = $response['result'];
    // special case: result returned by FancyPipe-based program
    if (isset($result['kwargs']) && isset($result['args'])) {
      $kwargs = $result['kwargs'];
      $args = $result['args'];
      $result = $args;
      foreach ($kwargs as $k=>$v) $result[$k] = $v;
    }
  } else {
    throw new Exception('Invalid RPC result: '.json_encode($result));
  }
  return $result;
}
?>
