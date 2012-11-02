<?php
namespace Banister;

const TSV_MIME_TYPE = "text/plain";

class TsvFilter
{
  private $_data;

  public function __construct($dataSource) {
    $this->_data = $dataSource;
  }

  public function output() {
    $val = array();
    foreach ($this->_data as $v) {
      $val[] = $v;
    }
    return implode("\t", $val);
  }
}