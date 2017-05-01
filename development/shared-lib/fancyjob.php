<?php
class fancyjob_type {
  public $logfile = null;
  public $tempdir = null;
  public $workdir = null;

  function __construct($logfile,$workdir,$tempdir=NULL) {
    $this->logfile = $logfile;
    $this->workdir = $work;
    $this->tempdir = ($tempdir ? $tempdir : sys_get_temp_dir());
    if (!is_file($this->logfile)) {
      try {
        $this->log('');
      } catch (Exception $e) {
        echo 'Cannot open log file "'.$this->logfile.'" for writing.'."\n";
      }
    }
    if (!is_dir($this->workdir)) {
      try {
        mkdir($workdir);
      } catch (Exception $e) {
        echo 'Cannot mkdir "'.$this->workdir.'".'."\n";
      }
    }
    if (!is_dir($this->tempdir)) {
      try {
        mkdir($tempdir);
      } catch (Exception $e) {
        echo 'Cannot mkdir "'.$this->tempdir.'".'."\n";
      }
    }
  }
  
  function log($msg) {
    $fp = fopen($this->logfile,'w');
    $fp->write($msg);
    fclose($fp);
  }
}
?>
