<?php
function parse_ABA_v2($fname) {
  if (!is_file($fname)) throw new Exception('Invalid input file "'.$fname.'"');
  $handle = fopen($fname,'r');

  // init results
  $index2acr = array();
  $index2full = array();
  $index2parent = array();
  $index2rgb = array(); // =colormap
  
  // init intermediate
  $dbid2acr = array();

  // skip first row
  $a = fgetcsv($handle, 1000, ",");
  // StructureName,Abbreviation,ParentStruct,red,green,blue,informaticsId,StructureId
  // name,abbreviation,parent,mesh_name,red,green,blue,id,database_id,order,level
  while (($a = fgetcsv($handle, 1000, ",")) !== FALSE && isset($a)) {
    $fullname = $a[0];
    $acr = $a[1];
    $parentAcr = $a[2];
    $meshName = $a[3];
    $r = $a[4];
    $g = $a[5];
    $b = $a[6];
    $iid = $a[7];
    $dbid = $a[8];
    $index2acr[$iid] = $acr;
    $dbid2acr[$dbid] = $acr;
    if (is_numeric($r) && is_numeric($g) && is_numeric($b)) {
      $rgb = sprintf('%02X%02X%02X',$r,$g,$b);
      $index2rgb[$iid] = $rgb;
    }
    if (strlen($fullname)>0) {
      $index2full[$iid] = $fullname;
    }
    if ($parentAcr) {
      if (substr($parentAcr,0,1) == '_') $parentAcr = substr($parentAcr,1);
      if ($acr == $parentAcr) {
        echo 'WARNING: acronym '.$acr.' is equal to its parent!'."\n";
      } else {
        $index2parent[$iid] = $parentAcr;
      }
    }
  }
  fclose($handle);
  
  // look up parent acronyms (csv file contains dbid values)
  foreach ($index2parent as $acr=>&$parent) {
    if (isset($dbid2acr[$parent])) {
      $parent = $dbid2acr[$parent];
    } else {
      unset($index2parent[$acr]);
    }
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
