<?php
ob_start();
require_once('../../shared-php/formatAs.php');
$info = json_decode(
<<<SITEMAP
{
  "path": "Scalable Brain Atlas|NeuroNames data",
  "title": "Get NeuroNames data in json-rpc format",
  "description": "Returns the result of the NeuroNames XML parser in json-rpc format"
}
SITEMAP
,TRUE);

ini_set('display_errors',1);

// this function is needed to use simplexml's xpath
function removeNameSpaces($xml) {
  $xml = preg_replace('/ xmlns([=])/',' removed_ns$1',$xml);
  $xml = preg_replace('/(<\/?[\w\d-]+?):/', '$1_', $xml);
  $xml = preg_replace('/( [\w\d-]+?):([\w\d-]+?=")/', '$1_$2', $xml);
  return $xml;
}

function update_cache($maxAge_hours) {
  $modified = @filemtime('acr2id.json');
  if ($modified && $modified-time()<=$maxAge_hours*3600) return;
  $href = 'http://braininfo.rprc.washington.edu/NeuroNames.xml';
  $xml = @file_get_contents($href);
  if (empty($xml)) throw new Exception('Can\'t load NeuroNames XML file '.$href);
  $xml = removeNameSpaces($xml);
  $xml = simplexml_load_string($xml);
  $concepts = @$xml->xpath('//concept');
  $acr2id = array();
  $id2full = array();
  $id2url = array();
  $server = null;
  foreach ($concepts as $region) {
    $name = (string)$region['standardName'];
    $acr = (string)$region['standardAcronym'];
    $id = (string)$region['brainInfoID'];
    if ($acr) $acr2id[$acr] = $id;
    $id2full[$id] = $name;
    $url = (string)$region->brainInfoURL;
    if (!$server) $server = preg_replace('/\d+$/','',$url);
    if ($url != $server.$id) $id2url[$id] = $url;
  }
  $id2url['@root'] = $server;
  file_put_contents('acr2id.json',json_encode($acr2id));
  file_put_contents('id2full.json',json_encode($id2full));
  file_put_contents('id2url.json',json_encode($id2url));
}

try {
  update_cache(24*7); // update once per week
  $acr2id = file_get_contents('acr2id.json');
  $id2full = file_get_contents('id2full.json');
  $id2url = file_get_contents('id2url.json');
  // return results in json-rpc format
  $ans = '{"result":{"acr2id":'.$acr2id.',"id2full":'.$id2full.',"id2url":'.$id2url.'}}';
  $msg = ob_get_clean();
  if ($msg) throw new Exception($msg);
} catch(Exception $e) {
  // return error in json-rpc format
  $ans = json_encode(array(
    "error" => $e->getMessage()
  ));
}
echo formatAs_jsonHeaders().$ans;
?>