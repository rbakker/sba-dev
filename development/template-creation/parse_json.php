<?php
function parse_json($fname) {
  if (!is_dir($fname)) throw new Exception('Invalid input directory "'.$fname.'"');
  echo 'Parsing folder '.$fname."<br/>\n";

  // simply load the data
  $jsonPath = $fname;
  $index2rgb = @json_decode(file_get_contents($jsonPath.'/index2rgb.json'),TRUE);
  $index2acr = @json_decode(file_get_contents($jsonPath.'/index2acr.json'),TRUE);
  if (!$index2acr) {
    $rgb2acr = @json_decode(file_get_contents($jsonPath.'/rgb2acr.json'),TRUE);
    if ($rgb2acr) {
      $index2acr = array();
      foreach ($index2rgb as $i=>$rgb) {
        if (isset($rgb2acr[$rgb])) $index2acr[$i] = $rgb2acr[$rgb];
        else echo 'No acronym found for region "'.htmlspecialchars($rgb).'".';
      }
    }
  } else if (!$index2rgb) {
    $rgb2acr = @json_decode(file_get_contents($jsonPath.'/rgb2acr.json'),TRUE);
    if ($rgb2acr) {
      $acr2rgb = array_flip($rgb2acr);
      $index2rgb = array();
      foreach ($index2acr as $i=>$acr) {
        if (isset($acr2rgb[$acr])) $index2rgb[$i] = $acr2rgb[$acr];
        else echo 'No rgb value found for region "'.htmlspecialchars($acr).'".';
      }
    }
  }
  $ans = array(
    'index2acr'=>$index2acr,
    'index2rgb'=>$index2rgb
  );
  $index2full = @json_decode(file_get_contents($jsonPath.'/index2full.json'),TRUE);
  if ($index2full) {
    $ans['index2full'] = $index2full;
  } else {
    $acr2full = @json_decode(file_get_contents($jsonPath.'/acr2full.json'),TRUE);
    if ($acr2full) {
      $index2full = array();
      foreach ($index2acr as $i=>$acr) {
        if (isset($acr2full[$acr])) $index2full[$i] = $acr2full[$acr];
        else echo 'No full name found for region "'.htmlspecialchars($acr).'".';
      }
      $ans['acr2full'] = $acr2full;
    }
  }
  $index2parent = @json_decode(file_get_contents($jsonPath.'/index2parent.json'),TRUE);
  if ($index2parent) {
    $ans['index2parent'] = $index2parent;
  } else {
    $acr2parent = @json_decode(file_get_contents($jsonPath.'/acr2parent.json'),TRUE);
    if ($acr2parent) {
      $ans['acr2parent'] = $acr2parent;
    }
  }
  return $ans;
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
