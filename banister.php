<?php
/** Banister RESTful API Services Core for PHP 5. **/ 
namespace Banister;

function run($name) {
  return new Rest($name);
}

class Rest
{
  // settings from .banister file
  private $_settings;

  // current request
  private $_requestUri;
  private $_requestedRoute;
  private $_requestedMethod;

  // response attributes
  private $_responseFormat;
  private $_responseMimeType;
  private $_responseHandler;

  // supported output formats
  private $_availableFilters = array();

  // supported database engines
  private $_availableDatabases = array();

  // initializes the REST API, given a config file.
  public function __construct($settingsFile) {
    if (!isset($settingsFile)) {
      // stop processing, it'll be useless.
      die("No configuration file specified. Banister stopped.");
    }

    // read configuration
    $this->_settings = json_decode(file_get_contents(__DIR__ . "/config/" . $settingsFile . ".banister"));

    // take current request
    $this->_requestUri = $_SERVER["REQUEST_URI"];

    if ($this->_requestUri == "/") {
      // stop processing, it'll be useless.
      die("Welcome.");
    }

    $this->_load("filters");
    $this->_load("databases");
  }

  // gets a setting from the .bannister file in the object.property form.
  private function _getSetting($setting) {
    if (strpos($setting, ".") === false) {
      if (isset($this->_settings->$setting)) {
        return $this->_settings->$setting;
      } else {
        // property not exists, get out.
        return -1;
      }
    } else {
      $s = explode(".", $setting);
      $v = $this->_settings;
      // go deeper and deeper!
      foreach ($s as $a => $b) {
        if (isset($v->$b)) {
          $v = $v->$b;
        } else {
          // hold it, you asked something wrong, get out.
          $v = -1;
          break;
        }
      }
      return $v;
    }
  }

  // load extension objects.
  private function _load($what) {
    foreach (glob(__DIR__ . "/$what/*.php") as $object) {
      require_once $object;
      $objectId = str_replace(".php", "", basename($object));
      $className = "Banister\\" . ucfirst($objectId) . ucfirst(substr($what, 0, strlen($what) - 1));
      if (class_exists($className)) {
        array_push(&$this->{"_available" . ucfirst($what)}, $objectId);
      } else {
        die(ucfirst($what) . " $object lacks a " . ucfirst($objectId) . ucfirst($what) . " class.");
      }
    }
  }

  // finds out what route is being requested now.
  private function _getRequestedRoute() {
    $routeFound = false;
    $method = $_SERVER["REQUEST_METHOD"];
    $uri = explode("/", $this->_requestUri);
    foreach ($this->_getSetting("routes") as $r) {
      $routeMethodHash = explode(" ", $r);
      $route = explode("/", $routeMethodHash[1]);
      $matches = true;
      foreach ($route as $i => $part) {
        if (strpos($part, "{") === false) {
          $matches &= $part == $uri[$i];
        }
      }
      if ($matches && count($route) == count($uri)) {
        // we found it!
        $this->_requestedMethod = $routeMethodHash[0];
        $this->_requestedRoute = $routeMethodHash[1];
        $routeFound = true;
        break;
      }
    }
    if (!$routeFound) {
      die("Route {$this->_requestUri} not found.");
    }
    if ($this->_requestedMethod != $method) {
      die("Route {$this->_requestUri} cannot be accessed via {$method}.");
    }
  }

  // get mime type for response
  private function _detectResponseFormat() {
    $matches = array();
    $formats = "/\\.(" . implode("|", $this->_availableFilters) . ")$/";
    preg_match($formats, $this->_requestUri, $matches);      
    if ($this->_getSetting("defaultFormat") != -1) {
      $defaultFormat = $this->_getSetting("defaultFormat");
      if (!in_array($defaultFormat, $this->_getSetting("outputFormats"))) {
        die("Default output format must be included in the available output formats for the application.");
      } else if (!in_array($defaultFormat, $this->_availableFilters)) {
        die("Default output format is not supported by the Banister engine.");
      }
      if (count($matches) === 0)
      {
        $this->_responseFormat = $defaultFormat;
      }
      else
      {
        if (count($matches) === 0) {
          die("Format not supported by the Banister engine.");
        }
        if (!in_array($matches[1], $this->_getSetting("outputFormats"))) {
          die("Format not supported for this application.");
        }
        $this->_responseFormat = $matches[1];
      }
    } else {
      if (count($matches) === 0) {
        die("Format not supported by the Banister engine.");
      }
      if (!in_array($matches[1], $this->_getSetting("outputFormats"))) {
        die("Format not supported for this application.");
      }
      $this->_responseFormat = $matches[1];
    }
    $responseMimeTypeConstant = strtoupper($this->_responseFormat) . "_MIME_TYPE";
    $this->_responseMimeType = constant("Banister\\$responseMimeTypeConstant");
  }

  // echoes the mime header
  private function _setResponseFormat() {
    header("Content-Type: " . $this->_responseMimeType);
  }

  // finds out which service is being requested
  private function _getApiMethodName() {
    $nameArray = array();
    foreach (explode("/", $this->_requestedRoute) as $part) {
      if (strpos($part, "{") === false) {
        $nameArray[] = $part;
      }
    }
    $name = strtolower($this->_requestedMethod)
      . str_replace(" ", "", ucwords(implode(" ", array_filter($nameArray))));
    return $name;
  }

  // build context object, with route parameters and database link
  private function _getContext() {
    $uri = explode("/", $this->_requestUri);
    $route = explode("/", $this->_requestedRoute);
    
    // get parameters
    $params = array();
    foreach ($uri as $i => $part) {
      if (strpos($route[$i], "{") !== false) {
        $paramName = str_replace("{", "", str_replace("}", "", $route[$i]));
        $params[$paramName] = $part;
      }
    }

    // get database connection
    $dbClass = "\\Banister\\" . ucfirst($this->_getSetting("database.vendor")) . "Database";
    $db = new $dbClass(
      $this->_getSetting("database." 
        . $this->_getSetting("currentEnvironment")
      )
    );
    $db->connect();

    $context = new \stdClass();
    $context->params = $params;
    $context->db = $db;

    return $context;
  }

  // binds the route to the controller
  private function _setResponseHandler() {
    $filterHandlerClass = "Banister\\" . ucwords($this->_responseFormat) . "Filter";
    $responseHandleMethod = $this->_getSetting("appNamespace") . "\\Rest\\" . $this->_getApiMethodName();
    $this->_responseHandler = new $filterHandlerClass(
      $responseHandleMethod($this->_getContext())
    );    
  }

  // echoes the result
  private function _respond()
  {
    echo $this->_responseHandler->output();
  }

  // main proc, does *everything*
  public function handleRequest()
  {
    $this->_getRequestedRoute();
    $this->_detectResponseFormat();
    $this->_setResponseFormat();
    $this->_setResponseHandler();
    $this->_respond();
    return $this;
  }

  // kthxbai!
  public function terminate()
  {
    foreach ($this as $k => $p) {
      unset($this->{$k});
    } 
  }
}