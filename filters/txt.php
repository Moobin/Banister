<?php
namespace Banister;

const TXT_MIME_TYPE = "text/plain";

class TxtFilter
{
  private $_data;

  public function __construct($dataSource) {
    $this->_data = $dataSource;
  }

  private function escape($t) {
    $src = array("\r", "\n", '"');
    $dst = array("\\r", "\\n", '\\"');
    foreach ($src as $i => $v) {
      $t = str_replace($src[$i], $dst[$i], $t);
    }
    return $t;
  }

  public function output() {
    $val = array();
    foreach ($this->_data as $k => $v) {
      $val[] = "$k = \"" . $this->escape($v) . "\"";
    }
    return implode("\r\n", $val);
  }
}