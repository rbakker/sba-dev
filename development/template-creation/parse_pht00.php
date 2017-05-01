<?php
function parse_pht00($fname) {
  if (!is_file($fname)) throw new Exception('Invalid input file "'.$fname.'"');
  echo 'Parsing file '.$fname."<br/>\n";

  $handle = fopen($fname,'r');
  if (!$handle) return 'ERROR: '.$fname.' cannot be opened.';
  $index2rgb = array();
  $rgb2acr = array();
  $acr2full = array();
  $acr2parent = array();
  $alias2acr = array(
    '47'=>'47(12)',
    '12'=>'47(12)',
    '47L' => '47(12)L',
    '12L'=>'47(12)L',
    '47O' => '47(12)O',
    '12O'=>'47(12)O',
    'MIP'=>'PEa',
    'LIPE'=>'POaE',
    'LIPI'=>'POaI',
    'PITD'=>'TEOM',
    '36'=>'TL',
    'LIP'=>'POa',
    'VIP'=>'DIP'
  );

  // skip first row
  $a = fgetcsv($handle, 1000, ";");

  $idx = 0;
  while (($a = fgetcsv($handle, 1000, ";")) !== FALSE) {
    $fullname = $a[0];
    $acr = $a[1];
    $legacyAcr = $a[2];
    $r = $a[3];
    $g = $a[4];
    $b = $a[5];
    $comment1 = $a[6];
    $parentAcr = $a[7];
    $comment2 = $a[8];
    if (strlen($acr)>0 && is_numeric($r) && is_numeric($g) && is_numeric($b)) {
      if (preg_match('/(.+?)\s*\((.*)\)$/',$acr,$matches)) {
        $alias2acr[$matches[1]] = $acr;
        $alias2acr[$matches[2]] = $acr;
      }
      $rgb = sprintf('%02X%02X%02X',$r,$g,$b);
      echo $rgb.' '.implode('|',$a)."\n";
      $rgb2acr[$rgb] = $acr;
      $index2rgb[$idx] = $rgb;
    }
    if (strlen($acr)>0 && strlen($fullname)>0) {
      $acr2full[$acr] = $fullname;
    }
    if (isset($parentAcr) && $parentAcr != '') {
      if (substr($parentAcr,0,1) == '_') $parentAcr = substr($parentAcr,1);
      if ($acr == $parentAcr) {
        echo 'WARNING: acronym '.$acr.' is equal to its parent!'."\n";
      } else {
        $acr2parent[$acr] = $parentAcr;
      }
    }
    $idx++;
  }
  fclose($handle);

  return array(
    'index2rgb'=>$index2rgb,
    'rgb2acr'=>$rgb2acr,
    'acr2full'=>$acr2full,
    'acr2parent'=>$acr2parent,
    'alias2acr'=>$alias2acr
  );
}
?>