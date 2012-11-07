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

  // errors
  private $ERR_CONFIG_FILE_NOT_SPECIFIED = "ERR_CONFIG_FILE_NOT_SPECIFIED::400::No configuration file specified. Banister stopped.";
  private $ERR_CONFIG_FILE_NOT_FOUND = "ERR_CONFIG_FILE_NOT_FOUND::404::Configuration file not found.";
  private $ERR_NO_ROUTE = "ERR_NO_ROUTE::400::Route not specified.";
  private $ERR_EXTENSION_DEFINITION = "ERR_EXTENSION_DEFINITION::501::%s %s lacks a %s %s class.";
  private $ERR_ROUTE_NOT_FOUND = "ERR_ROUTE_NOT_FOUND::404::Route %s not found.";
  private $ERR_METHOD_NOT_ALLOWED = "ERR_METHOD_NOT_ALLOWED::403::Route %s cannot be accessed via %s.";
  private $ERR_INVALID_DEFAULT_OUTPUT_FORMAT = "ERR_INVALID_DEFAULT_OUTPUT_FORMAT::400::Default output format must be included in the available output formats for the application.";
  private $ERR_UNSUPPORTED_DEFAULT_OUTPUT_FORMAT = "ERR_UNSUPPORTED_DEFAULT_OUTPUT_FORMAT::400::Default output format is not supported by the Banister engine.";
  private $ERR_UNSUPPORTED_OUTPUT_FORMAT = "ERR_UNSUPPORTED_OUTPUT_FORMAT::400::Format not supported by the Banister engine.";
  private $ERR_OUTPUT_FORMAT_NOT_ALLOWED = "ERR_OUTPUT_FORMAT_NOT_ALLOWED::400::Format not allowed for this application.";
  private $ERR_MISSING_PARAMETER = "ERR_MISSING_PARAMETER::400::Parameter (%s)%s is required.";
  private $ERR_MISSING_CONTROLLER = "ERR_MISSING_CONTROLLER::404::No controller function found for route %s.";

  private function _abort($err) {
    list($code, $status, $message) = explode("::", $err);
    header(":", true, $status);
    die($err);
  }

  // initializes the REST API, given a config file.
  public function __construct($settingsFile) {
    if (!isset($settingsFile)) {
      // stop processing, it'll be useless.
      $this->_abort($this->ERR_CONFIG_FILE_NOT_SPECIFIED);
    }

    if (!file_exists(__DIR__ . "/config/" . $settingsFile . ".banister")) {
      $this->_abort($this->ERR_CONFIG_FILE_NOT_FOUND); 
    }

    // read configuration
    $this->_settings = json_decode(file_get_contents(__DIR__ . "/config/" . $settingsFile . ".banister"));

    // take current request
    $this->_requestUri = $_SERVER["REQUEST_URI"];

    if ($this->_requestUri == "/") {
      // stop processing, it'll be useless.
      if ($this->_getSetting("welcomeMessage") != -1) {
        $this->_abort($this->_getSetting("welcomeMessage"));
      } else {
        $this->_abort($this->ERR_NO_ROUTE);
      }
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
        $this->_abort(sprintf($this->ERR_EXTENSION_DEFINITION, ucfirst($what), $object, ucfirst($objectId), $object));
      }
    }
  }

  // finds out what route is being requested now.
  private function _getRequestedRoute() {
    $routeFound = false;
    $methodIsAllowed = false;
    $method = $_SERVER["REQUEST_METHOD"];
    $uri = explode("?", $this->_requestUri);
    foreach ($this->_getSetting("routes") as $r) {
      $route = $r->route;
      $methodIsAllowed = in_array($method, $r->methods);
      $isRoute = strcasecmp($route, $uri[0]) === 0;
      if (!$methodIsAllowed && $isRoute) {
        $this->_requestedRoute = $route;
        $routeFound = true;
      }
      if ($methodIsAllowed && $isRoute) {
        $this->_requestedMethod = $method;
        $this->_requestedRoute = $route;
        $this->_requestedRouteObject = $r;
        $routeFound = true;
        break;
      }
    }
    if (!$routeFound) {
      $this->_abort(sprintf($this->ERR_ROUTE_NOT_FOUND, $this->_requestUri));
    }
    if ($this->_requestedMethod != $method) {
      $this->_abort(sprintf($this->ERR_METHOD_NOT_ALLOWED, $this->_requestedRoute, $method));
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
        $this->_abort($this->ERR_INVALID_DEFAULT_OUTPUT_FORMAT);
      } else if (!in_array($defaultFormat, $this->_availableFilters)) {
        $this->_abort($this->ERR_UNSUPPORTED_DEFAULT_OUTPUT_FORMAT);
      }
      if (count($matches) === 0)
      {
        $this->_responseFormat = $defaultFormat;
      }
      else
      {
        if (count($matches) === 0) {
          $this->_abort($this->ERR_UNSUPPORTED_OUTPUT_FORMAT);
        }
        if (!in_array($matches[1], $this->_getSetting("outputFormats"))) {
          $this->_abort($this->ERR_OUTPUT_FORMAT_NOT_ALLOWED);
        }
        $this->_responseFormat = $matches[1];
      }
    } else {
      if (count($matches) === 0) {
        $this->_abort($this->ERR_UNSUPPORTED_OUTPUT_FORMAT);
      }
      if (!in_array($matches[1], $this->_getSetting("outputFormats"))) {
        $this->_abort($this->ERR_OUTPUT_FORMAT_NOT_ALLOWED);
      }
      $this->_responseFormat = $matches[1];
    }
    $responseMimeType = strtoupper($this->_responseFormat) . "_MIME_TYPE";
    $this->_responseMimeType = constant("Banister\\$responseMimeType");
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
    // get parameters
    $params = array();
    $dbParams = array();

    foreach ($this->_requestedRouteObject->params as $i => $param) {
      if (isset($param->defaultValue)) {
        switch (strtolower($param->defaultValue)) {
          case "client.ip":
            $params[$param->name] = $_SERVER["REMOTE_ADDR"];
          break;
          default:
            $params[$param->name] = $param->defaultValue;
          break;
        }
      } else {
        if (!isset($_REQUEST[$param->name]) && !$param->output) {
          $this->_abort(sprintf($this->ERR_MISSING_PARAMETER, $param->type, $param->name));
        }
        $params[$param->name] = (isset($param->output) && $param->output) ? null : $_REQUEST[$param->name];
      }
      $dbParams[] = ((isset($param->output) && $param->output) ? "@" : ":") . $param->name;
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
    if (!isset($this->_requestedRouteObject->direct) || !$this->_requestedRouteObject->direct) {
      $context->params = $params;
      $context->db = $db;
    }

    // if the route has mysql db proc handler... let the base handle it!
    if (isset($this->_requestedRouteObject->handler->mysqlDbProc)) {
      $proc = $this->_requestedRouteObject->handler->mysqlDbProc;

      $stmt = $db->handler()->prepare("CALL $proc(" . implode(", ", $dbParams) . ")");

      foreach ($this->_requestedRouteObject->params as $i => $param) {
        $format = null;
        $length = null;
        switch (strtolower($param->type)) {
          case "number":
            $format = \PDO::PARAM_INT;
          break;
          case "bool":
            $format = \PDO::PARAM_BOOL;
          break;
          default:
            $format = \PDO::PARAM_STR;
          break;
        }

        if (isset($param->output) && $param->output) $length = $param->length;

        if (isset($param->inputOutput) && $param->inputOutput) {
          $stmt->bindParam($param->name, $params[$param->name] | \PDO::PARAM_INPUT_OUTPUT, $format, $length);  
        } else if (!isset($param->output) || (isset($param->output) && !$param->output)) {
          $stmt->bindParam($param->name, $params[$param->name], $format);
        }
      }

      $stmt->execute();
      $data = array();

      if ($stmt->fetchColumn() > 0) {
        while ($row = $stmt->fetch()) {
          $data[] = $row;
        }
      }

      $stmt->closeCursor();
      unset($stmt);

      $outParams = array();
      foreach ($dbParams as $paramName) {
        if (substr($paramName, 0, 1) == "@") {
          $outParams[$paramName] = null;
        }
      }

      $stmt = $db->handler()->prepare("SELECT " . implode(", ", array_keys($outParams)));
      $stmt->execute();

      while ($row = $stmt->fetch()) {
        foreach ($row as $column => $value) {
          $outParams[$column] = $value;
        }
      }

      $stmt->closeCursor();
      unset($stmt);

      $context->data = $data;
      $context->output = array_unique($outParams);
    }

    return $context;
  }

  // binds the route to the controller
  private function _setResponseHandler() {
    $filterHandlerClass = "Banister\\" . ucwords($this->_responseFormat) . "Filter";
    $responseHandleMethod = $this->_getSetting("appNamespace") . "\\Rest\\" . $this->_getApiMethodName();
    if (isset($this->_requestedRouteObject->direct) && $this->_requestedRouteObject->direct) {
      $this->_responseHandler = new $filterHandlerClass(
        $this->_getContext()
      );    
    } else {
      if (!is_callable($responseHandleMethod))
      {
        $this->_abort(sprintf($this->ERR_MISSING_CONTROLLER, $this->_requestedRouteObject->route));
      }
      $this->_responseHandler = new $filterHandlerClass(
        $responseHandleMethod($this->_getContext())
      );
    }
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