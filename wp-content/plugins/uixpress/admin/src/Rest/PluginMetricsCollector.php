<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Main class responsible for collecting and managing plugin performance metrics.
 * Coordinates all metric collection processes including memory, hooks, queries, and assets.
 *
 * @package UiXpress\Metrics
 */
class PluginMetricsCollector
{
  private $metrics = [];
  private $specific_plugin_slug = false;
  private $memory_threshold;

  public function __construct()
  {
    add_action("plugins_loaded", [$this, "initializeMetricsCollection"], -999999);
  }

  /**
   * Initializes the metrics collection process.
   * Triggers only when 'collect_plugin_metrics' query parameter is present.
   *
   * @return void
   */
  public function initializeMetricsCollection()
  {
    if (!isset($_GET["collect_plugin_metrics"])) {
      return;
    }

    if (!current_user_can("manage_options")) {
      return;
    }

    $this->specific_plugin_slug = isset($_GET["plugin_slug"]) ? sanitize_text_field($_GET["plugin_slug"]) : false;
    $this->memory_threshold = $this->specific_plugin_slug ? 16384 : 65536;
    $this->initializePluginMetrics();
    $this->setupHooks();
  }

  /**
   * Sets up initial metrics data structure for active plugins.
   * Filters plugins based on specific_plugin_slug if provided.
   *
   * @return void
   */
  private function initializePluginMetrics()
  {
    $all_plugins = get_plugins();
    $active_plugins = get_option("active_plugins");

    foreach ($active_plugins as $plugin) {
      $slug_parts = explode("/", $plugin);
      $base_slug = $slug_parts[0];

      if ($this->specific_plugin_slug && $this->specific_plugin_slug != $base_slug) {
        continue;
      }

      $this->metrics[$plugin] = [
        "name" => $all_plugins[$plugin]["Name"],
        "version" => $all_plugins[$plugin]["Version"],
        "splitSlug" => $base_slug,
        "active" => true,
        "start_time" => null,
        "end_time" => null,
        "active_time" => 0,
        "memory_snapshots" => [],
        "peak_memory" => 0,
        "queries" => [],
        "hooks" => [],
        "metrics" => [],
        "errors" => [],
        "http_requests" => [],
        "deprecated_calls" => [],
        "assets" => [
          "scripts" => [],
          "styles" => [],
        ],
      ];
    }
  }

  /**
   * Initializes and configures all metric tracking components.
   * Sets up execution, memory, hook, query, HTTP, and asset tracking.
   *
   * @return void
   */
  private function setupHooks()
  {
    $tracker = new ExecutionTracker($this->metrics);
    $tracker->setupTracking();

    $memory_monitor = new MemoryMonitor($this->metrics, $this->memory_threshold);
    $memory_monitor->startMonitoring();

    $hook_analyzer = new HookAnalyzer($this->metrics);
    $hook_analyzer->analyzeHooks();

    $query_tracker = new QueryTracker($this->metrics);
    $query_tracker->setupTracking();

    $http_monitor = new HttpRequestMonitor($this->metrics);
    $http_monitor->setupMonitoring();

    $asset_tracker = new AssetTracker($this->metrics);
    $asset_tracker->setupTracking();

    $metrics_reporter = new MetricsReporter($this->metrics);
    $metrics_reporter->setupReporting();

    $deprecated_tracker = new DeprecatedFunctionTracker($this->metrics);
    $deprecated_tracker->setupTracking();

    $error_tracker = new ErrorTracker($this->metrics);
    $error_tracker->setupTracking();
  }
}

/**
 * Tracks plugin execution boundaries and timing.
 * Measures active execution time and captures execution context.
 */
class ExecutionTracker
{
  private $metrics;

  public function __construct(&$metrics)
  {
    $this->metrics = &$metrics;
  }

  /**
   * Configures execution tracking for all monitored plugins.
   *
   * @return void
   */
  public function setupTracking()
  {
    foreach ($this->metrics as $plugin => &$data) {
      $plugin_dir = plugin_dir_path(WP_PLUGIN_DIR . "/" . $plugin);
      $this->trackExecutionBoundaries($data, $plugin_dir);
    }
  }

  /**
   * Sets up execution boundary tracking for a specific plugin.
   *
   * @param array  &$data      Reference to plugin metrics data
   * @param string $plugin_dir Plugin directory path
   * @return void
   */
  private function trackExecutionBoundaries(&$data, $plugin_dir)
  {
    add_action(
      "all",
      function () use (&$data, $plugin_dir) {
        static $last_start = null;
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $in_plugin = false;

        foreach ($backtrace as $trace) {
          if (isset($trace["file"]) && strpos($trace["file"], $plugin_dir) === 0) {
            $in_plugin = true;
            if ($last_start === null) {
              $last_start = microtime(true);
              if ($data["start_time"] === null) {
                $data["start_time"] = $last_start;
              }

              $data["memory_snapshots"][] = [
                "time" => microtime(true),
                "usage" => memory_get_usage(true),
                "peak" => memory_get_peak_usage(true),
                "hook" => current_filter(),
              ];
            }
            break;
          }
        }

        if ($last_start !== null && !$in_plugin) {
          $data["active_time"] += microtime(true) - $last_start;
          $last_start = null;
        }
      },
      0
    );
  }
}

/**
 * Monitors and records plugin memory usage during execution.
 * Tracks memory snapshots and peak memory usage.
 */
class MemoryMonitor
{
  private $metrics;
  private $memory_threshold;

  public function __construct(&$metrics, $memory_threshold)
  {
    $this->metrics = &$metrics;
    $this->memory_threshold = $memory_threshold;
  }

  /**
   * Initiates memory monitoring for all tracked plugins.
   *
   * @return void
   */
  public function startMonitoring()
  {
    foreach ($this->metrics as $plugin => &$data) {
      $plugin_dir = plugin_dir_path(WP_PLUGIN_DIR . "/" . $plugin);
      $this->monitorMemory($data, $plugin_dir);
    }
  }

  /**
   * Sets up memory monitoring for a specific plugin.
   *
   * @param array  &$data      Reference to plugin metrics data
   * @param string $plugin_dir Plugin directory path
   * @return void
   */
  private function monitorMemory(&$data, $plugin_dir)
  {
    add_action(
      "all",
      function () use (&$data, $plugin_dir) {
        if ($data["start_time"] === null) {
          return;
        }

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($backtrace as $trace) {
          if (isset($trace["file"]) && strpos($trace["file"], $plugin_dir) === 0) {
            $this->recordMemorySnapshot($data);
            break;
          }
        }
      },
      999999
    );
  }

  /**
   * Records a memory snapshot if usage exceeds threshold.
   *
   * @param array &$data Reference to plugin metrics data
   * @return void
   */
  private function recordMemorySnapshot(&$data)
  {
    $current_usage = memory_get_usage(true);
    $current_peak = memory_get_peak_usage(true);
    $last_snapshot = end($data["memory_snapshots"]);

    if (!$last_snapshot || abs($current_usage - $last_snapshot["usage"]) >= $this->memory_threshold) {
      $data["memory_snapshots"][] = [
        "time" => microtime(true),
        "usage" => $current_usage,
        "peak" => $current_peak,
        "hook" => current_filter(),
      ];
    }

    $data["peak_memory"] = max($data["peak_memory"], $current_peak);
    $data["end_time"] = microtime(true);
  }
}

/**
 * Analyzes and records WordPress hooks used by plugins.
 * Tracks hook names, priorities, and callback types.
 */
class HookAnalyzer
{
  private $metrics;

  public function __construct(&$metrics)
  {
    $this->metrics = &$metrics;
  }

  /**
   * Initiates hook analysis for all tracked plugins.
   *
   * @return void
   */
  public function analyzeHooks()
  {
    foreach ($this->metrics as $plugin => &$data) {
      $plugin_dir = plugin_dir_path(WP_PLUGIN_DIR . "/" . $plugin);
      $this->collectHookData($data, $plugin_dir);
    }
  }

  /**
   * Collects hook data for a specific plugin.
   *
   * @param array  &$data      Reference to plugin metrics data
   * @param string $plugin_dir Plugin directory path
   * @return void
   */
  private function collectHookData(&$data, $plugin_dir)
  {
    foreach ($GLOBALS["wp_filter"] as $hook_name => $hook_obj) {
      foreach ($hook_obj->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $cb) {
          $this->analyzeCallback($cb, $plugin_dir, $hook_name, $priority, $data);
        }
      }
    }
  }

  /**
   * Analyzes a single callback for hook information.
   *
   * @param array  $cb         Callback array
   * @param string $plugin_dir Plugin directory path
   * @param string $hook_name  Hook name
   * @param int    $priority   Hook priority
   * @param array  &$data      Reference to plugin metrics data
   * @return void
   */
  private function analyzeCallback($cb, $plugin_dir, $hook_name, $priority, &$data)
  {
    if (is_array($cb["function"])) {
      $this->analyzeObjectCallback($cb["function"], $plugin_dir, $hook_name, $priority, $data);
    } elseif (is_string($cb["function"]) && function_exists($cb["function"])) {
      $this->analyzeFunctionCallback($cb["function"], $plugin_dir, $hook_name, $priority, $data);
    }
  }

  /**
   * Analyzes object method callbacks.
   *
   * @param array  $callback   Object callback array
   * @param string $plugin_dir Plugin directory path
   * @param string $hook_name  Hook name
   * @param int    $priority   Hook priority
   * @param array  &$data      Reference to plugin metrics data
   * @return void
   */
  private function analyzeObjectCallback($callback, $plugin_dir, $hook_name, $priority, &$data)
  {
    if (!is_object($callback[0])) {
      return;
    }

    try {
      $ref = new \ReflectionClass($callback[0]);
      if (strpos($ref->getFileName(), $plugin_dir) === 0) {
        // Get relative path instead of full path for better readability
        $relative_path = str_replace($plugin_dir, "", $ref->getFileName());

        $data["hooks"][] = [
          "priority" => $priority,
          "name" => $hook_name,
          "callback" => is_array($callback) ? $callback[1] : $callback,
          "file" => $relative_path,
        ];
      }
    } catch (\Exception $e) {
    }
  }

  /**
   * Analyzes function callbacks.
   *
   * @param string $callback   Function name
   * @param string $plugin_dir Plugin directory path
   * @param string $hook_name  Hook name
   * @param int    $priority   Hook priority
   * @param array  &$data      Reference to plugin metrics data
   * @return void
   */
  private function analyzeFunctionCallback($callback, $plugin_dir, $hook_name, $priority, &$data)
  {
    try {
      $ref = new \ReflectionFunction($callback);
      if (strpos($ref->getFileName(), $plugin_dir) === 0) {
        // Get relative path instead of full path for better readability
        $relative_path = str_replace($plugin_dir, "", $ref->getFileName());

        $data["hooks"][] = [
          "priority" => $priority,
          "name" => $hook_name,
          "callback" => is_array($callback) ? $callback[1] : $callback,
          "file" => $relative_path,
        ];
      }
    } catch (\Exception $e) {
    }
  }
}

/**
 * Tracks and analyzes database queries made by plugins.
 */
class QueryTracker
{
  private $metrics;

  public function __construct(&$metrics)
  {
    $this->metrics = &$metrics;
  }

  /**
   * Sets up query tracking filters.
   *
   * @return void
   */
  public function setupTracking()
  {
    add_filter("query", [$this, "trackQuery"]);
  }

  /**
   * Tracks individual database queries.
   *
   * @param string $query SQL query string
   * @return string Original query string
   */

  public function trackQuery($query)
  {
    global $wpdb;
    $start = microtime(true);
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

    foreach ($this->metrics as $plugin => &$data) {
      $plugin_dir = plugin_dir_path(WP_PLUGIN_DIR . "/" . $plugin);
      foreach ($backtrace as $trace) {
        if (isset($trace["file"]) && strpos($trace["file"], $plugin_dir) === 0) {
          $data["queries"][] = [
            "query" => $query,
            "time" => microtime(true) - $start,
            "hook" => current_filter(),
          ];
          break;
        }
      }
    }
    return $query;
  }
}

/**
 * Monitors and records HTTP requests made by plugins.
 */
class HttpRequestMonitor
{
  private $metrics;

  public function __construct(&$metrics)
  {
    $this->metrics = &$metrics;
  }

  /**
   * Sets up HTTP request monitoring.
   *
   * @return void
   */
  public function setupMonitoring()
  {
    add_filter("pre_http_request", [$this, "trackRequest"], 10, 3);
  }

  /**
   * Tracks individual HTTP requests.
   *
   * @param mixed  $preempt    Whether to preempt the request
   * @param array  $args       Request arguments
   * @param string $url        Request URL
   * @return mixed Original preempt value
   */
  public function trackRequest($preempt, $args, $url)
  {
    $start_time = microtime(true);
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

    foreach ($this->metrics as $plugin => &$data) {
      $plugin_dir = plugin_dir_path(WP_PLUGIN_DIR . "/" . $plugin);
      foreach ($backtrace as $trace) {
        if (isset($trace["file"]) && strpos($trace["file"], $plugin_dir) === 0) {
          $data["http_requests"][] = [
            "url" => $url,
            "method" => $args["method"],
            "start_time" => $start_time,
            "hook" => current_filter(),
          ];
          break;
        }
      }
    }
    return $preempt;
  }
}

/**
 * Tracks plugin enqueued scripts and styles.
 */
class AssetTracker
{
  private $metrics;

  public function __construct(&$metrics)
  {
    $this->metrics = &$metrics;
  }

  /**
   * Sets up asset tracking hooks.
   *
   * @return void
   */
  public function setupTracking()
  {
    add_action("wp_enqueue_scripts", [$this, "trackAssets"], 999999);
    add_action("admin_enqueue_scripts", [$this, "trackAssets"], 999999);
  }

  /**
   * Tracks all enqueued assets.
   *
   * @return void
   */
  public function trackAssets()
  {
    global $wp_scripts, $wp_styles;

    foreach ($this->metrics as $plugin => &$data) {
      $temp_path = "plugins/{$data["splitSlug"]}/";
      $this->trackScripts($wp_scripts, $temp_path, $data);
      $this->trackStyles($wp_styles, $temp_path, $data);
    }
  }

  /**
   * Tracks enqueued scripts.
   *
   * @param WP_Scripts $wp_scripts WordPress scripts object
   * @param string     $temp_path  Plugin path
   * @param array      &$data      Reference to plugin metrics data
   * @return void
   */
  private function trackScripts($wp_scripts, $temp_path, &$data)
  {
    foreach ($wp_scripts->registered as $handle => $script) {
      if (isset($script->src) && strpos($script->src, $temp_path) !== false) {
        $stripped_src = substr($script->src, strpos($script->src, $temp_path) + strlen($temp_path));
        $data["assets"]["scripts"][] = [
          "handle" => $handle,
          "src" => $temp_path . $stripped_src,
          "deps" => $script->deps,
          "ver" => $script->ver,
          "size" => @filesize(ABSPATH . parse_url($script->src, PHP_URL_PATH)),
        ];
      }
    }
  }

  /**
   * Tracks enqueued styles.
   *
   * @param WP_Styles $wp_styles WordPress styles object
   * @param string    $temp_path Plugin path
   * @param array     &$data     Reference to plugin metrics data
   * @return void
   */
  private function trackStyles($wp_styles, $temp_path, &$data)
  {
    foreach ($wp_styles->registered as $handle => $style) {
      if (isset($style->src) && strpos($style->src, $temp_path) !== false) {
        $stripped_src = substr($style->src, strpos($style->src, $temp_path) + strlen($temp_path));
        $data["assets"]["styles"][] = [
          "handle" => $handle,
          "src" => $temp_path . $stripped_src,
          "deps" => $style->deps,
          "ver" => $style->ver,
          "size" => @filesize(ABSPATH . parse_url($style->src, PHP_URL_PATH)),
        ];
      }
    }
  }
}

/**
 * Generates and outputs final metrics report.
 */
class MetricsReporter
{
  private $metrics;

  public function __construct(&$metrics)
  {
    $this->metrics = &$metrics;
  }

  /**
   * Sets up metrics reporting hooks.
   *
   * @return void
   */
  public function setupReporting()
  {
    add_action("wp_footer", [$this, "outputMetrics"], 999999);
    add_action("admin_footer", [$this, "outputMetrics"], 999999);
  }

  /**
   * Outputs collected metrics as JSON.
   *
   * @return void
   */
  public function outputMetrics()
  {
    foreach ($this->metrics as $plugin => &$data) {
      if ($data["start_time"] === null) {
        continue;
      }

      $this->calculateMetrics($data);
    }

    // Output as JSON in a script tag with a specific ID
    echo '<script id="plugin-metrics-data" type="application/json">';
    echo json_encode($this->metrics, JSON_PRETTY_PRINT);
    echo "</script>";
  }

  /**
   * Calculates final metrics for a plugin.
   *
   * @param array &$data Reference to plugin metrics data
   * @return void
   */
  private function calculateMetrics(&$data)
  {
    $memory_metrics = $this->calculateMemoryMetrics($data);
    $query_metrics = $this->calculateQueryMetrics($data);

    // Get the current global peak memory usage for the entire page
    $global_peak_memory = memory_get_peak_usage(true);

    $data["metrics"] = array_merge($memory_metrics, $query_metrics, [
      "hook_count" => count($data["hooks"]),
      "hooks" => $data["hooks"],
      "global_peak_memory" => $global_peak_memory,
    ]);
  }

  /**
   * Calculates memory-related metrics.
   *
   * @param array $data Plugin metrics data
   * @return array Memory metrics array
   */
  private function calculateMemoryMetrics($data)
  {
    $memory_growth = [];
    $total_allocated = 0;
    $snapshots = $data["memory_snapshots"];

    for ($i = 1; $i < count($snapshots); $i++) {
      $diff = $snapshots[$i]["usage"] - $snapshots[$i - 1]["usage"];
      if ($diff > 0) {
        $total_allocated += $diff;
        $memory_growth[] = [
          "hook" => $snapshots[$i]["hook"],
          "amount" => $diff,
          "time" => $snapshots[$i]["time"] - $snapshots[$i - 1]["time"],
        ];
      }
    }

    return [
      "execution_time" => $data["active_time"],
      "peak_memory" => $data["peak_memory"],
      "total_memory_allocated" => $total_allocated,
      "memory_growth" => $memory_growth,
    ];
  }

  /**
   * Calculates query-related metrics.
   *
   * @param array $data Plugin metrics data
   * @return array Query metrics array
   */
  private function calculateQueryMetrics($data)
  {
    $total_query_time = array_sum(array_column($data["queries"], "time"));
    $queries_by_hook = [];

    foreach ($data["queries"] as $query) {
      $hook = $query["hook"] ?? "unknown";
      if (!isset($queries_by_hook[$hook])) {
        $queries_by_hook[$hook] = ["count" => 0, "total_time" => 0];
      }
      $queries_by_hook[$hook]["count"]++;
      $queries_by_hook[$hook]["total_time"] += $query["time"];
    }

    return [
      "query_count" => count($data["queries"]),
      "query_time" => $total_query_time,
      "queries_by_hook" => $queries_by_hook,
    ];
  }
}

/**
 * Tracks deprecated function calls within plugins
 */
class DeprecatedFunctionTracker
{
  /** @var array Reference to metrics data */
  private $metrics;

  /**
   * Constructor
   *
   * @param array &$metrics Reference to metrics data
   */
  public function __construct(&$metrics)
  {
    $this->metrics = &$metrics;
  }

  /**
   * Sets up deprecated function tracking
   *
   * @return void
   */
  public function setupTracking()
  {
    add_action("deprecated_function_run", [$this, "trackDeprecatedCall"], 10, 3);
  }

  /**
   * Tracks individual deprecated function calls
   *
   * @param string $function    Name of the deprecated function
   * @param string $replacement Suggested replacement function
   * @param string $version     Version that marked the function as deprecated
   * @return void
   */
  public function trackDeprecatedCall($function, $replacement, $version)
  {
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

    foreach ($this->metrics as $plugin => &$data) {
      $plugin_dir = plugin_dir_path(WP_PLUGIN_DIR . "/" . $plugin);

      foreach ($backtrace as $trace) {
        if (isset($trace["file"]) && strpos($trace["file"], $plugin_dir) === 0) {
          // Initialize deprecated_calls array if it doesn't exist
          if (!isset($data["deprecated_calls"])) {
            $data["deprecated_calls"] = [];
          }

          $data["deprecated_calls"][] = [
            "function" => $function,
            "replacement" => $replacement,
            "version" => $version,
            "file" => $trace["file"],
            "line" => $trace["line"],
          ];
          break;
        }
      }
    }
  }
}

/**
 * Tracks PHP errors, warnings, and notices for plugins
 */
class ErrorTracker
{
  private $metrics;
  private $old_error_handler;
  private $error_buffer = [];
  private $is_ready = false;

  public function __construct(&$metrics)
  {
    $this->metrics = &$metrics;
  }

  public function setupTracking()
  {
    $this->old_error_handler = set_error_handler([$this, "handleError"]);
    register_shutdown_function([$this, "handleFatalError"]);

    // Set ready after plugins_loaded
    add_action(
      "plugins_loaded",
      function () {
        $this->is_ready = true;
        $this->processErrorBuffer();
      },
      999999
    );
  }

  private function processErrorBuffer()
  {
    foreach ($this->error_buffer as $error) {
      $this->storeError($error["errno"], $error["errstr"], $error["errfile"], $error["errline"]);
    }
    $this->error_buffer = [];
  }

  public function handleError($errno, $errstr, $errfile, $errline)
  {
    if (!$this->is_ready) {
      $this->error_buffer[] = compact("errno", "errstr", "errfile", "errline");
    } else {
      $this->storeError($errno, $errstr, $errfile, $errline);
    }

    if ($this->old_error_handler) {
      return call_user_func($this->old_error_handler, $errno, $errstr, $errfile, $errline);
    }
    return false;
  }

  private function storeError($errno, $errstr, $errfile, $errline)
  {
    foreach ($this->metrics as $plugin => &$data) {
      $plugin_dir = plugin_dir_path(WP_PLUGIN_DIR . "/" . $plugin);

      if (strpos($errfile, $plugin_dir) === 0) {
        $data["errors"][] = [
          "type" => $this->getErrorType($errno),
          "message" => $errstr,
          "file" => str_replace($plugin_dir, "", $errfile),
          "line" => $errline,
          "time" => microtime(true),
          "hook" => current_filter(),
        ];
      }
    }
  }

  /**
   * Handles fatal errors
   */
  public function handleFatalError()
  {
    $error = error_get_last();

    if ($error && in_array($error["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
      $this->handleError($error["type"], $error["message"], $error["file"], $error["line"]);
    }
  }

  /**
   * Converts PHP error number to readable type
   *
   * @param int $errno Error number
   * @return string
   */
  private function getErrorType($errno)
  {
    switch ($errno) {
      case E_ERROR:
        return "Fatal Error";
      case E_WARNING:
        return "Warning";
      case E_PARSE:
        return "Parse Error";
      case E_NOTICE:
        return "Notice";
      case E_CORE_ERROR:
        return "Core Error";
      case E_CORE_WARNING:
        return "Core Warning";
      case E_COMPILE_ERROR:
        return "Compile Error";
      case E_COMPILE_WARNING:
        return "Compile Warning";
      case E_USER_ERROR:
        return "User Error";
      case E_USER_WARNING:
        return "User Warning";
      case E_USER_NOTICE:
        return "User Notice";
      case E_STRICT:
        return "Strict Notice";
      case E_RECOVERABLE_ERROR:
        return "Recoverable Error";
      case E_DEPRECATED:
        return "Deprecated";
      case E_USER_DEPRECATED:
        return "User Deprecated";
      default:
        return "Unknown Error";
    }
  }
}
