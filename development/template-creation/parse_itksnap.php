<?php
function parse_itksnap($fname) {
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
    while (($s = fgets($handle, 1000)) !== FALSE) {
      if (substr($s,0,1) !== '#' && strlen(trim($s))>0) {
        $a = array();
        $ok = preg_match_all('/\"([^\"]+)\"|[^\s]+/',$s,$a);
        $index = $a[0][0];
        $r = 0+$a[0][1];
        $g = 0+$a[0][2];
        $b = 0+$a[0][3];
        $rgb = sprintf('%02X%02X%02X',$r,$g,$b);
        $name = $a[1][7];
        $acr = $name;
        if (isset($a[1][8])) {
          $acr = $a[1][8];
        } else {
          $parts = explode('|',$name);
          if (isset($parts[0]) && trim($parts[0]) != '') $name = $acr = trim($parts[0]);
          if (isset($parts[1]) && trim($parts[1]) != '') $acr = trim($parts[1]);
          if (isset($parts[2]) && trim($parts[2]) != '') $index2parent[$index] = trim($parts[2]);
        }
        
        $index2acr[$index] = $acr;
        $index2rgb[$index] = $rgb;
        $index2full[$index] = $name;
        $index2rgb[$index] = $rgb;

        if (isset($rgb2index[$rgb])) {
          echo json_encode(array('error'=>'duplicate RGB entry encountered for index '.$index.' (RGB '.$r.','.$g.','.$b.')'));
        }
        $rgb2index[$rgb] = $index;
      }
    }
    fclose($handle);
  }
  
  return array(
    'index2acr'=>$index2acr,
    'index2full'=>$index2full,
    'index2parent'=>$index2parent,
    'index2rgb'=>$index2rgb
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
