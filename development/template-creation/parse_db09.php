<?php

function parse_db09($fname0,$fname1) {
  if (!is_file($fname0)) throw new Exception('Invalid input file "'.$fname0.'"');
  echo 'Parsing file '.$fname0."<br/>\n";
  $xml = simplexml_load_file($fname0);
  $index2acr = array('[-]');
  $acr2full = array('[-]'=>'[background]');
  $index2rgb = array('000000');
  $index2nnid = array(0);
  $ch = $xml->children();
  foreach ($ch as $tagName=>$area) {
    $value = trim($area['value']);
    $acr = trim($area['abbrev']);
    $full = trim($area['name']);
    $nnid = trim($area['id']);    
    $color = $area->color;
    $r = trim($color['r']);
    $g = trim($color['g']);
    $b = trim($color['b']);
    $rgb = sprintf('%02X%02X%02X',$r,$g,$b);
    $index2acr[+$value] = $acr;
    $index2rgb[+$value] = $rgb;
    $index2nnid[+$value] = $nnid;
    $acr2full[$acr] = $full;
  }

  $acr2parent = array();
  if (!is_file($fname1)) throw new Exception('Invalid input file "'.$fname1.'"');
  $handle = fopen($fname1,'r');
  $acr2nnid = array();
  $nnid2acr = array();
  $acr2parent = array();
  $alias2acr = array();

  // skip first line
  $a = fgetcsv($handle, 1000, "\t");

  while (($a = fgetcsv($handle, 1000, "\t")) !== FALSE) {
    $nnid = $a[0];
    $full = $a[1];
    $latin = $a[2];
    $acr = $a[3];
    $vol_or_struct = $a[4];
    $prim_or_sec = $a[5];
    $parent = $a[6];
    if (strlen($nnid)>0 & is_numeric($nnid)) {
      $acr2nnid[$acr] = $nnid;
      $nnid2acr[$nnid] = $acr;
      $acr2full[$acr] = $full;
      $acr2parent[$acr] = $parent;
      $alias2acr[$latin] = $acr;
    }
  }
  fclose($handle);
  
  // convert parent from nnid to acronym namespace
  foreach ($acr2parent as $acr=>&$parent) {
    $parent = $nnid2acr[$parent];
    if ($parent == $acr) unset($acr2parent[$acr]);
  }
  foreach ($index2acr as $idx=>$acr) {
    if (!isset($acr2parent[$acr])) $acr2parent[$acr] = '[not in hierarchy]';
  }
  
  file_put_contents(dirname($fname1).'/acr2nnid.json',json_encode($acr2nnid));
  file_put_contents(dirname($fname1).'/alias2acr.json',json_encode($alias2acr));

  return array(
    'index2acr'=>$index2acr,
    'index2rgb'=>$index2rgb,
    'acr2full'=>$acr2full,
    'acr2parent'=>$acr2parent
  );
}
?>