<?php
$scriptFolder = dirname(__FILE__);
require_once($scriptFolder.'/formatAs.php');

function dbQuery_connect($host,$user,$pwd,$dbName) {
  $conn = @mysqli_connect($host,$user,$pwd,$dbName);
  if ($conn === false) formatAs_error('Cannot connect to database '.$dbName."\n".mysqli_connect_error());
  $conn->query('charset utf8');
  $conn->query('SET character_set_client = utf8');
  $conn->query('SET character_set_server = utf8');
  $conn->query('SET character_set_connection = utf8');
  $conn->query('SET character_set_results = utf8');
  return $conn;
}

function dbQuery_prepareTable($db,$table,$colDefs='ID INT(16)',$dropIfExists=FALSE) {
  if ($dropIfExists) {
    $q = 'DROP TABLE `'.$table.'`';
    $rs = $db->query($q);
  }
  $q = 'SELECT table_name FROM information_schema.tables WHERE table_name=\''.$table.'\'';
  $rs = $db->query($q);
  if ($rs === FALSE || $rs->fetch_row() == NULL) {
    if (is_array($colDefs)) $colDefs = implode(',',$colDefs);
    // apparently, the table doesn't yet exist
    $q = 'CREATE TABLE `'.$table.'` ('.$colDefs.') TYPE=innodb';
    $rs = $db->query($q);
    if (!$rs) formatAs_error('Error in mysql query: '.($db->error).'<br>Query: '.$q);
  }
}

function dbQuery_prepareColumn($db,$table,$colName,$colDef) {
  $q = 'SELECT `'.$colName.'` FROM `'.$table.'` LIMIT 1';
  $rs = $db->query($q);
  if ($rs === FALSE || $rs->fetch_row() == NULL) {
    // apparently, the column doesn't yet exist
    $q = 'ALTER TABLE `'.$table.'` ADD COLUMN `'.$colName.'` '.$colDef;
    $rs = $db->query($q);
    if (!$rs) formatAs_error('Error in mysql query: '.mysqli_error($db).'<br>Query: '.$q);
  }
}

function dbQuery_fillColumn($db,$table,$colName,$values,$keyName,$keys) {
  // INSERT or UPDATE values
  $kvData = array_combine($keys,$values);
  if (count($kvData)>0) {
    $q = 'INSERT INTO `'.$table.'` (`'.$keyName.'`,`'.$colName.'`) VALUES (?,?) ON DUPLICATE KEY UPDATE '.$colName.'=?';
    $stmt = $db->prepare($q);
    if (!$stmt) formatAs_error('Error in mysql prepare: '.mysqli_error($db).'<br>Prepare: '.$q);
    foreach ($kvData as $k=>$v) {
      $stmt->bind_param('sss',$k,$v,$v);
      $ok = $stmt->execute();
      if (!$ok) formatAs_error('Error in mysql execute: '.mysqli_error($db).'<br>Execute: '.$q);
    }
    $stmt->close();
  }
}

// executes prepared query $q for all rows in $data
function dbQuery_multiInsert_prepared($db,$q,$data,$includeKey=FALSE) {
  $stmt = $db->prepare($q);
  if (!$stmt) throw new Exception($db->error);
  $numFields = substr_count($q,'?');
  $fieldTypes = str_repeat('s',$numFields);
  foreach ($data as $key=>$row) {
    $args = array(&$fieldTypes);
    if ($includeKey) $args[] = &$key;
    foreach ($row as $j=>&$r) {
      $args[] = &$r;
    }
    call_user_func_array(array($stmt,'bind_param'),$args);
    $ok = $stmt->execute();
  }
}


function dbQuery_untaint($q) {
  if (isset($q)) {
    $q = str_replace('\'','',$q);
    $q = str_replace('`','',$q);
  }  
  return $q;
}

function dbQuery_namedParameterTypes($q) {
  // data in query q is represented as $name (for scalars) or @name (for arrays)
  $parts = preg_split('/([\$@][\w\d]+)/',$q,-1,PREG_SPLIT_DELIM_CAPTURE);
  $params = array();
  $even = false;
  foreach ($parts as &$subst) {
    if ($even) {
      $tp = substr($subst,0,1);
      $name = substr($subst,1);
      $params[$name] = $tp;
    }
    $even = !$even;
  }
  return $params;
}

function dbQuery_namedParameters($db,$q,$params=array()) {
  // data in query q is represented as $name (for scalars) or @name (for arrays)
  $parts = preg_split('/(`?[\$@][\w\d]+`?)/',$q,-1,PREG_SPLIT_DELIM_CAPTURE);
  $even = false;
  foreach ($parts as &$subst) {
    if ($even) {
      $backtick = substr($subst,0,1) == '`' && substr($subst,-1,1) == '`';
      if ($backtick) $subst = substr($subst,1,-1);
      $tp = substr($subst,0,1);
      $par = substr($subst,1);
      $r = @$params[$par];
      if (!isset($r)) formatAs_error('Request contains no argument "'.$par.'"');
      $qm = $backtick ? '`' : "'";
      if ($tp == '@') {
        if (is_string($r)) $r = explode(',',$r);
        foreach ($r as $k=>&$v) {
          $v = $db->real_escape_string($v);
          //if (!is_numeric($v))
          $v = $qm.$v.$qm;
        }
        $r = implode(',',$r);
      } else {
        $r = $db->real_escape_string($r);
        //if (!is_numeric($r)) 
        $r = $qm.$r.$qm;
      }
      $subst = $r;
    }
    $even = !$even;
  }
  $q = implode('',$parts);
  $logfile = '../log/dbQuery.log';
  $doLog = file_exists($logfile);
  if ($doLog) {  
    if (filesize($logfile)>262144) {
      $gzfile = $logfile.'.gz';
      $fp = gzopen($gzfile,'w9'); // w9 is the highest compression
      gzwrite($fp,file_get_contents($logfile));
      gzclose($fp);
      file_put_contents($logfile,'');
    }
    $ip = $_SERVER['REMOTE_ADDR'];
    file_put_contents($logfile,$ip.'|'.$q,FILE_APPEND);
    $start = microtime(true);
  }
  $rs = $db->query($q);
  if (!$rs) formatAs_error('Error in mysql query: '.mysqli_error($db).'<br>Query: '.$q);
  if ($doLog) {
    $dur = microtime(true) - $start;
    file_put_contents($logfile,'|'.$dur."\n",FILE_APPEND);
  }
  return $rs;
}

function dbQuery_webSafe($db,$q,$params,$allowBackticks=FALSE) {
  // q represents a prepared query and should therefore not contain quotes
  $q = str_replace('\'','',$q);
  $q = str_replace('"','',$q);
  // only allow backticks if the user ONLY sets $params, and NOT $q.
  if (!$allowBackticks) $q = str_replace('`','',$q);
  return dbQuery_namedParameters($db,$q,$params);
}

function dbQuery_rs2scalar($rs) {
  $row=$rs->fetch_row();
  return (isset($row) ? $row[0] : $row);
}

function dbQuery_rs2columns($rs) {
  $cols = array();
  while ($row=$rs->fetch_row()) {
    foreach ($row as $i=>$v) $cols[$i][] = $v;
  }
  return $cols;
}

function dbQuery_rs2struct($rs, $omitPrimaryKeys=FALSE,$indexByPrimaryKey=TRUE) {
  // convert the query results contained in $rs into a struct
  // with fields 'fields' and 'data', whereby data is indexed by $pkey;
  // $pkey is removed from the data itself if $omitPrimaryKeys is TRUE.
  $fields = array();
  $fieldInfo = mysqli_fetch_fields($rs);
  $pidx = array();
  $pkey = array();
  $usedFields = array();
  foreach ($fieldInfo as $i=>$f) { 
    $table = $f->table;
    $col = $f->name;
    $flags = $f->flags;
    if (isset($usedFields[$col])) $col = $table.'.'.$col;
    $usedFields[$col] = TRUE;
    if ($flags & MYSQLI_PRI_KEY_FLAG) { $pidx[] = $i; $pkey[] = $col; }
    $fields[] = $col;
  }
  $data = array();
  if ($indexByPrimaryKey && count($pidx) > 0) {
    $pidx0 = array_shift($pidx);
    if ($omitPrimaryKeys) {
      unset($fields[$pidx0]);
      foreach ($pidx as $i) unset($fields[$i]);
      $fields = array_values($fields);
    }
    while ($row=$rs->fetch_row()) {
      $id = $row[$pidx0];
      foreach ($pidx as $i) $id .= '|'.$row[$i];
      if ($omitPrimaryKeys) {
        unset($row[$pidx0]);
        foreach ($pidx as $i) unset($row[$i]);
        $row = array_values($row);
      }
      $data[$id] = $row;
    }
  } else {
    // if there is NO primary key, then use numeric key
    while ($row=$rs->fetch_row()) {
      $data[] = $row;
    }
    // formatAs_error('Query result must contain at least one primary key field.');
  }
  return array('keys'=>$pkey,'fields'=>$fields,'data'=>$data);  
}

// Convert the query results contained in $rs into a struct
// with fields 'fields' and 'data', whereby data is indexed by $pkey.
// Fields and pkeys are provided, this is faster and also works for VIEWs
function dbQuery_rs2struct_preset($rs, $fields,$pkeys) {
  $pidx = array();
  $field2idx = array_flip($fields);
  foreach ($pkeys as $pkey) $pidx[] = $field2idx[$pkey];

  $data = array();
  if (count($pidx) > 0) {
    $pidx0 = array_shift($pidx);
    while ($row=$rs->fetch_row()) {
      $id = $row[$pidx0];
      foreach ($pidx as $i) $id .= '|'.$row[$i];
      $data[$id] = $row;
    }
  } else {
    // if there is NO primary key, then use numeric key
    while ($row=$rs->fetch_row()) {
      $data[] = $row;
    }
  }
  return array('keys'=>$pkey,'fields'=>$fields,'data'=>$data);  
}
?>
