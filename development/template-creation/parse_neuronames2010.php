<?php
function parse_neuronames2010($fname) {
  if (!is_file($fname)) throw new Exception('Invalid input file "'.$fname.'"');
  echo 'Parsing file '.$fname."<br/>\n";
  $handle = fopen($fname,'r');
  $acr2nnid = array();
  $nnid2acr = array();
  $acr2full = array();
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

  return array(
    'index2acr'=>$index2acr,
    'index2full'=>$index2full,
    'index2rgb'=>$index2rgb,
    'index2parent'=>$index2parent
  );
}
?>
