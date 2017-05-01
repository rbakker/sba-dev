<?php
function parse_nmm($fname) {
  if (!is_file($fname)) throw new Exception('Invalid input file "'.$fname.'"');
  echo 'Parsing file '.$fname."<br/>\n";
  $xml = file_get_contents($fname);  
  $xml = str_replace('xmlns=','ns=',$xml); // for xpath to work
  $xml = simplexml_load_string($xml);
  $labels = $xml->xpath('/LabelList/Label');
  
  
  // init results
  $index2acr = array();
  $index2full = array();
  $index2rgb =array();

  foreach ($labels as $lbl) {
    $i = (int)(string)$lbl->xpath('Number')[0];
    $name = $lbl->xpath('Name')[0];
    $abbr = $lbl->xpath('Abbr')[0];
    $index2acr[$i] = (string)$abbr;
    $index2full[$i] = (string)$name;
    $rgb = $lbl->xpath('RGBColor')[0];
    $rgb = explode(' ',$rgb);
    $index2rgb[$i] = $rgb;    
    echo $i.': '.(string)$name.' '.join(' ',$rgb).'<br/>';
  }
  $index2rgb[0] = array(0,0,0);
  $rgb2index = array();
  foreach ($index2rgb as $index=>$rgb) {
    $rgbhex = sprintf('%02X%02X%02X',$rgb[0],$rgb[1],$rgb[2]);
    if (isset($rgb2index[$rgbhex])) {
      for ($i=0; $i<=64; $i++) {
        if ($i==64) {
          echo 'The color '.join(' ',$rgb).' has a duplicate that cannot be fixed with a small adjustment.<br/>';
          break;
        }
        $bin = decbin($i+64);
        $rgb0 = $rgb[0]+$bin[0]*(2*$bin[3]-1);
        $rgb1 = $rgb[1]+$bin[1]*(2*$bin[4]-1);
        $rgb2 = $rgb[2]+$bin[2]*(2*$bin[5]-1);
        $rgbhex = sprintf('%02X%02X%02X',min(max($rgb0,0),255),min(max($rgb1,0),255),min(max($rgb2,0),255));
        if (!isset($rgb2index[$rgbhex])) {
          echo 'The color '.join(' ',$rgb).' has a duplicate. Fixed with adjustment '.$bin.'.<br/>';
          break;
        }
      }
    }
    $rgb2index[$rgbhex] = $index;
    $index2rgb[$index] = (string)$rgbhex;
  }
  return array(
    'index2acr'=>$index2acr,
    'index2full'=>$index2full,
    'index2rgb'=>$index2rgb
  );
}
?>
