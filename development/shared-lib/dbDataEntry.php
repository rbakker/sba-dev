<?php

class dataEntry_class {
  public $dbParsed = null;
  protected $db;
  protected $searchWizard;

  function __construct($dbParsed,$presets=array(),$request,$callback=NULL) {
    $this->dbParsed = $dbParsed;
    $this->presets = $presets;
    $this->request = isset($request) ? $request : $_REQUEST;
    // presets can also be passed by query parameter
    if (isset($this->request['presets'])) {
      $presets = json_decode($this->request['presets']);
      if (is_object($presets)) {
        foreach ($presets as $k=>$v) {
          $this->presets[$k] = $v;
        }
      } else formatAs_error('Query parameter "presets" must be a json_encoded array.');
    }
    $this->callback = $callback; // optional: the callback url that powers dynamic html output
  }

  function setDatabase($db) {
    $this->db = $db;
  }

  function rawValue($key) {
    $v = NULL;
    if (isset($this->presets[$key])) {
      $v = $this->presets[$key];
    } elseif (isset($this->request[$key])) {
      $v = $this->request[$key];
    }
    return $v;
  }

  function addTableFields($table, &$formFields,&$formValues) {
    $tableDef = $this->dbParsed[$table];
    $fields = $tableDef['fields'];
    $fieldFlags = $tableDef['fieldFlags'];
    $outLinks = $tableDef['outLinks'];
    $ok = TRUE;
    $sep = ',';
    $fieldValues = array();
    foreach ($fields as $f=>$def) {
      $f_f = 'f_'.$f;
      $val = $this->rawValue($f_f);
      if (isset($outLinks[$f])) {
        $ftable = $outLinks[$f];
        $ftableDef = $this->dbParsed[$ftable];
        $fpkeys = $ftableDef['indices']['primary'];
        if (count($fpkeys)>1) {
          $selectWhat = 'CONCAT_WS('.implode(',',$fpkeys).',$sep)';
        } else {
          $selectWhat = $fpkeys[0];
        }
        
        // use the search wizard to retrieve select options
        require_once('../shared-lib/dbSearchWizard.php');
        $request = array(
          'T'=>$ftable,
          'x0'=>'',
          'format'=>'altkeys'
        );
        $searchWizard = new searchWizard_class($this->dbParsed,array('limit'=>10000,'page'=>1,'format'=>'altkeys'),$request);
        $searchWizard->setDatabase($this->db);
        $formFields = new formfields_class();
        $submitText = '';
        $readyToSubmit = $searchWizard->scalarPropertiesForm($formFields,$request,$submitText);
        if ($readyToSubmit) {
          $ans = $searchWizard->submitQuery($request);
        } else {
          formatAs_error('Not ready to submit');
        }
echo htmlspecialchars(formatAs_prettyJson($ans,TRUE));
exit;
        $descriptiveKey = NULL;
        if (isset($ftableDef['descriptiveKey'])) {
          $descriptiveKey = $ftableDef['descriptiveKey'];
          if (is_array($descriptiveKey)) {
            $format = array_shift($descriptiveKey);
            $selectWhat .= $sep.implode($sep,$descriptiveKey);
          } else {
            $format = "%s";
            $selectWhat .= $sep.$descriptiveKey;
          }
        }
        $q = 'SELECT '.$selectWhat.' FROM '.$ftable.' LIMIT 200';
        $params = array('sep'=>$sep);
        $db = $this->dbReuse();
        $rs = dbQuery_namedParameters($db,$q,$params);
// replace this by a Search Query that uses format=altkeys
        $cols = dbQuery_rs2columns($rs);
        $choices = array(''=>'<select a value>');
        if ($descriptiveKey) {
          // so far, only a single descriptive key field is supported.
          $lastCol = count($cols)-1;
          foreach ($cols[0] as $i=>$k) {
            $choices[$k] = sprintf($format,$cols[$lastCol][$i]);
          }
        } else {
          foreach ($cols[0] as $k) {
            $choices[$k] = $k;
          }
        }
        $ff = new selectField_class($f);
        $ff->setChoices($choices);
      } else {
        $ff = new textField_class('Value of field '.$f);
      }
      // if $val is set, then fields have been submitted
      if (isset($val)) {
        $err = $ff->parseAndValidate($val);
        if ($err) {
          $ff->addError($err);
          $ok = FALSE;
        } else {
          // value is submitted ...
          if ($ff->isEmpty($val)) {
            // ... but it is empty
            $required = ($fieldFlags[$f] & DBSCHEME_NOTNULL);
            if ($required) {
              // empty value not OK for required fields
              $ff->addError('Required field.');
              $ok = FALSE;
            } else {
              // if empty value is equivalent to NULL, set to NULL
              if ($ff->isNull($val)) $val = NULL;
            }
          }
          $fieldValues[$f] = $val;
        }
        $ff->setDefault($val);
      } else {
        $ok = FALSE;
      }
      $formFields->addField($f_f,$ff);
    }
    $formValues['fields'] = $fieldValues;
    return $ok;
  }
  
  /*
   * add field to the form, depending on the wizard progress
   */
  function getNextFieldAndValue(&$formFields,&$formValues, $key) {
    $val = $this->rawValue($key);
    switch ($key) {
      case 'table': 
        $choices = array();
        foreach ($this->dbParsed as $tName=>$tSpec) {
          $name = @$tSpec['name'];
          $choices[$tName] = isset($name) ? $name : $tName;
        }
        $ff = new selectField_class('Table to insert item to');
        $ff->setChoices($choices);
        break;

/*        
      case 'fields':
        $table = $formValues['table'];
        $ff = new hiddenField_class('Fields to consider');
        $ff->setChoices(array('all'=>'all'));
        break;
      case 'L':
        $choices = array();
        $choices = dbScheme_allFieldsChoices($this->dbParsed,$this->allFields,$this->path2table);
        // note that multiple constraints all use the same table T0
        $ff = new selectField_class('Property to limit search by');
        $ff->setChoices($choices);
        break;    
      case 'x':
        if ($c == 0) {
          $ff = new selectField_class('Apply a constraint');
          $ff->setChoices(array(''=>'No','WHERE'=>'Yes'),'WHERE');
        } else {
          $ff = new selectField_class('Add another constraint');
          $ff->setChoices(array(''=>'No','AND'=>'Yes, combine using AND','OR'=>'Yes, combine using OR'),'');
        }
        list($this->allFields,$this->path2table) = dbScheme_allFields($this->dbParsed,$formValues[$parent.'T0']);
        break;
      case 'limit':
        $ff = new numField_class('Limit number of results to',array(),1,100000);
        $ff->setDefault(100);
        break;
      case 'page':
        $ff = new numField_class('Start at result page',array(),1,10000);
        $ff->setDefault(1);
        break;
      case 'format':
        $ff = new selectField_class('Output format');
        $ff->setChoices(array('json'=>'raw data (json)','dhtml'=>'dynamic table','html'=>'static tables','post'=>'-'),'dhtml');       
        break;
*/        
    }  

    if (isset($ff)) {
      if (isset($val)) {
        $ff->setDefault($val);
        // freeze the form field, it already got a value
        $ff->setReadOnly(TRUE);
        $err = $ff->parseAndValidate($val);
        if (is_string($err)) formatAs_error($err);
        $formValues[$key] = $val;
      }
      $formFields->addField($key,$ff);
    } 
    return $val;
  }

  function addItemForm(&$formFields,&$formValues,&$submitText) {
    // prepare the form fields
    $table = $this->getNextFieldAndValue($formFields,$formValues, 'table',FALSE);
    if (empty($table)) {
      $submitText = 'Next...';
    } else {
      $ok = $this->addTableFields($table,$formFields,$formValues);
      if ($ok) {
        $submitText = 'Submitted...';
        return TRUE;
      } else {
        $submitText = 'Submit...';
      }
    }
    return FALSE; // wizard not done yet
  }

  function editItemForm(&$formFields,&$formValues,&$submitText, $table,$key) {
    //...
  }
  
  function submitQuery($formValues,$customScript=NULL) {
    echo formatAs_prettyJson($formValues,TRUE);
    $table = $formValues['table'];
    $fields = array_keys($formValues['fields']);
    $sql = 'INSERT INTO '.$table.'('.implode(',',$fields).') VALUES ($'.implode(',$',array_keys($fields)).')';
    $params = array_values($formValues['fields']);
    echo $sql;
    echo formatAs_prettyJson($params,TRUE);
    $inLinks = $this->dbParsed[$table]['inLinks'];
    echo formatAs_prettyJson($inLinks,TRUE);
    echo 'Table '.$table.' can have children of the following types:<br/>';
    foreach ($inLinks as $lnk=>$pkey) {
      $dotpos = strpos($lnk,'.');
      $ftable = substr($lnk,0,$dotpos);
      echo '<input type="button" value="Add item"/> '.$ftable.'<br/>';
    }
  }
}
?>