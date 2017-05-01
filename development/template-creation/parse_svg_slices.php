<?php
set_time_limit(240);
ini_set("memory_limit","500M");

require_once('parse_svg_lib.php');

function parse_svg_slices($templatePath,$sliceRange,$svgFilePattern,$svgBoundingBox,$bgColors,$crossHairColor) {
  // example usage:
  // http://localhost/incf/development/importtemplate/parse_svg_slices.php?template=DB08&sliceRange=[6,491,3]&filepattern=coronal_svg/atlas_%2503d.svg

  $sliceStart = $sliceRange[0];
  $sliceEnd = $sliceRange[1];
  $sliceStep = @$sliceRange[2];
  if (!isset($sliceStep)) $sliceStep = 1;
  // ensure that loop also works for negative $sliceStep
  $sliceEnd = $sliceStart+floor(($sliceEnd-$sliceStart)/$sliceStep)*$sliceStep;

  // crossHairs are only used in manually scanned drawings
  if ($crossHairColor == '') $crossHairColor = NULL;
  $ignoreColors = $bgColors;
  if (isset($crossHairColor)) $ignoreColors[] = $crossHairColor;

  $parsedPaths = array();
  $svgPaths = array();
  $regionsByRgb = array();
  $regionsBySlice = array();
  $label2acr = array();
  $label2xy = array();
  $rgb2acr = array();
  $slicePos = array();
  $xyLim = array();
  $slice2orig = array();
  $slicePos = array();

  // second loop: rescale the SVG
  $bb = $svgBoundingBox;
  $scaleBy = 10000/($bb[3]);
  $sliceIndex = 0;
  $bmbb = array();
  for ($s=$sliceStart; $s!=$sliceEnd+$sliceStep; $s+=$sliceStep) {
    echo 'Slice '.$s.";";
    $xml = getSimpleXmlFromFile($svgFilePattern,$s);
    deleteBackground($xml,$ignoreColors);
    extractLabels($l2a,$l2xy,$rgb2acr, $xml);
    if (count($rgb2acr)) {
      $label2acr[$sliceIndex] = $l2a;
      $label2xy[$sliceIndex] = $l2xy;
    }
    $crossHairs = extractCrossHairs($xml,$crossHairColor);
    $parsedPaths = getPathsFromXML($xml,$crossHairs);
    // rewrite paths, scale to integers in the range 0-10000
    scaleAndStorePaths($svgPaths,$regionsByRgb,$regionsBySlice, $parsedPaths,$s,$sliceIndex,-$bb[0],-$bb[1],$scaleBy);
    // bitmap boundingbox either set by crosshairs or labeled svg rectangle
    $viewBox = extractViewBox($xml);
    if (isset($crossHairs)) {
      $parts = array($crossHairs['LT'],$crossHairs['RM'],$crossHairs['LB']);
      transformToAlignCrossHairs($parts, $crossHairs);
      // scaling, bit of a hack
      foreach ($parts as &$cp) {
        scalePoint($cp, -$bb[0],-$bb[1],$scaleBy);
      }
      // 0 = LT; 1 = RM; 2 = LB
      $bmbb[$sliceIndex] = array(round($parts[0][0]),round($parts[0][1]),round($parts[1][0]-$parts[0][0]),round($parts[2][1]-$parts[0][1]));
    } else {
      $b = extractBitmapBoundingBox($xml);
      if (!isset($b)) $b = $viewBox;
      // rescale bitmap boundingbox
      $b[0] = ($b[0]-$bb[0])*$scaleBy;
      $b[1] = ($b[1]-$bb[1])*$scaleBy;
      $b[2] *= $scaleBy; // width
      $b[3] *= $scaleBy; // height
      $bmbb[$sliceIndex] = $b;
    }
    $sp = extractSlicePos($xml);
    if (isset($sp)) $slicePos[] = +$sp;
    $lim = extractXYLimits($xml,$viewBox);
    if (isset($lim)) $xyLim[] = $lim;
    
    $slice2orig[$sliceIndex] = $s;
    $sliceIndex++;
  }
  echo "<br/>\n";
  $scaledBoundingBox = array(0,0,round(($bb[2])*$scaleBy),round(($bb[3])*$scaleBy));
  unset($parsedPaths);

  file_put_contents($templatePath.'/brainregions.json',json_encode($regionsByRgb));
  file_put_contents($templatePath.'/brainslices.json',json_encode($regionsBySlice));
  file_put_contents($templatePath.'/svgpaths.json',json_encode($svgPaths));
  file_put_contents($templatePath.'/slice2orig.json',json_encode($slice2orig));
  if (isset($bmbb)) {
    echo 'saving bmbb to '.$templatePath.'/bmbb.json';
    file_put_contents($templatePath.'/bmbb.json',json_encode($bmbb));
  }
  return array('svgBoundingBox'=>$scaledBoundingBox,'crossHairBoundingBox'=>$bmbb);
}
?>
