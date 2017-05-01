<?php
set_time_limit(240);
ini_set("memory_limit","500M");

require_once('parse_svg_lib.php');

function parse_svg_boundingbox($sliceRange,$filePattern,$bgColors,$crossHairColor) {
  $sliceStart = $sliceRange[0];
  $sliceEnd = $sliceRange[1];
  $sliceStep = @$sliceRange[2];
  if (!isset($sliceStep)) $sliceStep = 1;
  // ensure that loop also works for negative $sliceStep
  $sliceEnd = $sliceStart+floor(($sliceEnd-$sliceStart)/$sliceStep)*$sliceStep;

  // crossHairs are only used in manually scanned drawings
  if (!$bgColors) $bgcolors = array();
  if (!$crossHairColor) $crossHairColor = NULL;
  $ignoreColors = $bgColors;
  if (isset($crossHairColor)) $ignoreColors[] = $crossHairColor;

  // first loop: establish the bounding box
  $minX = 1e16;
  $minY = 1e16;
  $maxX = -1e16;
  $maxY = -1e16;
  $boundingBox = array();
  for ($s=$sliceStart; $s!=$sliceEnd+$sliceStep; $s+=$sliceStep) {
    $xml = getSimpleXmlFromFile($filePattern,$s);
    deleteBackground($xml,$ignoreColors);
    $crossHairs = extractCrossHairs($xml,$crossHairColor);
    $bb = getBoundingBoxFromXML($xml,$crossHairs);
    $boundingBox[$s] = $bb;
    
    if ($bb[0]<$minX) $minX = $bb[0];
    if ($bb[1]<$minY) $minY = $bb[1];
    if ($bb[0]+$bb[2]>$maxX) $maxX = $bb[0]+$bb[2];
    if ($bb[1]+$bb[3]>$maxY) $maxY = $bb[1]+$bb[3];
  }
  // slightly increase bounding box to account for curves
  $margin = 0.01*($maxY-$minY);
  $minX -= $margin;
  $minY -= $margin;
  $maxX += $margin;
  $maxY += $margin;
  for ($s=$sliceStart; $s!=$sliceEnd+$sliceStep; $s+=$sliceStep) {
    $boundingBox[$s][0] -= $margin;
    $boundingBox[$s][1] -= $margin;
    $boundingBox[$s][2] += 2*$margin;
    $boundingBox[$s][3] += 2*$margin;
  }
  $boundingBox['OVERALL'] = array($minX,$minY,$maxX-$minX,$maxY-$minY);
  
  return array(
    'boundingBox'=>$boundingBox
  );
}
?>