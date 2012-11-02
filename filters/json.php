<?php
namespace Banister;

const JSON_MIME_TYPE = "application/json";

class JsonFilter
{
  private $_data;

  public function __construct($dataSource) {
    $this->_data = $dataSource;
  }

  public function output() {
    return json_encode($this->_data);
  }
}