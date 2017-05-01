<?php
# what is a view exactly,
# what about a table with multiple view fields?
# as long as it is a table...

class fancyCachedView {
  function __construct($db,$table) {
    $db = $db;
    $table = $table;
    $sql = 'SELECT GREATEST(last_update) FROM FANCY_Dependencies LEFT JOIN FANCY_LastUpdate ON FANCY_LastUpdate.table=FANCY_Dependencies.table'
    $lastUpdate = verySql_getLastUpdate($db,$table)
    $dependencies = verySql_getDependencies($db,$table)
  }
  
  function update() {
    $lastUpdate = verySql_getLastUpdate($db,$this->table)
  }
}
?>
