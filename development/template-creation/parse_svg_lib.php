<?php
global $namedColors;
require_once('namedcolors.php');

function polygon2path($points) {
  $M = explode(' ',trim($points));
  foreach ($M as &$m) {
    $m = str_replace(',',' ',$m);
  }
  return 'M'.implode('L',$M).'z';
}

function decomposePath($d,$fixCorelLineBugs=TRUE) {
  $parts = array();
  preg_match_all('/([MmLlCcZz])([^MmLlCcZz]*)/',$d,$M,PREG_PATTERN_ORDER);
  $corelLineBugFixes = 0;
  // horizontal line bug angle threshold (0.5 degrees)
  $lineBugThresh = cos(0.5*pi()/180);
  foreach ($M[1] as $i=>$m) {
    $M2 = trim($M[2][$i]);
    if ($m == 'M' || $m == 'L') {
      $a = explode(',',str_replace(' ',',',$M2));
      while (count($a)>=2) {
        $xy = array(array_shift($a),array_shift($a));
        $parts[] = $m;
        $parts[] = $xy;
      }
    } elseif ($m == 'l' || $m == 'm') {
      $corelLineBug = 0;
      $dxy = $M2;
      //$dxyPrev0 = NULL;
      $dxyPrev = NULL;
      $lenPrev = NULL;
      while (isset($dxy)) {
        $dxy = explode(' ',$dxy,3);
        $len = hypot($dxy[0],$dxy[1]);
        if ($fixCorelLineBugs && $dxyPrev) {
          $dotprod = $dxy[0]*$dxyPrev[0]+$dxy[1]*$dxyPrev[1];
          $lenPrev = hypot($dxyPrev[0],$dxyPrev[1]); 
          // angle less than threshold
          if ($len == 0 || abs($dotprod)>$lineBugThresh*$lenPrev*$len) {
            $corelLineBugFixes++;
            array_pop($parts);
            array_pop($parts);
            $dxy[0] += $dxyPrev[0];
            $dxy[1] += $dxyPrev[1];
            $xy[0] -= $dxyPrev[0];
            $xy[1] -= $dxyPrev[1];            
          }
        }
                
        $xy[0] += $dxy[0];
        $xy[1] += $dxy[1];
        $parts[] = strtoupper($m);
        $parts[] = array($xy[0],$xy[1]);
        //$dxyPrev0 = $dxy[0];
        $dxyPrev = $dxy;
        $dxy = @$dxy[2];
      }
    } elseif ($m == 'c') { // curveto relative
      $dxy = $M2;
      while (isset($dxy)) {
        $dxy = explode(' ',$dxy,4);
        $dxy0 = explode(',',$dxy[0]);
        $dxy1 = explode(',',$dxy[1]);
        $dxy2 = explode(',',$dxy[2]); // 3rd point contains x,y
        $cp1 = array($xy[0]+$dxy0[0],$xy[1]+$dxy0[1]);
        $cp2 = array($xy[0]+$dxy1[0],$xy[1]+$dxy1[1]);
        $xy[0] += $dxy2[0];
        $xy[1] += $dxy2[1];
        $parts[] = strtoupper($m);
        $parts[] = array($cp1,$cp2,$xy);
        $dxy = @$dxy[3];
      } 
    } elseif ($m == 'C') { // curveto absolute
      $a = explode(',',str_replace(' ',',',$M2));
      while (count($a)>=6) {
        $cp1 = array(array_shift($a),array_shift($a));
        $cp2 = array(array_shift($a),array_shift($a));
        $xy = array(array_shift($a),array_shift($a));
        $parts[] = $m;
        $parts[] = array($cp1,$cp2,$xy);
      }
    } elseif ($m == 'z' || $m == 'Z') {
      $parts[] = $m;
    } else {
      echo 'Unknown command '.$m."\n";
    }
    if ($m == 'm' || $m == 'M') $xy0 = $xy;
  }
  if ($corelLineBugFixes>0) echo 'Fixed '.$corelLineBugFixes.' Corel spurious line bugs.'."\n";
  return $parts;
}

function transformPoint(&$p, $x0,$y0,$sinA,$cosA) {
  // translate
  $p[0] -= $x0; 
  $p[1] -= $y0;
  // rotate
  $p[0] = $p[0]*$cosA - $p[1]*$sinA;
  $p[1] = $p[0]*$sinA + $p[1]*$cosA;
}

function transformToAlignCrossHairs(&$parts, $crossHairs) {
  list($x0,$y0) = $crossHairs['LT'];
  $dx = $crossHairs['LB'][0]-$crossHairs['LT'][0]; // positive if bottom point is right of top point, clockwise rotation needed
  $dy = $crossHairs['LB'][1]-$crossHairs['LT'][1]; // always positive
  $hyp = sqrt($dx*$dx+$dy*$dy);
  $sinA = $dx/$hyp;
  $cosA = $dy/$hyp;
  foreach($parts as &$p) {
    // p contains absolute coordinates
    if (is_array($p)) { 
      if (is_array($p[0])) {
        foreach ($p as &$cp) {
          transformPoint($cp, $x0,$y0,$sinA,$cosA);
        }
      } else {
        transformPoint($p, $x0,$y0,$sinA,$cosA);
      }
    }
  }
}

function removeDecimals(&$attr) {
  preg_match_all('/(^|[^\.]*\D)(\d+\.\d+)/',$attr,$numbers,PREG_PATTERN_ORDER);
  preg_match('/[^\d\.][^\.]*$/',$attr,$postAttr);
  $attr = '';
  foreach ($numbers[2] as $i=>$dReal) {
    $attr .= $numbers[1][$i].round($dReal);
  }
  if ($postAttr) $attr .= preg_replace('/\s*$/','',$postAttr[0]);
}   

function extractCrossHairs($xml,$crossHairColor) {
  if (!isset($crossHairColor)) return;

  global $namedColors;
  $rgb2name = array_flip($namedColors);
  $chPaths = array();
  $colorName = $rgb2name[$crossHairColor];
  $elems = $xml->xpath('//*[@fill="'.$crossHairColor.'" or @fill="'.$colorName.'"]');
  foreach ($elems as $p) {
    $tagName = $p->getName();
    if ($tagName=='path') $chPaths[] = $p['d'];
    elseif ($tagName=='polygon') {
      $chPaths[] = polygon2path($p['points']);
    }
  }
  
  if (count($chPaths)<3) { echo 'Too few crosshairs detected.'."\n"; return; }
  
  $midX = array();
  $midY = array();
  foreach ($chPaths as $i=>$p) {
    $minXY = array(1e16,1e16);
    $maxXY = array(-1e16,-1e16);
    $parts = decomposePath($p,FALSE);
    foreach ($parts as $points) {
      if (is_array($points)) {
        $xy = is_array($points[0]) ? $points[2] : $points;
        foreach ($xy as $j=>$v) {
          $minXY[$j] = min($minXY[$j],$v);
          $maxXY[$j] = max($maxXY[$j],$v);
        }
      }
    }
    $midX[$i] = ($maxXY[0]+$minXY[0])/2;
    $midY[$i] = ($maxXY[1]+$minXY[1])/2;
  }
  $minmidXY = array(min($midX),min($midY));
  $maxmidXY = array(max($midX),max($midY));
  $mid = array();
  foreach ($chPaths as $i=>$p) {
    $key = '';
    $x = $midX[$i];
    if (($x-$minmidXY[0])<($maxmidXY[0]-$minmidXY[0])/4) $key .= 'L';
    elseif (($x-$minmidXY[0])>($maxmidXY[0]-$minmidXY[0])*3/4) $key .= 'R';
    else $key .= 'M';
    $y = $midY[$i];
    if (($y-$minmidXY[1])<($maxmidXY[1]-$minmidXY[1])/4) $key .= 'T';
    elseif (($y-$minmidXY[1])>($maxmidXY[1]-$minmidXY[1])*3/4) $key .= 'B';
    else $key .= 'M';
    $mid[$key] = array($x,$y);
  }
  return $mid;
}

function extractViewBox(&$xml) {
  $elems = $xml->xpath('//svg');
  if (count($elems)) {
    $elems = $elems[0];
    return explode(' ',$elems['viewBox']);
  }
}

// The boundingbox should be the rectangle in SVG space that corresponds precisely to the edges of the PNG file.
function extractBitmapBoundingBox(&$xml) {
  $elems = $xml->xpath('//rect[@id="BitmapContour"]');
  if (count($elems)) {
    // the Corel POWERTrace VB-script adds a rectangle to mark the bitmap contour
    $elems = $elems[0];
    // remove rectangle
    $elems->addAttribute('deleted',1);
    $x = (isset($elems['x']) ? (double)$elems['x'] : 0);
    $y = (isset($elems['y']) ? (double)$elems['y'] : 0);
    $width = (double)$elems['width'];
    $height = (double)$elems['height'];
  } else {
    // Autotrace uses same width and height as original bitmap
    $x = 0;
    $y = 0;
    $width = $xml["width"];
    $height = $xml["height"];
  }
  return array($x,$y,$width,$height);
}

function extractLabels(&$label2acr,&$label2xy,&$rgb2acr, &$xml) {
  $elems = $xml->xpath('//text');
  foreach ($elems as $t) {
    $id = substr($t['id'],5);
    $acr = trim($t);
    $label2acr[$id] = $acr;
    $xy = array($t['x'],$t['y']);
    $label2xy[$id] = $xy;
    // remove element
    $t->addAttribute('deleted',1);
  }
  if (count($label2acr)>0) {
    // that is, we're dealing with a Piotr Majka SVG file
    $elems = $xml->xpath('//path');
    foreach ($elems as $p) {
      $rgb_1 = substr($p['fill'],1);
      list($struct,$label,$acr) = explode('_',(string)$p['id']);
      if (strtolower($p) != 'unlabeled') {
        $labelId = substr($label,5);
        if ($label2acr[$labelId] != $acr) {
          echo $label;
          // not always an error: can be multiple labels per path ...          
          echo 'Error in extractLabels: label '.$labelId.'('.$label2acr[$labelId].') does not match acronym '.$acr;
        }
        $rgb2acr[$rgb_1] = $acr;
      }
    }
  }
}

function extractSlicePos($xml) {
  $elems = @$xml->xpath('//*[@name="coronalcoord"]');
  if ($elems) return (string)$elems[0]["content"];
}

function extractXYLimits($xml,$viewBox) {
  $elems = @$xml->xpath('//*[@name="transformationmatrix"]');
  if ($elems) {
    $abcd = explode(',',(string)$elems[0]["content"]);
    // x_mm = svgX*a+b
    // y_mm = svgY*c+d
    $xLeft = +$abcd[1];
    $xRight = $viewBox[2]*$abcd[0]+$abcd[1];
    $yTop = +$abcd[3];
    $yBottom = $viewBox[3]*$abcd[2]+$abcd[3];
    return array($xLeft,$xRight,$yBottom,$yTop);
  }
}

function deleteBackground(&$xml,$bgColors) {
  global $namedColors;
  $rgb2name = array_flip($namedColors);
  foreach ($bgColors as $bg) {
    if (substr($bg,0,1)=='#') {
      $colorName = @$rgb2name[$bg];
    } else {
      $colorName = $bg;
      $bg = @$namedColors[$colorName];
    }
    if (isset($bg)) {
      $elems = $xml->xpath('//*[@fill="'.$bg.'"]');
      foreach ($elems as $p) {
        $p->addAttribute('deleted',1);
      }
    }
    if (isset($colorName)) {
      $elems = $xml->xpath('//*[@fill="'.$colorName.'"]');
      foreach ($elems as $p) {
        $p->addAttribute('deleted',1);
      }
    }
  }
}

function getPathDef($tagName,$p) {
  // ignore elements marked as deleted
  if ($p['deleted']) return;
    
  // element must be (converted to) a path
  if ($tagName == 'path') {
    $attr = (string)$p['d'];
  } elseif ($tagName == 'polygon') {
    $attr = polygon2path(trim($p['points']));
  } else {
    return;
  }

  // fill attibute should contain valid rgb code
  if (isset($p['fill'])) { 
    $fill = (string)$p['fill'];
  } else if (isset($p['style'])) {
    $style = (string)$p['style'];
    $ok = preg_match('/(^|;)\s*fill\s*:\s*([^;]+)/',$style,$matches);
    if ($ok) $fill = (string)$matches[2];
  }
  # obtain rgb value from fill string
  if (isset($fill)) {
    $fill = strtolower($fill);
    $hex = preg_match('/(#[0-9a-f]+)/',$fill,$matches);
    if ($hex) {
      $rgb = strtoupper($matches[1]);
    } else {
      $dec = preg_match('/rgb\((\d+),(\d+),(\d+)\)/',$fill,$matches);
      if ($dec) {
        $rgb = sprintf('#%02X%02X%02X',$matches[1],$matches[2],$matches[3]);
      } else {
        global $namedColors;
        $rgb = @$namedColors[$fill];
      }
    }
  }
  if (!isset($rgb)) {
    echo 'Error in getPathDef: '.$tagName.' element does not have a valid fill color ('.$fill.')'."\n".$p->asXML()."\n";
  }
  return array($attr,$rgb);
}

function getPathsFromXML($xml,$crossHairs) {
  $g = $xml;
  if (isset($g->g)) $g = $g->g;
  if (isset($g->g)) $g = $g->g;
  $ch = $g->children();
  $paths = array();
  foreach ($ch as $tagName=>$p) {
    list($attr,$fill) = getPathDef($tagName,$p);
    if (!isset($attr)) continue;

    $parts = decomposePath($attr);
    if (isset($crossHairs)) transformToAlignCrossHairs($parts, $crossHairs);
    $paths[] = array($fill,$parts);
  }
  return $paths;
}

function getBoundingBoxFromXML($xml,$crossHairs) {
  $g = $xml;
  if (isset($g->g)) $g = $g->g;
  if (isset($g->g)) $g = $g->g;
  $ch = $g->children();
  $xyMin = array(1e16,1e16);
  $xyMax = array(-1e16,-1e16);
  foreach ($ch as $tagName=>$p) {
    list($attr,$fill) = getPathDef($tagName,$p);
    if (!isset($attr)) continue;

    $parts = decomposePath($attr);
    if (isset($crossHairs)) transformToAlignCrossHairs($parts, $crossHairs);
    foreach ($parts as $p) {
      if (is_array($p)) { 
        if (is_array($p[0])) {
          // p contains absolute coordinates
          $xy = $p[2];
        } else {
          $xy = $p;
        }
        if ($xy[0] < $xyMin[0]) $xyMin[0] = $xy[0];
        if ($xy[1] < $xyMin[1]) $xyMin[1] = $xy[1];
        if ($xy[0] > $xyMax[0]) $xyMax[0] = $xy[0];
        if ($xy[1] > $xyMax[1]) $xyMax[1] = $xy[1];
      }
    }
  }
  return array(+$xyMin[0],+$xyMin[1],$xyMax[0]-$xyMin[0],$xyMax[1]-$xyMin[1]);
}

// Untested.
function getPointsFromPaths($parsedPaths) {
  $points = array(); // absolute coords
  foreach ($parsedPaths as $fp) {
    $fill = $fp[0];
    $parts = $fp[1];

    $xyPrev = NULL;
    foreach($parts as $p) {
      if (is_array($p)) { 
        if (is_array($p[0])) {
          // p contains absolute coordinates
          $xy = $p[2];
        } else {
          $xy = $p;
        }
        $points[] = $xy[0].' '.$xy[1];
      }
    }
  }
  return $points;
}

function scalePoint(&$cp, $moveX,$moveY,$scaleBy) {
  $cp[0] += $moveX; $cp[0] *= $scaleBy;
  $cp[1] += $moveY; $cp[1] *= $scaleBy;
}

function scaleAndStorePaths(&$svgPaths,&$regionsByRgb,&$regionsBySlice, $parsedPaths,$origSlice,$newSlice,$moveX,$moveY,$scaleBy) {
  foreach ($parsedPaths as $fp) {
    $fill = $fp[0];
    $parts = $fp[1];
    $rgb_1 = substr($fill,1);
    
    $attr = '';
    $m = ' ';
    $xyPrev = NULL;
    foreach($parts as $p) {
      if (is_array($p)) { 
        // p contains absolute coordinates
        if (is_array($p[0])) {
          foreach ($p as &$cp) {
            scalePoint($cp, $moveX,$moveY,$scaleBy);
          }
          $xy = $p[2];
          foreach ($p as &$cp) {
            $cp = array($cp[0]-$xyPrev[0],$cp[1]-$xyPrev[1]);
            $cp = implode(',',$cp);
          }
        } else {
          scalePoint($p, $moveX,$moveY,$scaleBy);
          $xy = $p;
          if (isset($xyPrev)) $p = array($p[0]-$xyPrev[0],$p[1]-$xyPrev[1]);
        }
        $attr .= implode(' ',$p);
        $xyPrev = $xy;
      } else {
        if ($p == $m) {
          $attr .= ' ';
        } else {
          $m = (isset($xyPrev) ? strtolower($p) : $p);
          $attr .= $m;
        }
      }
    }
    removeDecimals($attr);
    $svgPaths[] = $attr;
    end($svgPaths); $pathId = key($svgPaths);
    $regionsByRgb[$rgb_1][$newSlice][] = $pathId;
    $regionsBySlice[$newSlice][$rgb_1][] = $pathId;
  }
}

function getSimpleXmlFromFile($xmlFilePattern,$slice) {
  $xmlfile = sprintf($xmlFilePattern,$slice);
  $xml = file_get_contents($xmlfile);  
  $xml = str_replace('xmlns=','ns=',$xml); // for xpath to work
  return simplexml_load_string($xml);
}
?>
