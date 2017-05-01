<?php

class searchWizard_class {
  public $dbParsed = null;
  public $progress = array();
  protected $db;
  
  function __construct($dbParsed,$presets=array(),$request,$callback=NULL) {
    $this->dbParsed = $dbParsed;
    $this->presets = $presets;
    $this->request = isset($request) ? $request : $_REQUEST;
    if (isset($this->request['T0'])) $this->request['T'] = $this->request['T0'];
    $this->callback = $callback; // optional: the callback url that powers dynamic html output
  }

  function setDatabase($db) {
    $this->db = $db;
  }

  function rawValue($key) {
    $v = @$this->presets[$key];
    if (!isset($v)) $v = @$this->request[$key];
    return $v;
  }

  function getValue($key,$ff,$required=TRUE) {
    $v = $this->rawValue($key);
    if (isset($v)) {
      // TODO: keep track of request parameters that are actually used
    } else {
      if (!$required) return NULL;
    }
    $err = $ff->parseAndValidate($v);
    if (is_string($err)) formatAs_error($err);
    return $v;
  }

  /*
   * add field to the form, depending on the wizard progress
   */
  function getNextFieldAndValue(&$formFields,&$formValues, $type,$parent,$c,$required=TRUE) {
    $key = $parent.$type.$c;
    $val = $this->rawValue($key);
    if (isset($this->request[$key])) $this->progress[] = $key;
    $prefix = ($parent ? str_repeat('.&nbsp;',strlen($parent)) : '');
    switch ($type) {
      case 'T': 
        if ($parent == '') {
          $fieldLabel = $prefix.'Type of data to search for';
          $choices = array();
          foreach ($this->dbParsed as $tName=>$tSpec) {
            $name = @$tSpec['name'];
            $choices[$tName] = isset($name) ? $name : $tName;
          }
        } else {
          $pk = preg_replace('/R(\d+)$/','L\1',$parent);
          $table = $formValues[$pk.'.table'];
          $field = $formValues[$pk.'.field'];
          $fieldLabel = $prefix.'Table.field that links to '.$table;

          $links = $this->dbParsed[$table]['inLinks'];
          $choices = array();
          foreach ($links as $lnk=>$f) {          
            if ($field == $f) $choices[$lnk] = $lnk;
          }
        }
        $ff = new selectField_class($fieldLabel);
        //if (isset($val)) $choices = array($val => $choices[$val]);
        $ff->setChoices($choices);
        if (isset($val)) {
          // decompose T in table and foreign key
          $dotPos = strrpos($val,'.');
          if ($dotPos !== FALSE) {
            $formValues[$parent.'T.table'] = substr($val,0,$dotPos);
            $formValues[$parent.'T.key'] = substr($val,$dotPos+1);
          } else {
            $formValues[$parent.'T.table'] = $val;
          }
        }
        break;
      case 'G':
      case 'L':
        $T = $formValues[$parent.'T.table'];
        list($allFields,$path2table) = dbScheme_allFields($this->dbParsed,$T);  
        $vecProps = ($type=='L');
        $choices = dbScheme_allFieldsChoices($this->dbParsed,$allFields,$path2table,$vecProps);
        // note that multiple constraints all use the same table T
        $ff = new selectField_class($prefix.($type == 'L' ? 'Property to limit search by':'Property to group results by'));
        $ff->setChoices($choices);
        if (isset($val)) {
          $dotPos = strpos($val,'.');
          if ($dotPos !== FALSE) {
            $field = substr($val,$dotPos+1);
            $path = substr($val,0,$dotPos);
            $table = $path2table[$path];
          } else {
            // no field implies that a vector property is selected
            $path = $val;
            $table = $path2table[$path];
            $field = implode(',',$this->dbParsed[$table]['indices']['primary']);
            $this->presets[$parent.'op'.$c] = 'IN';
          }
          $formValues[$parent.'L'.$c.'.path'] = $path;
          $formValues[$parent.'L'.$c.'.table'] = $table;
          $formValues[$parent.'L'.$c.'.field'] = $field;
        }
        break;    
      case 'op':
        $table = $formValues[$parent.'L'.$c.'.table'];
        $field = $formValues[$parent.'L'.$c.'.field'];

        // detect oversized columns
        $fType = strtolower($this->dbParsed[$table]['fields'][$field]);
        $sz = 0;
        $lPos = strpos($fType,'(');
        $rPos = strpos($fType,')');
        if ($rPos > $lPos) {
          $fType = substr($fType,$lPos);
          $sz = substr($fType,$lPos+1,$rPos-$lPos-1);
        }
        $maxFieldSize = 100;
        $trunc = 0;
        if ($fType == 'text' || $fType == 'longtext' || $sz > $maxFieldSize) $trunc = $maxFieldSize;
        $ff = new selectField_class($prefix.'Use operator');
        $choices = array('eq' => 'equals'.($trunc ? ' (first '.$trunc.' characters)' : ''),'neq'=>'not equals','lt'=>'less than','lte'=>'less than or equal','gt'=>'greater than','gte'=>'greater than or equal','*x*'=>'contains','x*'=>'starts with','*x'=>'ends with','IN'=>'IN (subquery)','any'=>'equals any list member');
        //,'is0','is null','not0','is not null'
        $ff->setChoices($choices,'eq');

        $formValues[$parent.'op'.$c.'.trunc'] = $trunc;
        break;
      case 'func':
        $ff = new selectField_class($prefix.'Function to apply to each group');
        $choices = array('COUNT'=>'count items','MIN'=>'minimum value','MAX'=>'maximum value','SUM'=>'sum of all values','AVG'=>'mean of all values','GROUP_CONCAT'=>'string-concatenate values');
        $ff->setChoices($choices,'COUNT');
        break;
      case 'R':
        $ff = new selectField_class($prefix.'Use value');
        $op = $formValues[$parent.'op'.$c];
        if ($op == 'eq' || $op == 'neq') {
          $path = $formValues[$parent.'L'.$c.'.path'];
          $table = $formValues[$parent.'L'.$c.'.table'];
          $field = $formValues[$parent.'L'.$c.'.field'];
          $trunc = $formValues[$parent.'op'.$c.'.trunc'];
          $fullField = $table.'.`'.$field.'`';
          if ($trunc) {
            $fullField = 'LEFT('.$fullField.','.$trunc.')';
            $q = 'SELECT LEFT('.$fullField.','.$trunc.'), LENGTH('.$fullField.')';
          }  else {
            $q = 'SELECT '.$fullField;
          }
          $q .= ' FROM '.$table;
          $params = array();
          if (isset($val)) {
            $q .= ' WHERE '.($trunc ? 'LEFT('.$fullField.','.$trunc.')' : $fullField).($op=='eq' ? '=':'!=').'$R';
            $params['R'] = $val;
          } else {
            $T = $formValues[$parent.'T.table'];
            $q .= dbScheme_allFieldsChoices_where($this->dbParsed, $T,$path);
          }
          $q .= ' ORDER BY '.$fullField;
          $rs = dbQuery_webSafe($this->db,$q,$params);
          $choices = array();
          if ($trunc) {
            while ($row = $rs->fetch_row()) {
              $k = $row[0];
              $choices[$k] = $k.($row[1]>$trunc ? ' (...)' : '');
            }
          } else {
            while ($row = $rs->fetch_row()) {
              $k = $row[0];
              $choices[$k] = $k;
            }
          }
          $ff->setChoices($choices);
        } elseif ($op == '*x*' || $op == '*x' || $op == 'x*') {
          $ff = new textField_class($prefix.'Substring');
        } elseif ($op == 'any') {
          $ff = new textField_class($prefix.'JSON-encoded list',array(),0,65536);
        } else {
          $ff = new textField_class($prefix.'Use expression');
        }
        break;
      case 'x':
        if ($c == 0) {
          $ff = new selectField_class($prefix.'Apply a constraint');
          $ff->setChoices(array(''=>'No','WHERE'=>'Yes'),'WHERE');
        } else {
          $ff = new selectField_class($prefix.'Add another constraint');
          $ff->setChoices(array(''=>'No','GROUP'=>'No, but group results to compute statistics','AND'=>'Yes, combine using AND','OR'=>'Yes, combine using OR (after AND)','OR!'=>'Yes, combine using OR (before AND)'),'');
        }
        break;
      case 'limit':
        $ff = new numField_class($prefix.'Limit number of results to',array(),1,100000);
        $ff->setDefault(100);
        break;
      case 'page':
        $ff = new numField_class($prefix.'Start at result page',array(),1,10000);
        $ff->setDefault(1);
        break;
      case 'format':
        $ff = new selectField_class($prefix.'Output format');
        $ff->setChoices(array('json'=>'raw data (json)','dhtml'=>'dynamic table','html'=>'static tables','csv'=>'comma-separated quoted values','count'=>'result count','keys' => 'primary keys only (json)','altkeys' => 'primary and alternate keys (json)','post'=>'-'),'dhtml');
        break;
    }  
    if (isset($val)) {
      // freeze the form field, it already got a value
      $ff->setDefault($val);
      $ff->setReadOnly(TRUE);
      $err = $ff->parseAndValidate($val);
      if (is_string($err)) formatAs_error($err);
      if (!isset($formValues[$key])) $formValues[$key] = $val;
    }
    else if ($required) formatAs_error('Missing value for required field '.$key);
    $formFields->addField($key,$ff);
    return $val;
  }

  function dhtmlPage($result,$dbLayout,$customScript=NULL) {
    echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">';
    echo '<html><head>';
    echo '<meta http-equiv="content-type" content="text/html; charset=UTF-8">';
    echo '<script type="text/javascript" src="../shared-js/browser.js"></script>';
    echo '<script type="text/javascript" src="../shared-js/dbViewer.js"></script>';
    if ($customScript) echo '<script type="text/javascript" src="'.$customScript.'"></script>';
    echo '<script>function dbView(elemId) { dbViewer = new dbViewer_class("dbViewer",'.json_encode($result).','.json_encode($dbLayout).',elemId,\''.$this->callback.'\'); }</script>';
    echo '</head><body onload="dbView(\'dbView\')">';
    echo '<h1 class="db">Query, generated by search wizard</h1>'.formatAs_prettyJson($result['query'],TRUE);
    echo '<h1 class="db">Response</h1>';
    echo 'Click a row in the results table to view/hide details.';
    echo '<div id="dbView"></div></body></html>';
  }

  function minimalQuery(&$mqParams, $formValues,$parent='') {
    // list of paths that must be included for where-clause
    $wherePaths = array();
    $c = 0;
    while (isset($formValues[$parent.'L'.$c])) {
      $path = $formValues[$parent.'L'.$c.'.path'];
      // ignore empty path (=main table)
      if ($path != '') $wherePaths[] = $path;
      $c++;
    }

    // get the FROM part of the minimal query that includes all where-clause paths
    list($allPaths,$path2table) = dbScheme_allPaths($this->dbParsed, $formValues[$parent.'T.table']);
    list($joinedTables,$path2as) = dbScheme_allPathsQuery_joinedTables($this->dbParsed,$allPaths,$path2table, $wherePaths);
    
    $sqlFrom = ' FROM '.$joinedTables;  
    // add constraints to the search query
    $c = 1;
    $lastClose = 0;
    while (isset($formValues[$parent.'L'.$c])) {
      $x = $formValues[$parent.'x'.$c];
      if ($x == 'OR!') {
        $formValues[$parent.'x'.($c-1)] .= '(';
        $formValues[$parent.'x'.$c] = 'OR';
        $formValues[$parent.'x'.($c+1)] = ')'.$formValues[$parent.'x'.($c+1)];
      }
      $c++;
    }
    $c = 0;
    $sqlWhere = '';
    while (isset($formValues[$parent.'L'.$c])) {
      $L = $formValues[$parent.'L'.$c];
      $table = $formValues[$parent.'L'.$c.'.table'];
      $path = $formValues[$parent.'L'.$c.'.path'];
      $field = $formValues[$parent.'L'.$c.'.field'];
      $L = $path2as[$path].'.'.$field;
      $op = $formValues[$parent.'op'.$c];
      $x = $formValues[$parent.'x'.($c)];
      if ($op == 'IN') {
        list($inFrom,$inWhere) = $this->minimalQuery($mqParams, $formValues,$parent.'R'.$c);
        $fkey = $formValues[$parent.'R'.$c.'T.key'];
        $q = 'SELECT T0.'.$fkey.$inFrom.' '.$inWhere;
        $sqlWhere .= ' '.$x.' '.$L.' IN ('.$q.')';
      } elseif ($op == 'any') {
        $R = $formValues[$parent.'R'.$c];
        $R = json_decode($R,TRUE);
        $sqlWhere .= ' '.$x.' '.$L.' IN (@'.$parent.'R'.$c.')';
        $mqParams[$parent.'R'.$c] = $R;
      } else {
        $R = $formValues[$parent.'R'.$c];
        if ($op == 'eq') $op = '=';
        elseif ($op == '*x*') { $op = ' LIKE '; $R = '%'.$R.'%'; }
        elseif ($op == '*x') { $op = ' LIKE '; $R = '%'.$R; }
        elseif ($op == 'x*') { $op = ' LIKE '; $R = $R.'%'; }
        elseif ($op == 'lt') $op = '<';
        elseif ($op == 'lte') $op = '<=';
        elseif ($op == 'gt') $op = '>';
        elseif ($op == 'gte') $op = '>=';
        $trunc = $formValues[$parent.'op'.$c.'.trunc'];
        //if ($x == 'AND') $x = ($c==0 ? 'WHERE' : 'AND');
        $sqlWhere .= ' '.$x.' '.($trunc ? 'LEFT('.$L.','.$trunc.')' : $L).$op.'$'.$parent.'R'.$c;
        $mqParams[$parent.'R'.$c] = $R;
      }
      $c++;
    }
    return array($sqlFrom,$sqlWhere);
  }
  
  function scalarPropertiesForm(&$formFields,&$formValues,&$submitText,$parent='') {
    // prepare the form field
    $T = $this->getNextFieldAndValue($formFields,$formValues, 'T',$parent,'',FALSE);
    $submitText = 'Next...';
    if (!empty($T)) {
      $x = '';
      for ($c=0; $c<100; $c++) {
        $x = $this->getNextFieldAndValue($formFields,$formValues, 'x',$parent,$c,FALSE);
        if (!isset($x)) {
          break;
        } else {
          if ($x == '') break;
          if ($x == 'GROUP') {
            // group results
            $G = $this->getNextFieldAndValue($formFields,$formValues, 'G',$parent,$c,FALSE);
            if (!isset($G)) {
              break;
            } else {
              $func = $this->getNextFieldAndValue($formFields,$formValues, 'func',$parent,$c,FALSE);
              if (!isset($func)) {
                break;
              }
            }
            $x = ''; // done
            break;
          } else {
            // add constraint
            $L = $this->getNextFieldAndValue($formFields,$formValues, 'L',$parent,$c,FALSE);  
            if (!isset($L)) {
              break;
            } else {
              $op = $this->getNextFieldAndValue($formFields,$formValues, 'op',$parent,$c,FALSE);
              if (empty($op)) {
                break;
              } elseif ($op == 'IN') {
                $R = $this->scalarPropertiesForm($formFields,$formValues,$submitText,$parent.'R'.$c,FALSE);
                if (empty($R)) {
                  $submitText = 'Next (subquery)...';
                  break;
                }
              } else {
                $R = $this->getNextFieldAndValue($formFields,$formValues, 'R',$parent,$c,FALSE);
                if (!isset($R)) {
                  break;
                }
              }
            }
          }
        }
      }
      if ($x === '') {
        // query is done; now shape the output
        if ($parent == '') {
          $lim = $this->getNextFieldAndValue($formFields,$formValues, 'limit','','',FALSE);
          $pag = $this->getNextFieldAndValue($formFields,$formValues, 'page','','',FALSE);
          $fmt = $this->getNextFieldAndValue($formFields,$formValues, 'format','','',FALSE);
          if (!isset($lim) || !isset($fmt)) {
            $submitText = 'Submit query';
          } else {
            // all set, get results
            return TRUE;
          }
        } else {
          return TRUE;
        }
      }
    }
    return FALSE; // wizard not done yet
  }

  function output($ans,$type,$doEcho) {
    if (!$doEcho) return $ans;
    if ($type=='text') {
      formatAs_textHeaders();
      echo $ans;
    } elseif ($type=='json') {
      formatAs_jsonHeaders();
      echo $ans;
    }
  }
  
  function submitQuery($formValues,$customScript=NULL,$doEcho=True) {
    $format = $formValues['format'];
    $mqParams = array();
    list($sqlFrom,$sqlWhere) = $this->minimalQuery($mqParams, $formValues);

    // open or reuse the database
    $db = $this->db;

    // execute the "count total number of results" query
    $sql = 'SELECT COUNT(*)'.$sqlFrom.$sqlWhere;
    $rs = dbQuery_namedParameters($db,$sql,$mqParams);
    $totResults = dbQuery_rs2scalar($rs);
    
    if ($format == 'count') {
      // ready to return result count if that is all what's needed.
      return $this->output($totResults,'text',$doEcho);
    }
    
    // initialize answer
    $resultTable = $formValues['T.table'];
    $ans = array(
      'query' => array(),
      'resultTable' => $resultTable,
      'resultCount' => $totResults,
      'resultKeys'=> array(),
      'tables'=>array()
    );

    if ($format ==  'keys') {
      // direct query, no need for temporary table
      $pkeys = $this->dbParsed[$resultTable]['indices']['primary'];
      if (count($pkeys)>1) {
        $mqResult = 'SELECT CONCAT_WS($sep,T0.'.implode(',T0.',$pkeys).')'.$sqlFrom.$sqlWhere;
        $mqParams['sep'] = ',';
      } else {
        $mqResult = 'SELECT T0.'.$pkeys[0].$sqlFrom.$sqlWhere;
      }
      $rs = dbQuery_namedParameters($db,$mqResult,$mqParams);
      $resKeys = dbQuery_rs2columns($rs);
      $resKeys = isset($resKeys[0]) ? $resKeys[0] : array();
      $ans['query']['sql'] = $mqResult;
      $ans['query']['params'] = $mqParams;
      $ans['resultKeys'] = $resKeys;

      // ready to return keys if that is all what's needed.
      $ans = formatAs_prettyJson($ans);
      return $this->output($ans,'json',$doEcho);
    }

    // create empty temporary results tables
    if ($format == 'altkeys') {
      // altLinks contain only those outLinks needed to compose the alternate key
      $useLinkIndices = TRUE;
      $linkIdx = count($this->dbParsed[$resultTable]['altLinks'])-1;
    } else {
      $useLinkIndices = FALSE;    
      $linkIdx = NULL;
    }
    list($allPaths,$path2table,$path2linkIdx) = dbScheme_allPaths($this->dbParsed, $resultTable, $linkIdx);
    $tables = array_unique($path2table);
    $primKeys = array();
    $allKeys = array();
    foreach ($tables as $table) {
      $tDef = $this->dbParsed[$table];
      $pkeys = $tDef['indices']['primary'];
      // include all foreign keys, even if they may not be needed for an altKeys query
      $fkeys = array_keys($tDef['outLinks']);
      
      $pfkeys = array_unique(array_merge($pkeys,$fkeys)); // merge $pkeys and $fkeys
      $colDefs = array();
      foreach ($pfkeys as $k) {
        $colDefs[] = $k.' '.$tDef['fields'][$k];
      }
      $sql = 'CREATE TEMPORARY TABLE vsqlResult_'.$table.' ('.implode(',',$colDefs).',PRIMARY KEY ('.implode(',',$pkeys).'))';
      $rs = dbQuery_namedParameters($db,$sql,array());
      $primKeys[$table] = $pkeys;
      $allKeys[$table] = $pfkeys;
    }
    // execute the limited results query and store in temporary table
    $limit = $formValues['limit'];
    $page = $formValues['page'];
    if (!isset($page)) $page=1;
    $mqResult = 'SELECT T0.'.implode(',T0.',$allKeys[$resultTable]).$sqlFrom.$sqlWhere;
    $sql = 'INSERT INTO vsqlResult_'.$resultTable.' ('.$mqResult.' LIMIT '.($page-1)*$limit.','.$limit.')';
    $rs = dbQuery_namedParameters($db,$sql,$mqParams);
    
    // extract resultkeys of T0
    $sql = 'SELECT CONCAT_WS($sep,'.implode(',',$primKeys[$resultTable]).') FROM vsqlResult_'.$resultTable;
    $params = array('sep'=>',');
    $rs = dbQuery_namedParameters($db,$sql,$params);
    $resKeys = dbQuery_rs2columns($rs);
    $resKeys = isset($resKeys[0]) ? $resKeys[0] : array();
    $ans['query']['sql'] = $mqResult;
    $ans['query']['params'] = $mqParams;
    $ans['resultKeys'] = $resKeys;

    // find paths which point to a given table
    // TODO: table2paths is not used, create joinsToGo directly
    $table2paths = array();
    foreach ($path2table as $path=>$table) {
      if (isset($table2paths[$table])) {
        $table2paths[$table][] = $path;    
      } else {
        $table2paths[$table] = array($path);
      }
    }

    // count how often each table will be joined with a child table
    $joinsToGo = array();
    foreach ($table2paths as $table=>$paths) {
      $joinsToGo[$table] = count($paths);
    }

    $table = $path2table[''];
    $pathOpen = array(''=>TRUE);
    $joinsToGo[$table]--;
    $toGoBypass = 0;
    $loopCount = 0; // just in case: prevent infinite loop
    while (count($pathOpen)>0 && $loopCount++ < 1000) {
      $numJoins = 0;
      foreach ($pathOpen as $parentPath=>$status) {
        $parent = $path2table[$parentPath];
        // a join is ready if the parent table is complete. i.e. has zero joins to go
        if ($joinsToGo[$parent] <= $toGoBypass) {
          if ($useLinkIndices) {
            $altLinks = $this->dbParsed[$parent]['altLinks'][$path2linkIdx[$parentPath]];
            $outLinks = $this->dbParsed[$parent]['outLinks'];
            $parentLinks = array();
            foreach ($altLinks as $k=>$toLink) $parentLinks[$k] = $outLinks[$k];
          } else {
            $parentLinks = $this->dbParsed[$parent]['outLinks'];
          }
          $table2lnks = array();
          foreach ($parentLinks as $lnk=>$toTable) {
            $table2lnks[$toTable][] = $lnk;
          }
          $done = array();
          // for all open paths with the same parent table ...
          foreach ($pathOpen as $sameParentPath=>$status) {
            if ($path2table[$sameParentPath] == $parent) {
              foreach ($table2lnks as $table=>$lnks) {
                if (!isset($done[$table])) {
                  // DO THE JOIN
                  $pkeys0 = $primKeys[$parent];
                  $pkeys = $primKeys[$table];
                  $pfkeys = $allKeys[$table];
                  foreach ($lnks as $lnk) {
                    // TODO: SUPPORT MULTIPLE PRIMARY KEYS
                    $fromTable = 'vsqlResult_'.$parent;
                    if ($parent == $table) {
                      $sql = 'DROP TEMPORARY TABLE IF EXISTS vsqlTemp';
                      $rs = dbQuery_namedParameters($db,$sql,array());
                      $sql = 'CREATE TEMPORARY TABLE vsqlTemp AS SELECT * FROM vsqlResult_'.$table;
                      $rs = dbQuery_namedParameters($db,$sql,array());
                      $fromTable = 'vsqlTemp';
                    }
                    $sql = 'INSERT IGNORE INTO vsqlResult_'.$table.' SELECT DISTINCT T1.'.implode(',T1.',$pfkeys).' FROM '.$fromTable.' AS T0 INNER JOIN '.$table.' AS T1 ON (T1.'.$pkeys[0].'=T0.'.$lnk.')';
                    $rs = dbQuery_namedParameters($db,$sql,array());
                    $numJoins++;
                  }
                  $done[$table] = TRUE;
                }
                // loop through outLinks that reference the same table                
                foreach ($lnks as $lnk) {
                  $path = $sameParentPath.'^'.$lnk;
                  if (isset($path2table[$path])) {
                    $pathOpen[$path] = TRUE;
                    $joinsToGo[$table]--;                
                  }
                }
              }
              unset($pathOpen[$sameParentPath]);
            }
          }
          // essential to break here, php chokes on unsetting array members in nested loops
          break;
        }
      }
      // There can be a deadlock if no table is complete yet.
      // In that case, increase the toGoBypass;
      if ($numJoins == 0) {
        $toGoBypass++;
      } else {
        $toGoBypass = 0;
      }
    }

    // read results from temporary tables
    $doCount = ($format == 'dhtml' || $format == 'post' && $doEcho);
    foreach ($tables as $table) {
      $tDef = $this->dbParsed[$table];
      $pkeys = $tDef['indices']['primary'];
      // use all fields, even though altkeys may not use them
      $fields = array_keys($tDef['fields']);

      // query includes support for composite primary keys
      $onClause = $pkeys;
      foreach ($onClause as &$k) $k = 'T0.'.$k.'='.'T1.'.$k;
      $sql = 'SELECT T1.'.implode(',T1.',$fields).' FROM vsqlResult_'.$table.' AS T0 LEFT JOIN '.$table.' AS T1 ON '.implode(' AND ',$onClause);
      $rs = dbQuery_namedParameters($db,$sql,array());
      $T = dbQuery_rs2struct_preset($rs,$fields,$pkeys);

      if ($doCount) {
        // count, for each inLink (vector properties), how many members are available for each key
        foreach ($tDef['inLinks'] as $inLink=>$pkey) {
          $dotPos = strpos($inLink,'.');
          $ftable = substr($inLink,0,$dotPos); 
          $fkey = substr($inLink,$dotPos+1);
          // TO DO: works but only for integer links and does not auto-update. Use triggers and stored procedures.
          $sql = 'SELECT ftable.fkey,ftable.count FROM vsqlResult_'.$table.' AS T0 INNER JOIN dbCountTable AS ftable ON T0.'.$pkey.'=ftable.fkey WHERE ftable.tbl=$ftable AND ftable.fld=$field';
          try {
            $rs = dbQuery_namedParameters($db,$sql,array('ftable'=>$ftable,'field'=>$fkey));
            $C = dbQuery_rs2columns($rs);
          } catch (Exception $e) {
            $C = array();
          }
          if (count($C) === 0) {
            // empty result returned; try the safe & slow way
            $sql = 'SELECT ftable.fkey,ftable.n FROM vsqlResult_'.$table.' AS T0 INNER JOIN (SELECT '.$fkey.' AS fkey,COUNT(*) AS n FROM '.$ftable.' GROUP BY '.$fkey.') AS ftable ON T0.'.$pkey.'=ftable.fkey';
            $rs = dbQuery_namedParameters($db,$sql,array());
            $C = dbQuery_rs2columns($rs);
          }
          $T['collections'][$inLink]['count'] = count($C) ? array_combine($C[0],$C[1]) : array();
          $T['collections'][$inLink]['keys'] = array();
        }
      }
      $T['links'] = $tDef['outLinks'];
      
      $ans['tables'][$table] = $T;
      // clean up temporary table
      $sql = 'DROP TEMPORARY TABLE vsqlResult_'.$table;
      $rs = dbQuery_namedParameters($db,$sql,array());
    }

    if ($format == 'altkeys') {
      dbScheme_addAlternateKeys($this->dbParsed, $ans,$linkIdx);
      $ans = formatAs_prettyJson($ans['tables'][$resultTable]['altKeys']);
      return $this->output($ans,'json',$doEcho);
    }

    if ($format == 'csv') {
      // dbScheme_addAlternateKeys($this->dbParsed, $ans,$linkIdx);
      // TODO: save all 'asCell' alternate keys of the results table as additional rows ^ID_BrainSite
      $fields = $ans['tables'][$resultTable]['fields'];
      $T = array();
      $data = $ans['tables'][$resultTable]['data'];
      foreach ($ans['resultKeys'] as $key) {
        $row = $data[$key];
        foreach ($row as &$v) $v = isset($v) ? '\''.$db->real_escape_string($v).'\'' : 'NULL';
        unset($v);
        $T[] = implode(',',$row);
      }
      $ans = "'".implode("','",$fields)."'\n".implode("\n",$T);
      return $this->output($ans,'text',$doEcho);
    }

    // post could be renamed to dhtml_request
    if ($format=='post') {
      return $ans;
    } elseif ($format=='json') {
      $ans = formatAs_prettyJson($ans);
      return $this->output($ans,'json',$doEcho);
    } elseif ($format=='dhtml') {
      $dbLayout = dbScheme_parseLayout($this->dbParsed);
      $this->dhtmlPage($ans,$dbLayout,$customScript);
    } else {
      // old html style, deprecated
      $linkPrefix = 'JUMPTO_';
      echo '<html><head><style type="text/css">h1{font-size:160%}h2{width:100%;border-top:8px solid #ddd;padding:3px;font-size:120%}tr.highlight{background:#9F9}</style><script type="text/javascript">HIGHLIGHT=null; function getTop(element){var pos=0;do pos+=element.offsetTop;while(element=element.offsetParent); return pos};function jumpTo(id) { if (HIGHLIGHT) HIGHLIGHT.className=null; elem=document.getElementById(id); if (elem) { window.scrollTo(0,getTop(elem)-100);elem.className="highlight";HIGHLIGHT=elem}}</script></head><body>';
      echo '<h1>Query, generated by search wizard</h1>'.formatAs_prettyJson($ans['query'],TRUE);
      echo '<h1>Response</h1>';
      foreach ($ans['tables'] as $table=>$T) {
        echo '<h2>'.$table.'</h2>';
        $field2idx = array_flip($T['fields']);
        $idx2link = array();      
        foreach ($T['links'] as $f=>$lnk) {
          $idx = @$field2idx[$f]; // if $f is a key, then field $f may be unset
          if (isset($idx)) $idx2link[$idx] = $lnk;
        }
        foreach ($T['data'] as $id=>&$row) {
          foreach ($idx2link as $i=>$link) {
            $linkId = $linkPrefix.$link.'['.$row[$i].']';
            $row[$i] = '<a href="javascript:jumpTo(\''.$linkId.'\')">'.$row[$i].'</a>';
          }
        }
        echo formatAs_htmlTable($T['fields'],$T['data'],$linkPrefix.$table);
      }
      echo '</body></html>';
    }
    return TRUE;
  }
  
  // call scalarPropertiesForm first
  function goBackURL() {
    $lastKey = end($this->progress);
    $request = $this->request;
    if (isset($lastKey)) unset($request[$lastKey]);
    $prot = strtolower($_SERVER['SERVER_PROTOCOL']);
    $slashPos = strpos($prot,'/');
    if ($slashPos) $prot = substr($prot,0,$slashPos);
    return $prot.'://'.$_SERVER['SERVER_NAME'].($_SERVER['SERVER_PORT'] != 80 ? ':'.$_SERVER['SERVER_PORT'] : '').$_SERVER['SCRIPT_NAME'].'?'.http_build_query($request);
  }
}
?>
