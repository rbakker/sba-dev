<?php
function parse_civm4($fname) {
  if (!is_file($fname)) throw new Exception('Invalid input file "'.$fname.'"');
  echo 'Parsing file '.$fname."<br/>\n";
  $handle = fopen($fname,'r');
  
  // init results
  $index2acr = array();
  $index2full = array();
  $index2parent = array();
  $index2rgb =array();
  
  $rgb2index = array();
  if ($handle) {
    $start = 0;
    // skip first line
    $s = fgets($handle, 1000);
    $nextIndex = 0;
    while (($s = fgets($handle, 1000)) !== FALSE) {
      $s = trim($s);
      if (substr($s,0,1) !== '#' && strlen($s)>0) {
        $a = split("\t",$s);
        $full  = $a[0];
        $acr   = $a[1];
        $parent3  = $a[2];
        $parent2  = $a[3];
        $parent1  = $a[4];
        $parent0  = $a[5];
        $index = $a[6];

        $index2parent[$index] = $parent0;
        $acr2parent[$parent0] = $parent1;
        $acr2parent[$parent1] = $parent2;
        $acr2parent[$parent2] = $parent3;
        $index2acr[$index] = $acr;
        #$index2rgb[$index] = $rgb;
        $index2full[$index] = $full;
        if ($index>=$nextIndex) $nextIndex = $index+1;
      }
    }
    fclose($handle);
    // deal with higher levels of hierarchy
    $acr2index = array_flip($index2acr);
    foreach ($acr2parent as $acr=>$parent) {
      if (!isset($acr2index[$acr])) {
        $index2acr[$nextIndex] = $acr;
        $index2full[$nextIndex] = $acr;
        $acr2index[$acr] = $nextIndex;
        $nextIndex++;        
      }
      if (!isset($acr2index[$parent])) {
        $index2acr[$nextIndex] = $parent;
        $index2full[$nextIndex] = $parent;
        $acr2index[$parent] = $nextIndex;
        $nextIndex++;        
      }
      $index = $acr2index[$acr];
      $index2parent[$index] = $parent;
    }
  }
  
  return array(
    'index2acr'=>$index2acr,
    'index2full'=>$index2full,
    'index2parent'=>$index2parent
  );
}

/*  
if (!debug_backtrace()) {
  header('Content-type: text/plain');
  try {
    $result = parse_...;
    echo json_encode(array(
      'result'=>$result
    ));
  } catch (Exception $e) {
    echo json_encode(array(
      'error'=>$e->getMessage()
    ));
  }
}
*/
?>
