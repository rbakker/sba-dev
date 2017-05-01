<?php
set_time_limit(120);

require_once('parse_svg_lib.php');

function parse_svg_hulls($templatePath,$sliceRange,$svgFilePattern,$hullBoundingBox) {
  $sliceStart = $sliceRange[0];
  $sliceEnd = $sliceRange[1];
  $sliceStep = @$sliceRange[2];
  if (!isset($sliceStep)) $sliceStep = 1;

  $parsedPaths = array();

  // ensure that loop also works for negative $sliceStep
  $sliceEnd = $sliceStart+floor(($sliceEnd-$sliceStart)/$sliceStep)*$sliceStep;

  // second loop: rescale the SVG
  $bb = $hullBoundingBox;
  $scaleBy = 10000/($bb[3]);
  $hullPaths = array();
  $regionsByRgb = array();
  $regionsBySlice = array();
  $sliceIndex = 0;
  for ($s=$sliceStart; $s!=$sliceEnd+$sliceStep; $s+=$sliceStep) {
    echo 'Hull '.$s.";";
    $xml = getSimpleXmlFromFile($svgFilePattern,$s);
    $svg = $xml->xpath('/svg');
    $parsedPaths = getPathsFromXML($xml,NULL);
    
    $hullPaths[$sliceIndex] = array();
    scaleAndStorePaths($hullPaths[$sliceIndex],$regionsByRgb,$regionsBySlice, $parsedPaths,$s,$sliceIndex,-$bb[0],-$bb[1],$scaleBy);

    $sliceIndex++;
  }
  echo "<br/>\n";
  $scaledBoundingBox = array(0,0,round(($bb[2])*$scaleBy),round(($bb[3])*$scaleBy));
  unset($parsedPaths);

  file_put_contents($templatePath.'/hulls.json',json_encode($hullPaths));

  return array('svgBoundingBox'=>$scaledBoundingBox);
}
?>
