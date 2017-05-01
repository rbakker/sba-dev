<?php
$info = json_decode(
<<<SITEMAP
{
  "path": "SBA|development|services|create_template",
  "title": "Create a new atlas template",
  "description": "(re)Create an atlas template based on a json configuration file."
}
SITEMAP
,TRUE);
ini_set('display_errors',1);
session_start();

require_once('../shared-lib/fancysite.php');
require_once('../shared-lib/applet.php');
$siteMap = new siteMap_class($info);
$applet = new applet_class();

/* Create form fields for this applet */
$attrs = array('size'=>100);

require_once('../shared-lib/formfields.php');
require_once('../shared-lib/formatAs.php');

$f = new textField_class('Template configuration file');
$applet->addFormField('config',$f);

$slicedir_choices = array('x'=>'saggital','y'=>'coronal','z'=>'horizontal');
$f = new selectField_class('Slice direction');
$f->setChoices($slicedir_choices,'y');
$applet->addFormField('slicedir',$f);

$raw = array();
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
  $ok = parse_str($_SERVER['QUERY_STRING'], $raw);
} else {
  $raw = $_REQUEST;
}
list($values,$errors) = $applet->validateInputs($raw);

if (!isset($raw['config'])) {
  /*
   * Interactive mode
   */
  formatAs_htmlHeaders('<!DOCTYPE HTML/>');
  echo '<html><head>';
  echo '<script type="text/javascript" src="../shared-js/browser.js"></script>';
  echo $siteMap->windowTitle();
  echo $siteMap->clientScript();
  echo $applet->clientScript();
  echo '</head><body>';

  $iframe = @($_GET['iframe'] ? 1 : 0);
  if (!$iframe) {
    echo $siteMap->navigationBar();
    echo $siteMap->pageTitle();
    echo $siteMap->pageDescription();
  }
  
  echo $applet->standardFormHtml('Create atlas template');
  echo '</body></html>';
  exit;
} elseif (count($errors)) {
  echo '<html>'.$applet->errorReport($errors).'</html>';
  exit;
}

/*
 * On submit
 */

require_once('../shared-lib/callPython.php');
require_once('parse_svg_hulls.php');
require_once('parse_svg_slices.php');
require_once('parse_svg_boundingbox.php');

function uncomment($src) {
  // remove python-style comments
  $src = preg_replace('/^\s*#.*$/m','',$src);
}

function patternPath($filePattern) {
  $parts = explode(DIRECTORY_SEPARATOR,$filePattern);
  return implode(DIRECTORY_SEPARATOR,array_slice($parts,-2,2));
}

function absPath($fname) {
  // absolute path
  if (substr($fname,0,1) == '/' || substr($fname,1,1) == ':') return $fname;
  // otherwise start at script dir
  return __DIR__.DIRECTORY_SEPARATOR.$fname;
}

function outputFolder($sliceDir,$template,$modality='png') {
  global $slicedir_choices;
  $sliceDirName = $slicedir_choices[$sliceDir];
  $safeName = preg_replace('/[^\w]/','_',$modality);
  return absPath('../../templates/'.$template.'/'.$sliceDirName.'_'.$safeName);
}

function nii2png($niftiFile,$sliceDir,$template,$modality='png',$colorMap=NULL,$pctile=NULL,$origin=NULL,$reorient=NULL) {
  # convert NIFTI to stack of PNG images
  $pngFolder = outputFolder($sliceDir,$template,$modality);
  if (isset($colorMap)) {
    if (is_array($colorMap)) {
      $rgb2index = array_flip($colorMap);
      $index2rgb = array_flip($rgb2index);
      $duplicates = count($colorMap) - count($rgb2index);
      if ($duplicates) {
        echo 'Color map for nifti file "'.$niftiFile.'" contains '.$duplicates.' duplicates, using default colorMap instead.<br/>';
        $colorMap = 'auto';
      } else {
        $colorMap = json_encode($colorMap);
      }
    }
  } else {
    $colorMap = $pctile ? '#000-#FFF' : 'auto';
  }

  $request = array(
    'input_nii'=>$niftiFile,
    'outfolder'=>$pngFolder,
    'slicedir'=>$sliceDir,
    'colormap'=>$colorMap,
    'boundingbox_bgcolor'=>'auto'
  );
  if ($pctile) $request['pctile'] = $pctile;
  if ($origin) {
    $request['origin'] = is_array($origin) ? json_encode($origin) : $origin;
  }
  if ($reorient) $request['-reorient'] = $reorient;
  if ($modality=='png') $request[0] = 'count_pixels';
  $rpc = callPython(__DIR__.'/../../lib-python/nii2slices.py',$request,0,TRUE);
  $result = validateRPC($rpc);
  $result['pngFolder'] = $pngFolder;
  $result['slicePos'] = $pngFolder.'/slicepos.json';
  if (is_file($pngFolder.'/index2rgb.json')) {
    $index2rgb = json_decode(file_get_contents($pngFolder.'/index2rgb.json'),TRUE);
    $result['index2rgb'] = $index2rgb;
  }
  return $result;
}

function url2file($url) {
  $urlparts = parse_url($url);
  if (!isset($urlparts['host']) || !$urlparts['host']) return absPath($url);
  $host = $urlparts['host'];
  $furl = fsockopen($host, 80, $errno, $errstr, 120);
  if ($furl) {
    $page = $urlparts['path'];
    $req = "GET $page HTTP/1.0\r\n";
    $req .= "Host: $host\r\n";
    $req .= "Connection: Close\r\n\r\n";
    fwrite($furl, $req);
    $fparts = pathinfo($page);
    $md5 = substr(md5($url),0,8);
    $cacheFile = sys_get_temp_dir().'/SBA_'.$md5.'_'.$fparts['basename'];
    $fcache = fopen($cacheFile,'wb');
    $chunk = 4096;
    // first chunk contains response header
    $buf = @fread($furl,$chunk);
    list($header, $buf) = explode("\r\n\r\n", $buf, 2);          
    fwrite($fcache,$buf);
    // next chunks contains just data
    while (!feof($furl)) {
      $buf = @fread($furl,$chunk);
      fwrite($fcache,$buf);
    }
    fclose($fcache);
    fclose($furl);
  } else {
    echo 'Cannot download URL "'.$url.'": '.$errstr.' ('.$errno.')<br/>';
  }
  return $cacheFile;
}

function applyTransformation($tfdata,$infile,$transfdir,$multilabel=TRUE) {
  $parts = pathinfo($infile);
  $name = $parts['basename'];
  $name = preg_replace('/\.gz$/','',$name);
  $name = preg_replace('/\.nii$/','',$name);
  $outdir = $transfdir.'/'.$name;
  if (!is_dir($outdir)) mkdir($outdir);
  $request = array(
    'i'=>$infile,
    'o'=>$outdir,
    'prog'=>$tfdata['program'],
    'tp'=>end($tfdata['tpfiles'])
  );
  if ($multilabel) $request[] = 'm';
  echo formatAs_prettyJson($request,TRUE);
  $rpc = callPython(__DIR__.'/../../registration-tools/applytransform.py',$request,0,TRUE);
  echo formatAs_prettyJson($rpc,TRUE);
  return validateRPC($rpc);
}

try {
  $setup = json_decode(file_get_contents($values['config']),TRUE);
  if (!$setup) {
    throw new Exception(
      'Your setup file "'.$values['config'].'" could not be parsed. Check the json syntax (for example at <a href="http://jsonlint.com">jsonlint.com</a>)'
    );
  }

  // set paths
  $template = $setup['ID'];
  $templateRelPath = '../../templates/'.$template;
  $templatePath = absPath($templateRelPath);
  if (!is_dir($templatePath)) mkdir($templatePath);
  $jsonPath = $templatePath.'/template';
  if (!is_dir($jsonPath)) mkdir($jsonPath);
  $sourcePath = $templatePath.'/source';
  if (!is_dir($sourcePath)) mkdir($sourcePath);
  
  // define transformations
  if (isset($setup['defineTransformations'])) {
    $transformationPath = $templatePath.'/transformations';
    if (!is_dir($transformationPath)) mkdir($transformationPath);
    $transformationData = array();
    $transf = $setup['defineTransformations'];
    foreach ($transf as $id=>$tf) {
      $outFolder = $transformationPath.'/'.$id;
      $fixed = url2file($tf['fixed']);
      $moving = url2file($tf['moving']);
      $paramfiles = $tf['paramfiles'];
      foreach ($paramfiles as &$pf) {
        $pf = url2file($pf);
      }
      $request = array(
        'f'=>$fixed,
        'm'=>$moving,
        'o'=>$outFolder,
        'prog'=>$tf['program'],
        'p'=>implode(',',$paramfiles)
      );
      echo formatAs_prettyJson($request,TRUE);
      $rpc = callPython(__DIR__.'/../../registration-tools/preptransform.py',$request,0,TRUE);
      $transformationData[$id] = validateRPC($rpc);
      echo '<p>The transformation result is:'.formatAs_prettyJson($transformationData[$id],TRUE).'</p>';
    }
  }
  $metadataFormat = str_replace('-','',strtolower($setup['metadataFormat']));
  echo '<p>The metadata format is '.$metadataFormat.'</p>';
  if ($metadataFormat == 'aba_v2') {
    $fname = absPath($setup['metadataSource']);
    require_once('parse_aba_v2.php');
    $metaData = parse_ABA_v2($fname);
  } elseif ($metadataFormat == 'itksnap') {
    $fname = absPath($setup['metadataSource']);
    require_once('parse_itksnap.php');
    $metaData = parse_itksnap($fname);
  } elseif ($metadataFormat == 'json') {
    $fname = absPath($setup['metadataSource']);
    require_once('parse_json.php');
    $metaData = parse_json($fname);
  } elseif ($metadataFormat == 'civm4_tsv') {
    $fname = absPath($setup['metadataSource']);
    require_once('parse_civm4.php');
    $metaData = parse_civm4($fname);
  } elseif ($metadataFormat == 'nmm_xml') {
    $fname = absPath($setup['metadataSource']);
    require_once('parse_nmm.php');
    $metaData = parse_nmm($fname);
  } elseif ($metadataFormat == 'db09_xml') {
    $fname0 = absPath($setup['metadataSource'][0]);
    $fname1 = absPath($setup['metadataSource'][1]);
    require_once('parse_db09.php');
    $metaData = parse_db09($fname0,$fname1);
  } elseif ($metadataFormat == 'pht00_csv') {
    $fname = absPath($setup['metadataSource']);
    require_once('parse_pht00.php');
    $metaData = parse_pht00($fname);
  } else {
    throw new Exception('Unsupported metadata format "'.$metadataFormat.'"');
  }
  echo '<p>The metadata is '.formatAs_prettyJson($metaData,TRUE).'</p>';

  $sliceRange = NULL;
  if (isset($setup['sliceRange'])) {
    $sliceRange = $setup['sliceRange'];
  }

  $delineationFormat = $setup['delineationFormat'];
  $delineationSource = $setup['delineationSource'];
  $delineationParams = isset($setup['delineationParams']) ? $setup['delineationParams'] : array();
  $delineationBackground = isset($delineationParams['background']) ? $delineationParams['background'] : 'auto';
  echo '<p>The delineation format is '.$delineationFormat.'</p>';
  $sliceDir = $values['slicedir'];
  $colorMap = isset($metaData['index2rgb']) ? $metaData['index2rgb'] : 'auto';
  $origin = isset($delineationParams['origin']) ? $delineationParams['origin'] : NULL;
  $reorient = isset($delineationParams['reorient']) ? $delineationParams['reorient'] : NULL;
  if ($delineationFormat == 'nifti') {
    if (isset($delineationParams['applyTransformation'])) {
      $id = $delineationParams['applyTransformation'];
      if (!isset($transformationData[$id])) {
        throw new Exception('The transformation "'.$id.'" has not been defined.');
      }
      $result = applyTransformation($transformationData[$id],$delineationSource,$transformationPath,TRUE);
      $delineationSource = $result['outfile'];
    }
  } elseif ($delineationFormat == 'pht00_svg') {
    $bgColors = isset($delineationParams['bgColors']) ? $delineationParams['bgColors'] : array();
    $crossHairColor = @$delineationParams['crossHairColor'];
    $bbData = parse_svg_boundingbox($sliceRange,$delineationSource,$bgColors,$crossHairColor);
    $svg2pathData = parse_svg_slices($jsonPath,$sliceRange,$delineationSource,$bbData['boundingBox']['OVERALL'],$bgColors,$crossHairColor);
    $chBb_mm = @$delineationParams['crossHairBoundingBox']; 
    $chBb_svg = @$svg2pathData['crossHairBoundingBox'][0];
    $boundingbox_svg = $svg2pathData['svgBoundingBox']; // svg viewbox
    $boundingbox_mm = array(
      $chBb_mm[0]+$chBb_mm[2]*($boundingbox_svg[0]-$chBb_svg[0])/$chBb_svg[2],
      $chBb_mm[1]+$chBb_mm[3]*($boundingbox_svg[1]-$chBb_svg[1])/$chBb_svg[3],
      $chBb_mm[2]*$boundingbox_svg[2]/$chBb_svg[2],
      $chBb_mm[3]*$boundingbox_svg[3]/$chBb_svg[3]
    ); // limits in mm of the svg viewbox
    $request = array(
      'paths_json'=>$jsonPath.'/svgpaths.json',
      'brainslices_json'=>$jsonPath.'/brainslices.json',
      'boundingbox_svg'=>json_encode($boundingbox_svg),
      'boundingbox_mm'=>json_encode($boundingbox_mm),
      'slicepos_json'=>@$delineationParams['slicePos']
    );
    $rpc = callPython(__DIR__.'/../../lib-python/sba_paths2nii.py',$request,0,TRUE);
    $paths2niiData = validateRPC($rpc);
    $delineationSource = $paths2niiData['labels_nii'];
    echo json_encode($paths2niiData)."\n\n";
  } else {
    throw new Exception('Unsupported delineation format "'.$delineationFormat.'"');
  }
  $delineationData = nii2png($delineationSource,$sliceDir,$template,'png',$colorMap,NULL,$origin,$reorient);
  if (isset($delineationParams['slicePos'])) {
    $delineationData['slicePos'] = $delineationParams['slicePos']; // override slicepos obtained from nifti file
  }
  echo '<p>The delineation result is:'.formatAs_prettyJson($delineationData,TRUE).'</p>';    
  $delineationBackgroundRGB = is_numeric($delineationBackground) ? $delineationData['index2rgb'][$delineationBackground] : $delineationBackground;
  
  // compute slice range
  if (!isset($sliceRange) || $sliceRange == 'auto') {
    $pixCountFile = dirname($delineationData['filePattern']).'/pixcount.json';
    $pixCount = @json_decode(file_get_contents($pixCountFile),TRUE);
    if (!isset($pixCount)) throw new Exception('Can\'t find or parse '.$pixCountFile.', needed to automatically set the slice range.');
    $sMin = PHP_INT_MAX;
    $sMax = 0;
    foreach ($pixCount as $i=>$cnt) {
      if (count($cnt)>1) {
        if ($i<$sMin) $sMin = $i;
        if ($i>$sMax) $sMax = $i;
      }
    }
    $step = ceil(($sMax-$sMin+1)/192);
    $mod = ($sMax-$sMin+1) % $step;
    $sliceRange = [$sMax-ceil($mod/2),$sMin,-1*$step];
  }

  // generate SVG
  if ($delineationFormat == 'nifti') {
    $svgFolder = outputFolder($sliceDir,$template,'svg');
    // convert PNG to SVG
    $delineationCurveTol = isset($delineationParams['curveTolerance']) ? $delineationParams['curveTolerance'] : 1.5;
    $delineationLineTol = isset($delineationParams['lineTolerance']) ? $delineationParams['lineTolerance'] : 0.5;
    $request = array(
      'i'=>$delineationData['pngFolder'],
      'o'=>$svgFolder,
      't'=>$delineationCurveTol,
      's'=>$delineationLineTol,
      'c'=>$delineationBackgroundRGB
    );
    $rpc = callPython(__DIR__.'/../../nifti-tools/png2svg.py',$request,0,TRUE);
    $png2svgData = validateRPC($rpc);
    echo '<p>The png2svg result is:'.formatAs_prettyJson($png2svgData,TRUE).'</p>';
  }

  // generate hulls and compute boundingbox
  if (isset($setup['hullSource']) && $setup['hullSource'] != $setup['delineationSource']) {
    $hullFormat = $setup['hullFormat'];
    $hullSource = $setup['hullSource'];
    $hullParams = isset($setup['hullParams']) ? $setup['hullParams'] : $delineationParams;
    $hullBackground = isset($hullParams['background']) ? $hullParams['background'] : 'auto';
    if ($hullFormat == 'nifti') {
      $colorMap = isset($metaData['index2rgb']) ? $metaData['index2rgb'] : 'auto';
      $origin = isset($hullParams['origin']) ? $hullParams['origin'] : NULL;
      $reorient = isset($hullParams['reorient']) ? $hullParams['reorient'] : NULL;
      echo '<p>The hullSource is:'.formatAs_prettyJson($hullSource,TRUE).'</p>';
      $hull2pngData = nii2png($hullSource,$sliceDir,$template,'hull_png',$colorMap,NULL,$origin,$reorient);
      echo '<p>The hull2png result is:'.formatAs_prettyJson($hull2pngData,TRUE).'</p>';
    } else {
      throw new Exception('Unsupported hull format "'.$hullFormat.'"');
    }
    $hull2svgFolder = str_replace('_hull_png','_hull_svg',$hull2pngData['pngFolder']);
  } else {
    $hullSource = $delineationSource;
    $hullBackground = $delineationBackground;
    $hull2pngData = $delineationData;
    $hullBackground = $delineationBackground;
    $hull2svgFolder = str_replace('_png','_hull_svg',$delineationData['pngFolder']);
  }
  $hullBackgroundRGB = is_numeric($hullBackground) ? $hull2pngData['index2rgb'][$hullBackground] : $hullBackground;
  echo 'hull2svgFolder: '.$hull2svgFolder;
  $boundingBoxFile = dirname($hull2pngData['filePattern']).'/boundingbox.json';
  $hullBoundingBox = json_decode(file_get_contents($boundingBoxFile),TRUE);
  echo '<p>The hull bounding box is:'.formatAs_prettyJson($hullBoundingBox['combined'],TRUE).'</p>';
  
  // convert hull PNG to SVG
  $hullCurveTol = isset($hullParams['curveTolerance']) ? $hullParams['curveTolerance'] : 2.5;
  $hullLineTol = isset($hullParams['lineTolerance']) ? $hullParams['lineTolerance'] : 0.5;
  $request = array(
    'i'=>$hull2pngData['pngFolder'],
    'o'=>$hull2svgFolder,
    't'=>$hullCurveTol,
    's'=>$hullLineTol,
    'c'=>$hullBackgroundRGB
  );
  $rpc = callPython(__DIR__.'/../../nifti-tools/png2hulls.py',$request,0,TRUE);
  $hull2svgData = validateRPC($rpc);
  echo '<p>The hull2svg result is:'.formatAs_prettyJson($hull2svgData,TRUE).'</p>';
  
  // extract hulls from SVG files
  $hullPngFilePattern = $hull2pngData['filePattern'];
  $hullSvgFilePattern = $hull2svgFolder.'/'.str_replace('.png','.svg',basename($hullPngFilePattern));
  $hull2pathData = parse_svg_hulls($jsonPath,$sliceRange,absPath($hullSvgFilePattern),$hullBoundingBox['combined']);

  if (!isset($svg2pathData)) {
    // extract and store SVG polygons
    $pngFilePattern = $delineationData['filePattern'];
    $svgFilePattern = str_replace('_png','_svg',str_replace('.png','.svg',$pngFilePattern));
    $bgColors = array();
    $svg2pathData = parse_svg_slices($jsonPath,$sliceRange,absPath($svgFilePattern),$hullBoundingBox['combined'],$bgColors,NULL);
  }

  // copy template data
  $delineationPath = dirname($delineationData['filePattern']);
  echo 'delineationPath '.$delineationPath.'<br/>';
  $index2rgb = $delineationData['index2rgb'];
  file_put_contents($jsonPath.'/index2rgb.json',json_encode($index2rgb));
  
  // rgb2acr: check for missing entries
  $index2acr = $metaData['index2acr'];
  $rgb2acr = array();
  foreach ($index2rgb as $i=>$rgb) {
    $rgb2acr[$rgb] = isset($index2acr[$i]) ? $index2acr[$i] : '['.$i.']';
  }  
  file_put_contents($jsonPath.'/rgb2acr.json',json_encode($rgb2acr));
  // acr2full
  if (isset($metaData['index2full'])) {
    $index2full = $metaData['index2full'];
    $acr2full = array();
    // make sure all color indices have an acronym and fullname
    foreach ($index2rgb as $i=>$rgb) {
      $acr = $rgb2acr[$rgb];
      $acr2full[$acr] = isset($index2full[$i]) ? $index2full[$i] : '#'.$rgb;
    }
    foreach ($index2acr as $i=>$acr) {
      if (isset($index2full[$i])) $acr2full[$acr] = $index2full[$i];
    }
    file_put_contents($jsonPath.'/acr2full.json',json_encode($acr2full));
  } elseif (isset($metaData['acr2full'])) {
    $acr2full = $metaData['acr2full'];
    file_put_contents($jsonPath.'/acr2full.json',json_encode($acr2full));
  }
  // optional: acr2parent
  if (isset($metaData['index2parent'])) {
    $index2parent = $metaData['index2parent'];
    $acr2parent = array();
    // make sure all color indices have an acronym and fullname
    foreach ($index2parent as $i=>$parent) {
      if (isset($index2acr[$i])) {
        $acr = $index2acr[$i];
        $acr2parent[$acr] = $parent;
      }
    }
    file_put_contents($jsonPath.'/acr2parent.json',json_encode($acr2parent));
  } elseif (isset($metaData['acr2parent'])) {
    $acr2parent = $metaData['acr2parent'];
    file_put_contents($jsonPath.'/acr2parent.json',json_encode($acr2parent));
  }
  
  // origslicepos
  $origSlicePos = json_decode(file_get_contents($delineationData['pngFolder'].'/slicepos.json'),TRUE);
  file_put_contents($jsonPath.'/origslicepos.json',json_encode($origSlicePos));
  // slicepos
  $slice2orig = json_decode(file_get_contents($jsonPath.'/slice2orig.json'),TRUE);
  $slicePos = array();
  foreach ($slice2orig as $i=>$s) $slicePos[$i] = $origSlicePos[$s];
  file_put_contents($jsonPath.'/slicepos.json',json_encode($slicePos));
  
  $config = isset($setup['config']) ? $setup['config'] : array();
  $config['boundingBox'] = $svg2pathData['svgBoundingBox'];
  $bmbb = json_decode(file_get_contents($jsonPath.'/bmbb.json'),TRUE);
  $config['sliceCoordFrame'] = $bmbb[0];
  $rasLimits = $delineationData['rasLimits'];
  $sliceX = $sliceDir == 'x' ? 1 : 0;
  $sliceXLim = $rasLimits[$sliceX];
  $config['sliceXLim'] = $sliceXLim;
  $sliceY = $sliceDir == 'z' ? 1 : 2;
  $sliceYLim = $rasLimits[$sliceY];
  $config['sliceYLim'] = $sliceYLim;
  $config['rasLimits'] = $rasLimits;
  $config['sliceRange'] = $sliceRange;
  
  $overlays = isset($config['overlays']) ? $config['overlays'] : array();
  $downloads = isset($config['downloads']) ? $config['downloads'] : array();
  // modalities
  if (isset($setup['overlays'])) {
    foreach ($setup['overlays'] as $i=>$ovl) {
      $name = $ovl['name'];
      $fmt = $ovl['format'];
      $src = url2file($ovl['source']);
      $cmap = isset($ovl['colormap']) ? $ovl['colormap'] : NULL;
      $pctile = isset($ovl['pctile']) ? $ovl['pctile'] : NULL;
      $origin = isset($ovl['origin']) ? $ovl['origin'] : NULL;
      $reorient = isset($ovl['reorient']) ? $ovl['reorient'] : NULL;

      if (isset($ovl['applyTransformation'])) {
        $id = $ovl['applyTransformation'];
        if (!isset($transformationData[$id])) {
          throw new Exception('The transformation "'.$id.'" has not been defined.');
        }
        $result = applyTransformation($transformationData[$id],$src,$transformationPath,!isset($pctile));
        $src = $result['outfile'];
      }
      
      $ovlData = nii2png($src,$sliceDir,$template,$name,$cmap,$pctile,$origin,$reorient);
      $ovlFilePattern = $ovlData['filePattern'];
      $config_ovl = array(
        'source'=>patternPath($ovlFilePattern),
      );
      $fname = $ovlData['pngFolder'].'/slicepos.json';
      $slicepos = @json_decode(file_get_contents($fname),TRUE);
      if ($slicepos && $slicepos != $origSlicePos) {
        $config_ovl['slicepos'] = patternPath($fname);
      }
      $ovlRasLimits = $ovlData['rasLimits'];
      $ovlXLim = $ovlRasLimits[$sliceX];
      $ovlYLim = $ovlRasLimits[$sliceY];
      if (   $ovlXLim[0] != $sliceXLim[0] 
          || $ovlXLim[1] != $sliceXLim[1]
          || $ovlYLim[0] != $sliceYLim[0] 
          || $ovlYLim[1] != $sliceYLim[1]) {
        $config_ovl['anchorBox'] = [
          $ovlXLim[0],
          $ovlYLim[0],
          $ovlXLim[1]-$ovlXLim[0],
          $ovlYLim[1]-$ovlYLim[0]
        ];
        $config_ovl['anchorUnit'] = 'mm';
      }
      
      if (isset($ovl['descr'])) {
        $config_ovl['descr'] = $ovl['descr'];
      } else {
        $ovl['descr'] = $ovl['name']; // used for downloads section
      }
      if (isset($ovl['whitebackground'])) {
        $config_ovl['whitebackground'] = $ovl['whitebackground'];
      };
      if (isset($ovl['shareAsNifti'])) {
        $share = $ovl['shareAsNifti'];
        $downsample = @$share['downsample'];
        if (is_numeric($downsample)) $downsample = array($downsample);
        if (!is_array($downsample)) $downsample = array(1);
        $srcParts = pathinfo($src);
        $versions = array();
        foreach ($downsample as $factor) {
          $filename = $srcParts['basename'];
          $filename = preg_replace('/.gz$/','',$filename);
          $filename = preg_replace('/.nii$/','',$filename);
          if ($factor>1) $filename .= '_downsample'.$factor;
          $filename .= '.nii.gz';
          $request = array(
            'i'=>$src,
            'o'=>$sourcePath.'/'.$filename,
            'f'=>$factor
          );
          if ($reorient) $request['-reorient'] = $reorient;
          $rpc = callPython(__DIR__.'/../../nifti-tools/nii_downsample.py',$request,0,TRUE);
          $result = validateRPC($rpc);
          echo json_encode($result);            
          $versions[] = array(
            'url'=>$templateRelPath.'/source/'.$filename,
            'format'=>'nifti',
            'shape'=>$result['shape'],
            'filesize'=>$result['filesize']
          );
        }
        $name = $ovl['name'];
        $downloads[$name] = array(
          'versions'=>$versions,
          'descr'=>$ovl['descr']
        );
      }
      $overlays[$ovl['name']] = $config_ovl;
    }
  }
  // standard overlays: labels and hull
  if (!isset($overlays['labels'])) {
    $overlays['labels'] = array(
      'source'=>patternPath($pngFilePattern)
    );
  }
  if ($hullPngFilePattern != $pngFilePattern) {
    $overlays['hull'] = array(
      'source'=>patternPath($hullPngFilePattern)
    );
  }
  $config['overlays'] = $overlays;
  
  // EXPERIMENTAL: SUPERZOOM
  if (isset($setup['superzoom'])) {
    global $slicedir_choices;
    $sliceDirName = $slicedir_choices[$sliceDir];
    $superzoom = array();
    foreach ($setup['superzoom'] as $zoom) {
      $config_zoom = array();
      $name = $zoom['name'];
      $fmt = $zoom['format'];

      if ($fmt == 'nifti') {
        $cmap = isset($zoom['colormap']) ? $zoom['colormap'] : NULL;
        $pctile = isset($zoom['pctile']) ? $zoom['pctile'] : [0.100];
        $origin = isset($zoom['origin']) ? $zoom['origin'] : NULL;
        $reorient = isset($zoom['reorient']) ? $zoom['reorient'] : NULL;
        $src = url2file($zoom['source']);
        $zoomData = nii2png($src,$sliceDir,$template,$name,$cmap,$pctile,$origin,$reorient);
        $pattern = $zoomData['filePattern'];
      } else {
        $pattern = url2file($zoom['source']);
      }

      $safeName = preg_replace('/[^\w]/','_',$name);
      $zoomPath = $templatePath.'/zoom_'.$sliceDirName.'_'.$safeName;
      if (!is_dir($zoomPath)) mkdir($zoomPath);
      $srcPath = pathinfo($pattern,PATHINFO_DIRNAME);
      $fname = $srcPath.'/slicepos.json';
      if (!is_file($fname)) {
        throw new Exception('Superzoom folder does not contain a slicepos file "'.$fname.'".');
      }        
      $slicePos = @json_decode(file_get_contents($fname),TRUE);
      if ($slicePos) {
        file_put_contents($zoomPath.'/slicepos.json',json_encode($slicePos));
        $sliceShape = @file_get_contents($zoomPath.'/sliceshape.json');
        if ($sliceShape) $sliceShape = @json_decode($sliceShape,TRUE);
        if (!is_array($sliceShape)) $sliceShape = array();
        $config_zoom['slicepos'] = patternPath($fname);
        foreach ($slicePos as $i=>$pos) {
          $src = sprintf($pattern,$i);
          $src_fname = pathinfo($src,PATHINFO_FILENAME);
          $src_fname = str_replace('.','_',$src_fname);
          echo $src_fname;
          $dotPos = strrpos($src,'.');
          $request = array(
            'i'=>$src,
            'o'=>$zoomPath.'/'.$src_fname
          );
          //echo json_encode($request).'<br/>';
          $rpc = callPython(__DIR__.'/../../nifti-tools/img2dzi.py',$request,0,TRUE);
          $result = validateRPC($rpc);
          echo formatAs_prettyJson($result);
          $sliceShape[$i] = $result['shape'];
        }
        file_put_contents($zoomPath.'/sliceshape.json',json_encode($sliceShape));
        $request = array(
          'i'=>$zoomPath
        );
        // the dzi2jsonp step is required to make the template visible in microdraw
        $rpc = callPython(__DIR__.'/../../lib-python/dzi2jsonp.py',$request,0,TRUE);
        $result = validateRPC($rpc);
        echo formatAs_prettyJson($result);
      } else {
        throw new Exception('Superzoom folder "'.$srcPath.'" does not contain a valid slicepos.json file.');
      }
      
      $config_zoom = array(
        'source'=>patternPath($zoomPath).'/'.pathinfo($pattern,PATHINFO_FILENAME),
        'format'=>'dzi'
      );
      
      if (isset($zoom['descr'])) {
        $config_zoom['descr'] = $zoom['descr'];
      }
      $superzoom[$zoom['name']] = $config_zoom;
    }
    $config['superzoom'] = $superzoom;    
  }
  

  // standard source: coronal svg slices
  if (is_dir($templatePath.'/coronal_svg')) {
    $zipfile = $sourcePath.'/coronal_svg.zip';
    $update = is_file($zipfile) ? '-u ' : '';
    $cmdline = 'zip -9 -j '.$update.$zipfile.' '.$templatePath.'/coronal_svg/*';
    list($ec,$stdout,$stderr) = pipe_exec($cmdline);
    if ($ec == 12) echo 'Zipfile '.$zipfile.' already up to date.<br/>';
    elseif ($ec) echo 'Error no. '.$ec.' in "'.$cmdline.'":<br/>'.$stdout.'<br/>'.$stderr.'<br/>';
    $delineationParts = pathinfo($delineationSource);
    $downloads['coronal_svg'] = array(
      'versions'=>array(
        array(
          'url'=>$templateRelPath.'/source/coronal_svg.zip',
          'format'=>'svg',
          'filesize'=>filesize($zipfile)
        )
      ),
      'descr'=>array(
        'Coronal sections of the label volume "'.$delineationParts['filename'].'", converted to Scalable Vector Graphics.',
        'Brain regions are color-coded by the <a href="'.$templateRelPath.'/template/rgb2acr.json">RGB to Acronym</a> map, which can be chained with the <a href="'.$templateRelPath.'/template/acr2full.json">Acronym to Fullname</a> map.'
      )
    );
  }
  $config['downloads'] = $downloads;
  
  file_put_contents($templatePath.'/config.json',formatAs_prettyJson($config));
  
  // use hullSource to generate lateralView
  $request = array(
    'i'=>$hullSource,
    'o'=>$templatePath.'/lateral_wholebrain.png',
    'x3d'=>$templatePath.'/wholebrain.x3d',
    'bg'=>$hullBackground
  );
  $reorient = isset($hullParams['reorient']) ? $hullParams['reorient'] : NULL;
  if ($reorient) $request['-reorient'] = $reorient;
  $rpc = callPython(__DIR__.'/../../nifti-tools/nii2lateralview.py',$request,0,TRUE,'xvfb-run --server-args="-screen 0 1024x768x24" python');
  $lateralviewData = validateRPC($rpc);
  echo '<p>Lateral view:'.formatAs_prettyJson($lateralviewData).'</p>';

  // create wholebrain mesh from x3d
  if (!is_dir($templatePath.'/meshes')) mkdir($templatePath.'/meshes');
  $request = array(
    'surface_x3d'=>$templatePath.'/wholebrain.x3d',
    'faces_csv'=>$templatePath.'/meshes/wholebrain_faces.csv',
    'vertices_csv'=>$templatePath.'/meshes/wholebrain_vertices.csv',
    'vertexlimits_csv'=>$templatePath.'/meshes/wholebrain_vertexlimits.csv'
  );
  $rpc = callPython(__DIR__.'/../../lib-python/x3d_to_mesh.py',$request,0,TRUE,'python');
  $result = validateRPC($rpc);
  if (!isset($config['meshes'])) $config['meshes'] = array();
  $config['meshes']['wholebrain'] = array(
    "name"=>"whole brain",
    "deformations"=>array(
      "fiducial"=>array(
        "faces"=>"wholebrain_faces.csv",
        "vertices"=>"wholebrain_vertices.csv",
        "vertexlimits"=>"wholebrain_vertexlimits.csv"
      )
    )
  );
  
  // create mesh data for x3d viewer
  $meshdata = isset($setup['meshdata']) ? $setup['meshdata'] : array();
  $meshdata[] = array("region"=>"wholebrain");
  if (!is_dir($templatePath.'/meshdata')) mkdir($templatePath.'/meshdata');
  $config['meshdata'] = array();
  foreach ($meshdata as $md) {
    $region = $md['region'];
    $space = isset($md['space']) ? $md['space'] : $template;
    $labels_nii = isset($md['source']) ? $md['source'] : $delineationSource;
    if ($space == $template) {
      $spacePath = $templatePath;
      $mConfig = $config;
    } else {
      $spacePath = absPath('../../templates/'.$space);
      $mConfig = file_get_contents($spacePath.'/config.json');
      $mConfig = json_decode($mConfig,TRUE);
    }
    $mesh = $mConfig['meshes'][$region];
    $faces_csv = $mesh['deformations']['fiducial']['faces'];
    $vertices_csv = $mesh['deformations']['fiducial']['vertices'];
    $prefix = ($space == $template ? '' : $space.'_');
    $labels_csv = $prefix.$region.'_labels.csv';
    $colormap_csv = $prefix.$region.'_colormap.csv';
    $request = array(
      'faces_csv'=>$spacePath.'/meshes/'.$faces_csv,
      'vertices_csv'=>$spacePath.'/meshes/'.$vertices_csv,
      'labels_nii'=>$labels_nii,
      'multilabelfilter'=>'[1,1,4,1,1]',
      'colormap_json'=>$templatePath.'/template/index2rgb.json',
      'labels_csv'=>$templatePath.'/meshdata/'.$labels_csv,
      'label2rgb_csv'=>$templatePath.'/meshdata/'.$colormap_csv,
      'autoRemove'=>false
    );
    $rpc = callPython(__DIR__.'/../../lib-python/labels_to_meshdata.py',$request,0,TRUE,'python');
    $rpcResult = validateRPC($rpc);
    if (!isset($config['meshdata'][$space])) $config['meshdata'][$space] = array();
    if (!isset($config['meshdata'][$space][$region])) $config['meshdata'][$space][$region] = array();
    $config['meshdata'][$space][$region]['labels'] = $labels_csv;
    $config['colormaps'] = array("labels"=>$colormap_csv);
    echo '<p>x3d viewer with labels:'.formatAs_prettyJson($rpcResult).'</p>';
  }
  
  file_put_contents($templatePath.'/config.json',formatAs_prettyJson($config));

  // compute region centers and volumes
  // TODO: THIS JUST ECHOES THE PYTHON COMMAND
  // MAKE FANCYPIPE CHECK THE FILE DATES...
  // MAKE THE ROUTINE HEMISPHERE-AWARE
  $hemisphere = isset($config['hemisphere']) ? $config['hemisphere'] : 'L';
  $request = array(
    '-input_nii'=>$delineationSource,
    '-index2rgb_json'=>$templatePath.'/template/index2rgb.json',
    '-hemisphere'=>$config['hemisphere'],
    '-rgbcenters_json'=>$templatePath.'/template/rgbcenters_mm.json',
    '-rgbvolumes_json'=>$templatePath.'/template/rgbvolumes.json'
  );
  $rpc = callPython(__DIR__.'/../../lib-python/nii2centers.py',$request,0,TRUE,'echo python');
  
} catch (Exception $e) {
  echo '<p style="color: #D00">'.$e->getMessage()."</p>\n";
} 
?>
