<?php
$scriptFolder = dirname(__FILE__);
require_once($scriptFolder.'/formatAs.php');
require_once($scriptFolder.'/dbQuery.php');

function verySql_tableExists($db,$table) {
  $sql = 'SHOW TABLES LIKE $table';
  $rs = dbQuery_namedParameters($db,$sql,array('table'=>$table));
  return ($rs->num_rows > 0);
}

function verySql_createTable($db,$table,$colDefs,$type='innodb') {
  $sql = 'CREATE TABLE `'.$table.'` ('.$colDefs.') TYPE='.$type;
  $rs = $db->query($sql);
}

function verySql_getLastUpdate($db,$table) {
  $lastUpdateTable = 'VERYSQL_LASTUPDATE';
  try {
    $sql = 'SELECT Last_Update FROM TABLE `'.$lastUpdateTable.'` WHERE Table=`'.$table.'`';
    $rs = $db->query($sql);
    if ($rs->num_rows > 0) {
      $row = $rs->fetch_row();
      return $row[0][0];
    }
  } catch(Exception $e) {
    $colDefs = array(
      'Table'=>'VARCHAR(256)',
      'Last_Update'=>'TIMESTAMP'
    );
    verySql_createTable($db,$table,$colDefs);
    $sql = 'INSERT INTO `'.$lastUpdateTable.'` (Table,Last_Update) VALUES ($table,TIMESTAMP) ON DUPLICATE KEY UPDATE Last_Update=TIMESTAMP';    
    $rs = $db->query($sql);
    return FALSE;
  }
}

function verySql_setLastUpdate($db,$table) {
  $lastUpdateTable = 'VERYSQL_LASTUPDATE';
  try {
    $sql = 'SELECT Last_Update FROM TABLE `'.$lastUpdateTable.'` WHERE Table=`'.$table.'`';
    $rs = $db->query($sql);
    if ($rs->num_rows > 0) {
      $row = $rs->fetch_row();
      return $row[0][0];
    }
  } catch(Exception $e) {
    $colDefs = array(
      'Table'=>'VARCHAR(256)',
      'Last_Update'=>'TIMESTAMP'
    );
    verySql_createTable($db,$table,$colDefs);
    $sql = 'INSERT INTO `'.$lastUpdateTable.'` (Table,Last_Update) VALUES ($table,TIMESTAMP) ON DUPLICATE KEY UPDATE Last_Update=TIMESTAMP';    
    $rs = $db->query($sql);
    return FALSE;
  }
}

function verySql_createCache($db) {
  $cacheTable = 'VERYSQL_CACHE';
  $colDefs = array(
    'table'=>'VARCHAR(256)',
    'last_update'=>'TIMESTAMP',
    'create_statement'=>'VARCHAR(4096)'
  );
  verySql_createTable($db,$cacheTable,$colDefs);
}

function verySql_updateCache($db,$table) {
  if (!verySql_tableExists($db,'VERYSQL_CACHE')) {
    throw new Exception('VERYSQL_CACHE table does not exist');
  }
  if (!verySql_tableExists($db,'VERYSQL_DEPENDENCIES')) {
    throw new Exception('VERYSQL_DEPENDENCIES table does not exist');
  }
  $sql = 'SELECT last_update,create_statement FROM VERYSQL_CACHE WHERE table=$table';
  $params = array('table'=>$table);
  $rs = dbQuery_namedParameters($db,$sql,$params);
  $row = $rs->fetch_row();
  if (!$row) {
    throw new Exception('VERYSQL_CACHE has no table "'.$table.'"');
  }
  list($last_update,$create_statement) = $row;
  if ($create_statement) {
    // otherwise no point in updating
    $sql = 'SELECT last_update,create_statement FROM VERYSQL_DEPENDENCIES LEFT JOIN VERYSQL_CACHE ON VERYSQL_DEPENDENCIES WHERE table=$table';
    
  }  
  // check dependencies
  //#if any dependency is newer than $last_updated {
  //#  // recreate table
  //#  execute create_statement 
  //#}
}
?>
