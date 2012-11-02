<?php
namespace Banister;

const CSV_MIME_TYPE = "text/plain";

class CsvFilter
{
  private $_data;

  public function __construct($dataSource) {
    $this->_data = $dataSource;
  }

  public function output() {
    $val = array();
    foreach ($this->_data as $v) {
      $val[] = '"' . str_replace('"', '\\"', $v) . '"';
    }
    return implode(",", $val);
  }
}