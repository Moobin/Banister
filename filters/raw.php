<?php
namespace Banister;

const RAW_MIME_TYPE = "text/plain";

class RawFilter
{
  private $_data;

  public function __construct($dataSource) {
    $this->_data = $dataSource;
  }

  public function output() {
    return $this->_data;
  }
}