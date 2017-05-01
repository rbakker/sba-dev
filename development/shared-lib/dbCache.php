<?php
require_once('../shared-lib/dbQuery.php');

function dbCache_createManagerTable($db) {
  $colDefs = array(
    'tempTable VARCHAR(63)',
    'dbName VARCHAR(63)',
    'created INT(64)',
    'touched INT(64)',
    'lifetime INT(64)',
    'sessionId VARCHAR(63)',
    'dbScheme_json TEXT',
    'queryURL BLOB',
    'PRIMARY KEY (dbName,tempTable)'
  );
  $q = 'CREATE TABLE IF NOT EXISTS dbCacheManager ('.implode(',',$colDefs).') ENGINE=innodb';
  echo $q."<br/>\n";
  $rs = $db->query($q);
  return $rs;
}

function dbCache_findTempTable($db,$sessionId,$queryURL) {
  $rs = $db->query('SELECT DATABASE()');
  $dbName = dbQuery_rs2scalar($rs);
  // check if this query was already submitted in this session
  $sql = 'SELECT tempTable FROM dbCacheManager WHERE dbName=$dbName AND sessionId=$sessionId AND queryURL=$queryURL';
  $rs = dbQuery_namedParameters($db,$sql,array('dbName'=>$dbName,'sessionId'=>$sessionId,'queryURL'=>$queryURL));
  $tempTable = FALSE;
  if ($rs->num_rows>0) {
    $row = $rs->fetch_row();
    $tempTable = $row[0];
    $datetime = new DateTime('now'); // should not suffer from UNIX year 2038 bug
    $now = $datetime->getTimestamp();
    $sql = 'UPDATE dbCacheManager SET touched=$now WHERE dbName=$dbName AND tempTable=$tempTable';
    $rs = dbQuery_namedParameters($db,$sql,array('dbName'=>$dbName,'tempTable'=>$tempTable,'now'=>$now));
  }
  return $tempTable;
}

function dbCache_registerTempTable($db,$baseTable,$sessionId,$dbSchemeUpdate,$queryURL=NULL,$lifetime=3600) {
  // first remove old temp tables
  dbCache_removeOldTables($db);
  $rs = $db->query('SELECT DATABASE()');
  $dbName = dbQuery_rs2scalar($rs);
  // create a new tempTable
  $datetime = new DateTime('now'); // should not suffer from UNIX year 2038 bug
  $now = $datetime->getTimestamp();
  $hms = $datetime->format('His');
  $tempTable = $baseTable.'_'.$hms;
  // check if tempTable already exists (created in the same second...), otherwise append 'i' to its name
  $sql = 'SELECT tempTable FROM dbCacheManager WHERE dbName=$dbName AND tempTable LIKE $tempTable ORDER BY tempTable DESC limit 1';
  $rs = dbQuery_namedParameters($db,$sql,array('dbName'=>$dbName,'tempTable'=>$tempTable.'%'));
  if ($rs->num_rows>0) {
    $row = $rs->fetch_row();
    $tempTable = $row[0].'i';
  }
  $sql = 'INSERT INTO dbCacheManager(tempTable,dbName,created,touched,lifetime,sessionId,dbScheme_json,queryURL) VALUES ($tempTable,$dbName,$now,$now,$lifetime,$sessionId,$dbScheme_json,$queryURL)';
  $rs = dbQuery_namedParameters($db,$sql,array('tempTable'=>$tempTable,'dbName'=>$dbName,'now'=>$now,'lifetime'=>$lifetime,'sessionId'=>$sessionId,'dbScheme_json'=>json_encode($dbSchemeUpdate),'queryURL'=>$queryURL));
  dbCache_removeOldTables($db);
  return $tempTable;
}

function dbCache_removeOldTables($db) {
  $datetime = new DateTime('now');
  $now = $datetime->getTimestamp();
  $sql = 'SELECT tempTable FROM dbCacheManager WHERE touched+lifetime<$now';
  $rs = dbQuery_namedParameters($db,$sql,array('now'=>$now));
  $cols = dbQuery_rs2columns($rs);
  if (@$cols[0]) foreach ($cols[0] as $tempTable) {
    $sql = 'DROP TABLE IF EXISTS `'.$tempTable.'`';
    $rs = $db->query($sql);
    if (!isset($rs)) throw Exception('dbCache_removeOldTables: Could not drop table '.$table);
    $sql = 'DELETE FROM dbCacheManager WHERE tempTable=$tempTable';
    $rs = dbQuery_namedParameters($db,$sql,array('tempTable'=>$tempTable));
  };
}

function dbCache_listCachedTables(&$dbScheme, $db,$sessionId=NULL) {
  $rs = $db->query('SELECT DATABASE()');
  $dbName = dbQuery_rs2scalar($rs);
  $sql = 'SELECT tempTable,dbScheme_json FROM dbCacheManager WHERE dbName=$dbName';
  $params = array('dbName'=>$dbName);
  if (isset($sessionId)) {
    $sql .= ' AND sessionId=$sessionId';
    $params['sessionId'] = $sessionId;
  }
  $rs = dbQuery_namedParameters($db,$sql,$params);
  $tables = array();
  if ($rs->num_rows>0) {
    $cols = dbQuery_rs2columns($rs);
    $tables = array_values($cols[0]);
  }
  return $tables;
}

function dbCache_importCachedTables(&$dbScheme, $db,$sessionId) {
  $rs = $db->query('SELECT DATABASE()');
  $dbName = dbQuery_rs2scalar($rs);
  $sql = 'SELECT tempTable,dbScheme_json FROM dbCacheManager WHERE dbName=$dbName AND sessionId=$sessionId';
  $rs = dbQuery_namedParameters($db,$sql,array('dbName'=>$dbName,'sessionId'=>$sessionId));
  if ($rs->num_rows>0) {
    $cols = dbQuery_rs2columns($rs);
    foreach ($cols[0] as $i=>$tempTable) {
      $dbScheme['tables'][$tempTable] = json_decode($cols[1][$i],TRUE);
    }
    $datetime = new DateTime('now');
    $now = $datetime->getTimestamp();
    $sql = 'UPDATE dbCacheManager SET touched=$now WHERE dbName=$dbName AND sessionId=$sessionId';
    $rs = dbQuery_namedParameters($db,$sql,array('dbName'=>$dbName,'sessionId'=>$sessionId,'now'=>$now));
  }  
}
?>
