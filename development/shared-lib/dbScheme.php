<?php
/*
*/

define('DBSCHEME_NOTNULL',1);
define('DBSCHEME_UNSIGNED',2);
define('DBSCHEME_AUTO_INCREMENT',4);
define('DBSCHEME_UNIQUE',8);
define('DBSCHEME_PRIMARY_KEY',16); // if multi-column: only set for first column

require_once('../shared-lib/formatAs.php');
require_once('../shared-lib/dbQuery.php');

function &dbScheme_useOrInit(&$H,$k,$v0) {
  if (!isset($H[$k])) $H[$k] = $v0;
  return $H[$k];
}

/*
 * returns a json structure with a list of tables and their fields
 */
function dbScheme_parse($dbScheme) {
  // FIRST PASS
  // parse the field definitions of the dbScheme tables
  $dbParsed = $dbScheme['tables'];
  $globalFieldNames = @$dbScheme['fieldNames'];
  foreach ($dbParsed as $tName=>&$tSpec) {
    $fields =& dbScheme_useOrInit($tSpec,'fields',array());
    $fieldFlags =& dbScheme_useOrInit($tSpec,'fieldFlags',array());
    $fieldNames =& dbScheme_useOrInit($tSpec,'fieldNames',array());
    $indices =& dbScheme_useOrInit($tSpec,'indices',array());
    $indexFlags =& dbScheme_useOrInit($tSpec,'indexFlags',array());
    $outLinks =& dbScheme_useOrInit($tSpec,'outLinks',array());
    $inLinks =& dbScheme_useOrInit($tSpec,'inLinks',array());
    $primary = array();
    // parse the table fields
    foreach ($fields as $f=>&$fDef) {
      $fFlag = &$fieldFlags[$f];
      $fFlag = 0;
      // * means NOT NULL
      if (substr($fDef,0,1)=='*') {
        $fDef = substr($fDef,1);
        $fFlag |= DBSCHEME_NOTNULL;
      }
      // ! means primary key
      if (substr($fDef,0,1)=='!') {
        $primary[] = $f;
        $fDef = substr($fDef,1); // remove the ! prefix
        $fFlag |= (DBSCHEME_PRIMARY_KEY | DBSCHEME_NOTNULL);
      }
      // # means auto-incrementing primary key
      if (substr($fDef,0,1)=='#') {
        $primary[] = $f;
        $fDef = substr($fDef,1); // remove the # prefix
        $fFlag |= (DBSCHEME_PRIMARY_KEY | DBSCHEME_AUTO_INCREMENT | DBSCHEME_NOTNULL);
      }
      // + means UNSIGNED
      if (substr($fDef,0,1)=='+') {
        $fDef = substr($fDef,1);
        $fFlag |= DBSCHEME_UNSIGNED;
      }
      // ^ means FOREIGN KEY
      if (substr($fDef,0,1)=='^') {
        $fDef = substr($fDef,1);
        $outLinks[$f] = $fDef;
      }
      // $friendlyName is the friendly name of a field
      $friendlyName = &$fieldNames[$f];
      if (!isset($friendlyName)) { 
        $friendlyName = @$globalFieldNames[$f];
        if (!isset($friendlyName)) {
          // for fields formatted as ID_something, set $friendlyName to 'something'
          if (strncasecmp($f,'ID_',3) === 0) $friendlyName = @$dbParsed[substr($f,3)]['name']; 
          if (!isset($friendlyName)) $friendlyName = $f;
        }
      }
    }
    // parse the table indices
    foreach ($indices as $i=>&$idx) {
      // ! means UNIQUE (index)
      if (substr($idx,0,1)=='!') {
        $idx = substr($idx,1);
        $indexFlags[$i] |= DBSCHEME_UNIQUE;
      }
      $idx = explode(',',$idx);
      foreach ($idx as $f) {
        if (!isset($fields[$f])) formatAs_error('Index '.$idx.'refers to non-existing field '.$tName.'.'.$f);
      }
    }
    // create the primary key index
    $numPrimary = count($primary);
    if (isset($indices['primary'])) {
      // The primary key is specified as an index with the key 'primary'
      if ($numPrimary>0) formatAs_error('Primary key doubly defined in table '.$tName);
    } else {
      if ($numPrimary == 0) formatAs_error('No primary key specified for table '.$tName.'<p>'.$tSpec); 
      $indices['primary'] = $primary;
    }
  }

  // SECOND PASS
  // parse foreign key constraints
  foreach ($dbParsed as $tName=>&$tSpec) {
    $complexLinks =& dbScheme_useOrInit($tSpec,'complexLinks',array());
    $altFormat =& dbScheme_useOrInit($tSpec,'altFormat',array());
    $altFields =& dbScheme_useOrInit($tSpec,'altFields',array());
    $altLinks =& dbScheme_useOrInit($tSpec,'altLinks',array());
    $fields =& $tSpec['fields'];
    $outLinks =& $tSpec['outLinks'];
    // outLink has format "toTable1.toField1,toField2,toField3,..."
    foreach ($outLinks as $f=>$outLink) {
      $fromFields = explode(',',$f); // $f is a string; for composite keys the fromFields are comma-separated
      $dotPos = strpos($outLink,'.');
      $isComplex = FALSE;
      if ($dotPos === FALSE) {
        $toTable = $outLink;        
        $toFields = $dbParsed[$toTable]['indices']['primary'];
        if (count($toFields)>1) $isComplex = TRUE;
      } else {
        $toTable = substr($outLink,0,$dotPos);
        $toFields = explode(',',substr($outLink,$dotPos+1)); // for composite keys the toFields are comma-separated
      }
      $numFields = count($fromFields);
      if ($numFields==1 && $toFields[0] == $dbParsed[$toTable]['indices']['primary'][0]) {
        $fields[$f] = $dbParsed[$toTable]['fields'][$toFields[0]];
        $outLinks[$f] = $toTable;
        $dbParsed[$toTable]['inLinks'][$tName.'.'.$f] = $toFields[0];
      } else {
        // This code provides *untested* support for complex foreign keys: either composite or 
        // not referring to a table's primary key.
        for ($i=0; $i<$numFields; $i++) {
          $from = $fromFields[$i];
          $to = @$toFields[$i];
          if (!isset($to)) formatAs_error('Error in complex foreign key "'.$tName.'.'.$f.'" : "'.$outLink.'"');
          $prevFrom = ($i>0 ? $fromFields[$i-1] : NULL);
          $nextFrom = ($i+1<$numFields ? $fromFields[$i+1] : NULL);
          // set complexLinks in the format toTable toField prevFromField nextFromField
          $complexLinks[$from] = array($toTable,$to,$prevFrom,$nextFrom);
          // infer the field types from the toTable
          $fields[$from] = $dbParsed[$toTable]['fields'][$to];
        }
        if ($numFields>0) {
          // remove the comma-separated field
          unset($outLinks[$f]);
          unset($fields[$f]);
        }
        // inlinks don't support complex foreign keys yet
      }
    }    
    // parse the asCell and asItem formatted keys
    $altKeys = array(isset($tSpec['asCell']) ? $tSpec['asCell'] : NULL);
    if (isset($tSpec['asItem'])) $altKeys[] = $tSpec['asItem'];
    $tSpec['altFormat'] = array();
    foreach ($altKeys as $i=>$aK) {
      if (!isset($aK)) continue;
      if (!is_array($aK)) {
        $fmt = NULL;
        $aK = array($aK);
      } else {
        $fmt = array_shift($aK);
      }
      $tSpec['altFormat'][$i] = $fmt;
      $tSpec['altFields'][$i] = array();
      $tSpec['altLinks'][$i] = array();
      foreach ($aK as $f) {
        $linkLevel = -1;
        while (substr($f,0,1)=='^') {
          $f = substr($f,1);
          $linkLevel++;
        }
        if ($linkLevel > -1) $tSpec['altLinks'][$i][$f] = $linkLevel;
        $tSpec['altFields'][$i][] = $f;
      }
    }
  }
  return $dbParsed;
}

/* 
 * Parses the display layout components of the dbScheme
 */
function dbScheme_parseLayout($dbParsed) {
  $dbLayout = array();
  // estimate field sizes
  foreach ($dbParsed as $tName=>$tSpec) {
    $tLayout = array();
    $cls = @$tSpec['class'];
    if ($cls) $tLayout['class'] = $cls;
    foreach ($tSpec['fields'] as $fName=>$fDef) {
      $fDef = strtolower($fDef);
      $sz = 0;
      if ($fDef == 'longtext') $sz = 1000;
      $lPos = strpos($fDef,'(');
      $rPos = strpos($fDef,')');
      if ($rPos > $lPos) {
        $sz = substr($fDef,$lPos+1,$rPos-$lPos-1);
      }
      if ($sz>0) $tLayout['fields'][$fName]['size'] = $sz;
    }
    if (isset($tSpec['altLinks'])) {
      if (isset($tSpec['altLinks'][0])) {
        $tLayout['asCell'] = array(
          'format'=>$tSpec['altFormat'][0],
          'links'=>$tSpec['altLinks'][0],
          'fields'=>$tSpec['altFields'][0]
        );
      }
      if (isset($tSpec['altLinks'][1])) {
        $tLayout['asItem'] = array(
          'format'=>$tSpec['altFormat'][1],
          'links'=>$tSpec['altLinks'][1],
          'fields'=>$tSpec['altFields'][1]
        );
      }
    }
    $dbLayout[$tName] = $tLayout;
  }
  return $dbLayout;
}

function dbScheme_detectHierarchy($dbParsed) {
  $child2parent = array();
  foreach ($dbParsed as $tName=>$tSpec) {
    $outLinks = $tSpec['outLinks'];
    $key = key($outLinks);
    if (isset($key)) {
      if ($tSpec['fieldFlags'][$key] & DBSCHEME_NOTNULL) {
        $outLink = current($outLinks);
        $child2parent[$tName] = $outLink;
      }
    }
  }
  return $child2parent;
}

function dbScheme_allFields_recursive($dbParsed, &$path2table, $table,$path, $tableExclude,$depthLeft) {
  $maxRecursion = 1;
  if ($depthLeft <= 0) return;

  // don't use table more than $maxRecursion
  isset($tableExclude[$table]) ? $tableExclude[$table]++ : $tableExclude[$table] = 1;
  
  // include normal fields
  $outLinks = $dbParsed[$table]['outLinks'];
  $allFields = array();
  $fields = $dbParsed[$table]['fields'];
  foreach ($fields as $k=>$fDef) {
    if (!isset($outLinks[$k])) {
      $allFields[] = $k;
    }
  }
  $path2table[$path] = $table;

  // recursively include outLinks (ignore inLinks)
  foreach ($outLinks as $k=>$toTable) {
    if (!isset($tableExclude[$toTable]) || $tableExclude[$toTable]<=$maxRecursion) {
      $link = '^'.$k;
      $allFields[$link] = dbScheme_allFields_recursive($dbParsed, $path2table, $toTable,$path.$link,$tableExclude,$depthLeft-1);
    }
  }
  
  return $allFields;
}

/*
 * allfields includes all direct fields of a table and the fields of its outLinks (sub-tables)
 *
 * Example output:
 * allFields = {
    "0":"SiteDef_Type",
    "1":"SiteClass",
    "2":"ID_BrainMap",
    "3":"ID",
    "4":"BrainSite",
    "^ID_BrainMaps_BrainSiteAcronym":[
      "Acronym",
      "FullName",
      "LegacyID",
      "BrainInfoID",
      "ID"
    ]
   }
 * cmpct2table = {
    {
      "^ID_BrainMaps_BrainSites":[
        "BrainMaps_BrainSites",
        ""
      ],
      "^ID_BrainMaps_BrainSites^ID_BrainMaps_BrainSiteAcronyms":[
        "BrainMaps_BrainSiteAcronyms",
        "^ID_BrainMaps_BrainSiteAcronym"
      ]
    }
   }
 *
 */
function dbScheme_allFields($dbParsed, $table, $tableExclude=array()) {
  $maxDepth = 20;
  $allFields = dbScheme_allFields_recursive($dbParsed, $path2table, $table,'', $tableExclude,$maxDepth);
  return array($allFields,$path2table);
}


function dbScheme_allPaths_recursive($dbParsed, &$path2table,&$path2linkIdx, $path,$table,$linkIdx, $tableExclude,$depth) {
  // TODO: either eliminate allPaths_recursive or allFields_recursive
  $maxDepth = 100;
  if ($depth>$maxDepth || isset($tableExclude[$table])) return;
  $tableExclude[$table] = TRUE;

  $path2table[$path] = $table;
  $allPaths = array();

  // recursively include outLinks
  $outLinks = $dbParsed[$table]['outLinks'];
  if (isset($linkIdx)) {
    $path2linkIdx[$path] = $linkIdx;
    $altLinks = $dbParsed[$table]['altLinks'][$linkIdx];
    foreach ($altLinks as $k=>$toLink) {
      $toTable = $outLinks[$k];
      $allPaths[$k] = dbScheme_allPaths_recursive($dbParsed, $path2table,$path2linkIdx, $path.'^'.$k,$toTable,$toLink, $tableExclude,$depth+1);
    }
  } else {
    foreach ($outLinks as $k=>$toTable) {
      $allPaths[$k] = dbScheme_allPaths_recursive($dbParsed, $path2table,$path2linkIdx, $path.'^'.$k,$toTable,NULL, $tableExclude,$depth+1);
    }
  }
  return $allPaths;
}

/*
 * allPaths recursively includes all of its outLinks
 *
 * Example output:
 * allPaths = {
    "ID_BrainMaps_BrainSiteAcronym":[
      "Acronym",
      "FullName",
      "LegacyID",
      "BrainInfoID",
      "ID"
    ]
   }
 * path2table = {
    {
      "^ID_BrainMaps_BrainSites":[
        "BrainMaps_BrainSites",
        ""
      ],
      "^ID_BrainMaps_BrainSites^ID_BrainMaps_BrainSiteAcronyms":[
        "BrainMaps_BrainSiteAcronyms",
        "^ID_BrainMaps_BrainSiteAcronym"
      ]
    }
   }
 *
 */
function dbScheme_allPaths($dbParsed, $rootTable, $linkIdx=NULL,$tableExclude=array()) {
  // if $link is set, it decides which $altKey is used
  $path2table = array();
  $path2linkIdx = array();
  $allPaths = dbScheme_allPaths_recursive($dbParsed, $path2table,$path2linkIdx, '',$rootTable,$linkIdx, $tableExclude,0);
  return array($allPaths,$path2table,$path2linkIdx);
}

function dbScheme_allFieldsChoices_recursive(&$choices, $dbParsed,$allFields,$path2table, $nicePath,$path,$depth, $vecProps=TRUE) {
  $table = $path2table[$path];
  $fieldNames = isset($dbParsed[$table]['fieldNames']) ? $dbParsed[$table]['fieldNames'] : array();
  foreach ($allFields as $k=>$v) {
    if (is_array($v)) {
      $fkPath = $path.$k;
      $fkTable = $path2table[$fkPath];
      $f = substr($k,1);
      $niceName = isset($fieldNames[$f]) ? $fieldNames[$f] : $f;
      $fkNicePath = (isset($nicePath) ? $nicePath.' &#9658; ' : '').$niceName;
      dbScheme_allFieldsChoices_recursive($choices, $dbParsed,$v,$path2table, $fkNicePath,$fkPath,$depth+1,$vecProps);
    } else {
      $table = $path2table[$path];
      $choices[$nicePath][$path.'.'.$v] = $table.'.'.$v;
    }
  }
  if ($vecProps) $choices[$nicePath][$path] = '&#9668; vector properties of '.$table;
}

/*
 * allfieldsChoices returns a human-readable list of fields of all tables and their outLinks (recursively)
 *
 * Example output (input to formfields::selectField_class):
 * choices = 
    {
      "BrainMaps_BrainSites":{
        ".SiteDef_Type":"BrainMaps_BrainSites.SiteDef_Type",
        ".SiteClass":"BrainMaps_BrainSites.SiteClass",
        ".ID_BrainMap":"BrainMaps_BrainSites.ID_BrainMap",
        ".ID":"BrainMaps_BrainSites.ID",
        ".BrainSite":"BrainMaps_BrainSites.BrainSite"
      },
      "BrainMaps_BrainSites &#9668; Acronym":{
        "^ID_BrainMaps_BrainSiteAcronym.Acronym":"BrainMaps_BrainSiteAcronyms.Acronym",
        "^ID_BrainMaps_BrainSiteAcronym.FullName":"BrainMaps_BrainSiteAcronyms.FullName",
        "^ID_BrainMaps_BrainSiteAcronym.LegacyID":"BrainMaps_BrainSiteAcronyms.LegacyID",
        "^ID_BrainMaps_BrainSiteAcronym.BrainInfoID":"BrainMaps_BrainSiteAcronyms.BrainInfoID",
        "^ID_BrainMaps_BrainSiteAcronym.ID":"BrainMaps_BrainSiteAcronyms.ID"
      }
    } 
 *
 * The actual selected fields are stored in their compact form,
 * e.g. ^ID_BrainMaps_BrainSiteAcronym.FullName
 */
function dbScheme_allFieldsChoices($dbParsed,$allFields,$path2table,$vecProps=TRUE) {
  $T0 = $path2table[''];
  $choices = array();
  dbScheme_allFieldsChoices_recursive($choices, $dbParsed,$allFields,$path2table, $T0,'',0,$vecProps);
  return $choices;
}

// Returns boolean hash array that indicates whether a table has multiple inLinks (in a given query)
// Status: deprecated
function dbScheme_multiParentTables($path2table) {
  $multiParent = array();
  $table2parent = array();
  foreach ($path2table as $path=>$table) {
    if (!isset($multiParent[$table])) {
      $sepPos = strrpos($path,'^');
      if ($sepPos !== FALSE) {
        $parentPath = substr($path,0,$sepPos);
        $parent = $path2table[$parentPath];
        if (isset($table2parent[$table]) && $parent !== $table2parent[$table]) {
          $multiParent[$table] = TRUE;
          $table2parent[$table] .= ','.$parent;
        } else {
          $table2parent[$table] = $parent;
        }
      }
    }
  }
  return $multiParent;
}

function dbScheme_leftJoin_recursive($dbParsed,$allPaths,$path2table, &$leftJoin,&$path2as, $path, $pathInclude) {
  foreach ($allPaths as $outLink=>$subTree) {
    $fkPath = $path.'^'.$outLink;
    if (!isset($pathInclude[$fkPath])) continue;
    //? could replace $path2table by $path2table
    $fkTable = $path2table[$fkPath];
    $fkTableAs = 'T'.count($path2as);
    $path2as[$fkPath] = $fkTableAs;
    $tableAs = $path2as[$path];
    // split link into key and foreign table
    $fkField = $dbParsed[$fkTable]['indices']['primary'][0];
    $leftJoin[] = $fkTable.' AS '.$fkTableAs.' ON '.$tableAs.'.'.$outLink.'='.$fkTableAs.'.'.$fkField.'';
    dbScheme_leftJoin_recursive($dbParsed,$subTree,$path2table, $leftJoin,$path2as, $fkPath, $pathInclude);
  }
}

/* 
 * Create the 'FROM' part of the all-fields query.
 */
function dbScheme_allPathsQuery_joinedTables($dbParsed,$allPaths,$path2table, $includePaths) { 
  // parse includePaths
  $pathInclude = array();
  if (count($includePaths)>0) {
    foreach ($includePaths as $path) {
      do {
        // avoid double work
        if (isset($pathInclude[$path])) break; 
        $pathInclude[$path] = TRUE;
        // prepare for next iteration
        $sepPos = strrpos($path,'^');
        $path = @substr($path,0,$sepPos);
      } while ($path);
    }
  }

  $T0 = $path2table[''];
  $path2as = array(''=>'T0');
  $leftJoin = array($T0.' AS T0');
  dbScheme_leftJoin_recursive($dbParsed,$allPaths,$path2table, $leftJoin,$path2as, '', $pathInclude);
  $joinedTables = implode(' LEFT JOIN ',$leftJoin);
  return array($joinedTables,$path2as);
}

function dbScheme_addAlternateKeys_recursive($dbParsed, &$result, $table,$linkIdx,$depth) {
  if ($depth >= 10) return;
  $tDef = $dbParsed[$table];
  $res = &$result['tables'][$table];

  if (isset($tDef['altFields'][$linkIdx])) {
    $outLinks = $tDef['outLinks'];
    $format = $tDef['altFormat'][$linkIdx];
    $altLinks = $tDef['altLinks'][$linkIdx];
    foreach ($altLinks as $f=>$toLink) {
      $toTable = $res['links'][$f];
      dbScheme_addAlternateKeys_recursive($dbParsed, $result, $toTable,$toLink,$depth+1);
    }
    $f2i = array_flip($res['fields']);
    $args = array();
    $fields = $tDef['altFields'][$linkIdx];
    foreach ($res['data'] as $k=>$row) {
      $args = array();
      foreach ($fields as $f) {
        $i = $f2i[$f];
        $args[] = isset($outLinks[$f]) ? $result['tables'][$outLinks[$f]]['altKeys'][$row[$i]] : $row[$i];
      }
      $res['altKeys'][$k] = isset($format) ? vsprintf($format,$args) : implode(',',$args);
    }
  } else {
    // use primary key as altKey
    foreach ($res['data'] as $k=>$row) {
      $res['altKeys'][$k] = $k;
    }    
  }
}

function dbScheme_addAlternateKeys($dbParsed, &$result,$linkIdx) {
  $table = $result['resultTable'];
  dbScheme_addAlternateKeys_recursive($dbParsed, $result, $table,$linkIdx,0); 
  return $result['tables'][$table]['altKeys'];
}

/* restrict choices such that they will yield at least one search result */
function dbScheme_allFieldsChoices_where($dbParsed,$T0,$linkPath) {
  $sql = '';
  if (substr($linkPath,0,1) != '^') return $sql;
  // linkPath always starts with ^, ignore this first character
  $linkPath = explode('^',substr($linkPath,1));
  $tableChain = array();
  // start with the base table
  $tableChain[] = $table = $T0;
  foreach ($linkPath as $lp) {
    $tableChain[] = $table = $dbParsed[$table]['outLinks'][$lp];
  }
  for ($i=count($linkPath)-1; $i>=0; $i--) {
    $table = $tableChain[$i];
    $field = $linkPath[$i];
    $upTable = $tableChain[$i+1];
    $upKey = join(',',$dbParsed[$upTable]['indices']['primary']); // normally there is just a singke primary key
    $sql .= ' WHERE '.$upTable.'.'.$upKey.' IN(SELECT '.$field.' FROM '.$table;
  }
  return $sql.str_repeat(')',count($linkPath));
}

function dbScheme_detect($db) {
  $q = 'SHOW TABLES';
  // For some file systems, tables are stored as lower case but mapped to their original case. 
  // The KEY_COLUMN_USAGE field always refers to the name used to store the table
  $rs = @$db->query($q);
  $lc2table = array();
  while ($row = mysqli_fetch_row($rs)) {
    $lc2table[strtolower($row[0])] = $row[0];
  }  
  
  $rs = $db->query('SELECT DATABASE()');
  if ($rs === FALSE) echo formatAs_error($db->error);
  $row = mysqli_fetch_row($rs);
  $dbName = $row[0];
  $rs->free();
  
  $db->select_db('information_schema');
  $q = 'SELECT TABLES.TABLE_NAME, COLUMNS.COLUMN_NAME, COLUMNS.IS_NULLABLE, COLUMNS.COLUMN_TYPE, COLUMNS.COLUMN_KEY, COLUMNS.EXTRA, LOWER(KEY_COLUMN_USAGE.REFERENCED_TABLE_NAME) AS FK_TABLE, KEY_COLUMN_USAGE.REFERENCED_COLUMN_NAME AS FK_COLUMN '.
    'FROM COLUMNS LEFT JOIN TABLES ON COLUMNS.TABLE_NAME=TABLES.TABLE_NAME AND COLUMNS.TABLE_SCHEMA=TABLES.TABLE_SCHEMA '.
    'LEFT JOIN KEY_COLUMN_USAGE ON LOWER(KEY_COLUMN_USAGE.TABLE_NAME)=LOWER(TABLES.TABLE_NAME) AND KEY_COLUMN_USAGE.COLUMN_NAME=COLUMNS.COLUMN_NAME AND LOWER(KEY_COLUMN_USAGE.TABLE_SCHEMA)=LOWER(TABLES.TABLE_SCHEMA) '.
    'WHERE TABLES.TABLE_SCHEMA = \''.$dbName.'\' '.
    'ORDER BY TABLES.TABLE_NAME,COLUMNS.COLUMN_NAME DESC';
  $rs = @$db->query($q);
  $db->select_db($dbName);
  if ($rs === FALSE) echo formatAs_error($db->error);
  $rows = array();
  while ($row = $rs->fetch_row()) $rows[] = $row;
  $rs->free();
  $tables = array();
  foreach ($rows as $row) {
    $type = '';
    // (part of) primary key
    if ($row[4]=='PRI') {
      $type .= (@$row[5] == 'auto_increment' ? '#' : '!');
    } else {
      // nullable
      if ($row[2]=='NO') $type .= '*';
    }
    // + for unsigned?
    if ($row[6]) {
      // outLink table, outLink column
      $fkTable = @$lc2table[$row[6]];
      if (!isset($fkTable)) formatAs_error('Referenced table '.$row[6].' does not exist');
      $type .= '^'.$fkTable.'.'.$row[7]; // todo: simplify if foreign key is primary key of referenced table
    } else {
      // data type (incl. length)
      $type .= $row[3];    
    }
    // save to tables.fields[field name]
    if (@!isset($tables[$row[0]])) $tables[$row[0]] = array('fields'=>array());
    $tables[$row[0]]['fields'][$row[1]] = $type;
  }
  $mdbRelations = @$tables['MDB_Relations'];
  if (isset($mdbRelations)) {
    $rs = @$db->query('SELECT fkTable,fkField,refTable,refField FROM MDB_Relations');    
    if ($rs === FALSE) echo formatAs_error($db->error);
    $rows = array();
    $row = $rs->fetch_row();
    while (isset($row)) { $rows[] = $row; $row = $rs->fetch_row(); }
    foreach ($rows as $row) {
      list($fkTable,$fkField,$refTable,$refField) = $row;
      // if ($refField == 'ID') $refField = null;
      // This requires detection of primary key. How are many-to-many primary keys stored in mySQL?
      $refField = isset($refField) ? '.'.$refField : '';
      $type = @substr($tables[$fkTable]['fields'][$fkField],0,1);
      if (!isset($type)) echo formatAs_error('MDB_Relations: foreign key field '.$fkTable.'.'.$fkField.' does not exist.');
      $prefix = '';
      if (substr($type,0,1) == '#') echo formatAs_error('MDB_Relations: foreign key field '.$fkTable.'.'.$fkField.' cannot auto-increment.');
      if (substr($type,0,1) == '!') { $prefix .= '!'; $type = substr($type,1); }
      if (substr($type,0,1) == '*') { $prefix .= '*'; $type = substr($type,1); }
      @$tables[$fkTable]['fields'][$fkField] = $prefix.'^'.$refTable.$refField;
    }
  }
  return array('tables'=>$tables);
}

// return statement to create the table $tName given its (parsed) $tSpec
function dbScheme_tableColDefs($tName,$tSpec) {
  $colDefs = array();
  $fields = $tSpec['fields'];
  $fieldFlags = $tSpec['fieldFlags'];
  foreach ($fields as $k=>$v) {
    $def = $k.' '.$v;
    $fFlag = $fieldFlags[$k];
    if ($fFlag & DBSCHEME_UNSIGNED) $def .= ' unsigned';
    if ($fFlag & DBSCHEME_NOTNULL) $def .= ' not null';
    if ($fFlag & DBSCHEME_AUTO_INCREMENT) $def .= ' auto_increment';
    $colDefs[] = $def;
  }
  $indices = $tSpec['indices'];
  $indexFlags = $tSpec['indexFlags'];
  foreach ($indices as $k=>$v) {
    $def = ($k == 'primary' ? 'primary key' : 'index '.$k);
    $def .= ' ('.(is_array($v) ? implode(',',$v) : $v).')';
    $iFlag = @$indexFlags[$k];
    if ($iFlag & DBSCHEME_UNIQUE) $def .= ' unique';
    $colDefs[] = $def;
  }
  $outLinks = $tSpec['outLinks'];
  foreach ($outLinks as $k=>$v) {
    // no support for multiple column keys yet
    $def = 'index '.$k.'('.$k.')';
    // $def = 'foreign key';
    // FOREIGN KEY (`ID`) REFERENCES `projects` (`assigned`)
    $colDefs[] = $def;
  }
  return $colDefs;
}

// creates a table that contains all foreign key counts for each table, to speed up queries
function dbScheme_createCountTable($db,$dbParsed) {
  $sql = 'DROP TABLE IF EXISTS `dbCountTable`';
  $rs = $db->query($sql);
  $colDefs = array('tbl VARCHAR(127)','fld VARCHAR(127)','fkey INT(32)','count INT(32)');
  $sql = 'CREATE TABLE `dbCountTable` ('.implode(',',$colDefs).',PRIMARY KEY (tbl,fld,fkey))';
  $rs = $db->query($sql);
  foreach ($dbParsed as $tName=>$tDef) {
    foreach ($tDef['outLinks'] as $field=>$outLink) {
      // ignore non-integer fields
      if (substr($tDef['fields'][$field],0,3)=='int') { 
        $sql = 'INSERT INTO dbCountTable SELECT \''.$tName.'\',\''.$field.'\','.$field.',COUNT(*) FROM '.$tName.' GROUP BY '.$field;
        $rs = $db->query($sql);
      }
    }
  }
}
  
function dbScheme_validateForeignKeys($db,$dbParsed) {
  foreach ($dbParsed as $tName=>$tDef) {
    $prim = $dbParsed[$tName]['indices']['primary'];
    foreach ($tDef['outLinks'] as $field=>$outLink) {
      $sql = 'SELECT '.$tName.'.'.$prim[0].','.$tName.'.'.$field.' FROM '.$tName.' LEFT JOIN '.$outLink[0].' ON '.$outLink[0].'.'.$outLink[1].'='.$tName.'.'.$field.' WHERE '.$outLink[0].'.'.$outLink[1].' IS NULL';
      $rs = $db->query($sql);
      if ($rs) {
        $T = dbQuery_rs2struct($rs,FALSE,FALSE);
        $numInvalid = count($T['data']);
        if ($numInvalid>0) {
          echo $tName.'.'.$field.' contains '.$numInvalid.' invalid foreign keys.<br/>'."\n";
          echo formatAs_htmlTable($T['fields'],$T['data'],$tName.'.'.$field).'<br/><br/>'."\n";
        }
      }
    }
  }
}

function dbScheme_createViews($dbScheme,$db) {
  $views = @$dbScheme['views'];
  if (!isset($views)) return;
  foreach ($views as $vName=>$vSpec) {
    $sql = 'CREATE OR REPLACE VIEW '.$vName.' AS '.$vSpec[0];
    echo $sql.'<br/>';
    $params = $vSpec[1];    
    $rs = dbQuery_namedParameters($db,$sql,$params);
  }
}

function dbScheme_createTables($dbScheme,$db=NULL,$dropIfExists=FALSE) {
  $ans = array(); // return all table create statements
  $dbParsed = dbScheme_parse($dbScheme);
  foreach ($dbParsed as $tName=>$tSpec) {  
    $isView = isset($tSpec['createView']);
    if (!$isView) {
      $colDefs  = dbScheme_tableColDefs($tName,$tSpec);
      // only proceed if $db is set    
      if (isset($db)) dbQuery_prepareTable($db,$tName,$colDefs,$dropIfExists);
      $ans[$tName] = $colDefs;    
    }
  }
  return $ans;
}
?>
