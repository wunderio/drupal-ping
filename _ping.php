<?php

/**
 * @file
 * The Ping Utility.
 *
 * FOR DRUPAL 8 OR 9 ONLY !
 * FILE IS SUPPOSED TO BE IN DRUPAL ROOT DIRECTORY (NEXT TO INDEX.PHP) !!
 */

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

main();

/**
 * The main function.
 */
function main(): void {

  /*
   * Setup
   */

  $profile = new Profile();
  $status = new Status();

  if (!empty(getenv('TESTING'))) {
    return;
  }

  setup_shutdown();
  disable_newrelic();
  // Will be corrected later when not failing.
  set_header(503);

  /*
   * Actual stuff.
   */

  foreach ([
    'bootstrap',
    'check_db',
    'check_memcache',
    'check_redis',
    'check_elasticsearch',
    'check_fs_scheme_create',
    'check_fs_scheme_delete',
    'check_custom_ping',
  ] as $func) {
    // Although checks provide the name as the first thing,
    // just for safety if any of the checks fails too early.
    $status->setName($func);
    $msg = $profile->measure($func, [$status]);
    if (!empty($msg)) {
      $status->set('error', $msg);
    }
  }

  /*
   * Finish.
   */

  $profile->stop();

  log_slow_checks($profile);

  $warnings = $status->getBySeverity('warning');
  log_errors($warnings, 'warning');

  $errors = $status->getBySeverity('error');
  finish($status, $profile, $errors);

  // Exit immediately.
  // Note the shutdown function registered at the beginning.
  exit();
}

/*
 * Setup & Finish.
 */

/**
 * Custom shutdown.
 *
 * Register our shutdown function so that no other shutdown functions run
 * before this one.  This shutdown function calls exit(), immediately
 * short-circuiting any other shutdown functions, such as those registered by
 * the devel.module for statistics.
 */
function setup_shutdown(): void {
  // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FunctionHandlingFunctions.WarnFunctionHandling
  register_shutdown_function(function () {
    exit();
  });
}

/**
 * Disable NewRelic.
 *
 * We want to ignore _ping.php from New Relic statistics.
 * _ping.php skews the overall statistics significantly.
 */
function disable_newrelic(): void {
  if (extension_loaded('newrelic')) {
    newrelic_ignore_transaction();
  }
}

/**
 * Set response header.
 *
 * It can be called multiple times.
 * Originally the error status can be set.
 * But if the code finishes without errors,
 * then we can override that with successful status.
 *
 * @param int $code
 *   The status code, for ex 200, 404, etc.
 *
 * @return string
 *   The status message string.
 */
function set_header(int $code): string {
  $map = [
    200 => 'OK',
    500 => 'Internal Server Error',
    503 => 'Service Unavailable',
  ];
  $msg = $map[$code];
  $header = sprintf('HTTP/1.1 %d %s', $code, $msg);
  header($header);
  return $msg;
}

/**
 * Log errors according to the environment.
 *
 * We recognize following envs:
 * - silta -> stderr.
 * - lando -> stderr.
 * - the rest -> syslog().
 */
function log_errors(array $errors, string $category): void {

  if (!empty(getenv('SILTA_CLUSTER')) || !empty(getenv('LANDO'))) {
    $logger = function (string $msg) {
      error_log($msg);
    };
  }
  else {
    $logger = function (string $msg) {
      syslog(LOG_ERR | LOG_LOCAL6, $msg);
    };
  }

  foreach ($errors as $name => $message) {
    $logger("ping: $category: $name: $message");
  }
}

/**
 * Deliver the results.
 *
 * It performs following things:
 * - Take care status code.
 * - Deliver the ping status.
 * - Print debug info if requested.
 */
function finish(object $status, object $profile, array $errors = []): void {

  if (!empty($errors)) {
    log_errors($errors, 'error');
    $code = 500;
    $msg = 'INTERNAL ERROR';
  }
  else {
    $code = 200;
    $msg = 'CONGRATULATIONS';
  }
  set_header($code);
  // Split up this message, to prevent the remote chance of monitoring software
  // reading the source code if mod_php fails and then matching the string.
  print "$msg $code";

  if (!is_debug()) {
    return;
  }

  $status_tbl = $status->getTextTable(PHP_EOL);
  $profiling_tbl = $profile->getTextTable(PHP_EOL);
  print <<<TXT
<br/>

<pre>
$status_tbl
</pre>

<pre>
$profiling_tbl
</pre>
TXT;
}

/**
 * Log all slow requests.
 *
 * Fetch all slow requests from the profiling system,
 * and log them.
 */
function log_slow_checks(object $profile): void {
  $slow = $profile->getByDuration(1000.0, NULL);
  foreach ($slow as &$value) {
    $value = "duration=$value ms";
  }
  log_errors($slow, 'slow');
}

/**
 * Detect if debug information should be provided on request.
 *
 * Currently it is matching '?debug=hash',
 * where the 'hash' is the 4 first letters of the Drupal hash salt.
 */
function is_debug(): bool {

  $debug = $_GET['debug'] ?? NULL;
  if (empty($debug)) {
    return FALSE;
  }

  global $_drupal_settings;
  $hash = $_drupal_settings['hash_salt'] ?? '';
  $hash = substr($hash, 0, 4);

  return $debug == $hash;
}

/*
 * Actual functionality (to be profiled).
 */

/**
 * Drupal bootstrap.
 *
 * This is a prerequisite for the rest of the checks.
 */
function bootstrap(object $status): void {

  $autoloader = require_once 'autoload.php';
  $request = Request::createFromGlobals();
  $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
  $kernel->boot();

  // Define DRUPAL_ROOT if it's not yet defined by bootstrap.
  if (!defined('DRUPAL_ROOT')) {
    define('DRUPAL_ROOT', getcwd());
  }

  // Get current settings.
  // And save them for other functions.
  global $_drupal_settings;
  $_drupal_settings = Settings::getAll();
}

/**
 * Check: Main database connectivity.
 */
function check_db(object $status): void {

  $status->setName('db');

  // Check that the main database is active.
  $result = Database::getConnection()
    ->query('SELECT * FROM {users} WHERE uid = 1')
    ->fetchAllKeyed();

  $count = count($result);
  if ($count > 0) {
    $status->set('success');
  }
  else {
    $status->set('error', "result_count=$count expected=1 Master database invalid results.");
  }
}

/**
 * Check: Memcache connectivity.
 *
 * Verify that all memcache instances are running on this server.
 * There are 3 statuses:
 * - 'success' - all instances are available.
 * - 'warning' - 0<x<all instances are not available.
 * - 'error' - all instances are unavailable.
 */
function check_memcache(object $status): void {

  global $_drupal_settings;

  $status->setName('memcache');

  $servers = $_drupal_settings['memcache']['servers'] ?? NULL;
  if (empty($servers)) {
    $status->set('disabled');
    return;
  }

  $good_count = 0;
  $bad_count = 0;
  $errors = [];

  // Loop through the defined servers.
  foreach ($servers as $address => $bin) {

    [$host, $port] = explode(':', $address);

    // We are not relying on Memcache or Memcached classes.
    // For speed and simplicity we use just basic networking.
    $socket = fsockopen($host, $port, $errno, $errstr, 1);
    if (!empty($errstr)) {
      $errors[] = "host=$host port=$port - $errstr";
      $bad_count++;
      continue;
    }
    fwrite($socket, "stats\n");
    // Just check the first line of the reponse.
    $line = fgets($socket);
    if (!preg_match('/^STAT /', $line)) {
      $errors[] = "host=$host port=$port response='$line' - Unexpected response";
      $bad_count++;
      continue;
    }
    fclose($socket);

    $good_count++;
  }

  if ($good_count > 0 && $bad_count < 1) {
    $status->set('success');
    return;
  }

  if ($good_count > 0 && $bad_count > 0) {
    $status->set('warning', implode('; ', $errors));
    return;
  }

  if ($good_count < 1 && $bad_count > 0) {
    $status->set('error', implode('; ', $errors));
    return;
  }
}

/**
 * Check: Redis connectivity.
 *
 * Handles both:
 * - TCP/IP - both host and port are defined.
 * - Unix Socket - only host is defined as path.
 */
function check_redis(object $status): void {

  global $_drupal_settings;

  $status->setName('redis');

  $host = $_drupal_settings['redis.connection']['host'] ?? NULL;
  $port = $_drupal_settings['redis.connection']['port'] ?? NULL;

  if (empty($host) || empty($port)) {
    $status->set('disabled');
    return;
  }

  /*
   * In case of a socket,
   * only host is defined.
   */

  // Use PhpRedis, PRedis is outdated.
  $redis = new \Redis();
  if ($redis->connect($host, $port)) {
    $status->set('success');
  }
  else {
    $status->set('error', "host=$host port=$port - unable to connect");
  }
}

/**
 * Check: Elasticsearch connectivity by curl.
 */
function check_elasticsearch(object $status): void {

  global $_drupal_settings;

  $status->setName('elasticsearch');

  // We use ping-specific configuration to check Elasticsearch.
  // Because there are way too many ways how Elasticsearch
  // connections can be defined depending on libs/mods/versions.
  $connections = $_drupal_settings['ping_elasticsearch_connections'] ?? NULL;
  if (empty($connections)) {
    $status->set('disabled');
    return;
  }

  $good_count = 0;
  $bad_count = 0;
  $errors = [];

  // Loop through Elasticsearch connections.
  // Perform basic curl request,
  // and ensure we get green status back.
  foreach ($connections as $c) {

    $url = sprintf('%s://%s:%d%s', $c['proto'], $c['host'], $c['port'], '/_cluster/health');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 2000);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2000);
    curl_setopt($ch, CURLOPT_USERAGENT, "ping");
    $json = curl_exec($ch);
    if (empty($json)) {
      $errors[] = sprintf('url=%s - errno=%d errstr="%s"', $url, curl_errno($ch), curl_error($ch));
      curl_close($ch);
      $bad_count++;
      continue;
    }
    curl_close($ch);

    $data = json_decode($json);
    if (empty($data)) {
      $errors[] = sprintf('url=%s - %s', $url, 'Unable to decode JSON response');
      $bad_count++;
      continue;
    }

    if (empty($data->status)) {
      $errors[] = sprintf('url=%s - %s', $url, 'Response does not contain status');
      $bad_count++;
      continue;
    }

    if ($data->status !== 'green') {
      $errors[] = sprintf('url=%s status=%s - %s', $url, $data->status, 'Not green');
      $bad_count++;
      continue;
    }

    $good_count++;
  }

  if ($good_count > 0 && $bad_count < 1) {
    $status->set('success');
    return;
  }

  if ($good_count > 0 && $bad_count > 0) {
    $status->set('warning', implode('; ', $errors));
    return;
  }

  if ($good_count < 1 && $bad_count > 0) {
    $status->set($c['severity'], implode('; ', $errors));
    return;
  }
}

/**
 * Check: Filesystem item creation.
 *
 * Note, 'Filesystem item deletion' depends on this, and is executed after.
 */
function check_fs_scheme_create(object $status): void {

  $status->setName('fs-scheme-create');

  // Define file_uri_scheme if it does not exist, it's required by realpath().
  // The function file_uri_scheme is deprecated and will be removed in 9.0.0.
  if (!function_exists('file_uri_scheme')) {

    /**
     * Hmm.
     */
    function file_uri_scheme($uri) {
      return \Drupal::service('file_system')->uriScheme($uri);
    }

  }

  // Get current defined scheme.
  $scheme = \Drupal::config('system.file')->get('default_scheme');

  // Get the real path of the files uri.
  $path = \Drupal::service('file_system')->realpath($scheme . '://');

  // Check that the files directory is operating properly.
  $tmp = \Drupal::service('file_system')->tempnam($path, 'status_check_');
  if (empty($tmp)) {
    $status->set('error', "path=$path - Could not create temporary file in the files directory.");
    return;
  }

  global $_check_fs_scheme_file;
  $_check_fs_scheme_file = $tmp;

  $status->set('success');
}

/**
 * Check: Filesystem item deletion.
 *
 * Note, it depends on 'Filesystem item creation' being executed before.
 */
function check_fs_scheme_delete(object $status): void {

  $status->setName('fs-scheme-delete');

  global $_check_fs_scheme_file;
  $tmp = $_check_fs_scheme_file;

  if (empty($tmp)) {
    $status->set('disabled');
    return;
  }

  if (!unlink($tmp)) {
    $status->set('error', "file=$tmp - Could not delete newly created file in the files directory.");
    return;
  }

  $status->set('success');
}

/**
 * Check: Custom ping.
 *
 * _ping.custom.php will be executed if present.
 */
function check_custom_ping(object $status): void {

  $status->setName('custom-ping');

  if (!file_exists('_ping.custom.php')) {
    $status->set('disabled');
    return;
  }

  // We set the status in advance,
  // but it will be overridden by the custom ping
  // or by catch(){}.
  $status->set('success');

  // Note: the custom script has to use
  // $status->set() interface for the messages!
  include '_ping.custom.php';
}

/**
 * Time Profiling subsystem keeps track of timing.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class Profile {

  /**
   * Execution start time[ns].
   *
   * @var int
   */
  private $start;

  /**
   * Execution stop time[ns].
   *
   * @var int
   */
  private $stop;

  /**
   * Duration[ns] of an execution: stop - start.
   *
   * @var int
   */
  private $duration;

  /**
   * Time[ns] spent on non-measured stuff.
   *
   * @var int
   */
  private $misc;

  /**
   * Holds profiled functions and durations[ns].
   *
   * @var array
   */
  private $items = [];

  /**
   * Init profiling, start global execution measurement.
   *
   * This function/object must be executed as early as possible.
   */
  public function __construct() {
    $this->start = hrtime(TRUE);
  }

  /**
   * Stop global execution measurement.
   */
  public function stop(): void {

    $this->stop = hrtime(TRUE);

    $this->duration = $this->stop - $this->start;

    $measured = 0;
    foreach ($this->items as $duration) {
      $measured += $duration;
    }

    // Calculate 'misc'.
    // Misc is time spent on non-measured things.
    // It is total_execution_time - sum(measured_items).
    $misc = 0;
    $this->misc = $this->duration - $measured;
  }

  /**
   * Execute a function, and measure execution time and catch errors.
   *
   * The time measures is being recorded internally, but not returned.
   * The error-catching is way too convenient and tempting here,
   * though it violates the single responsibility.
   *
   * @param string $func
   *   The function name to be executed, and measured.
   * @param array $args
   *   Arguments to provide to the function.
   *
   * @return string|null
   *   Return error string, or NULL.
   */
  public function measure(string $func, array $args) {

    $start = hrtime(TRUE);
    try {
      $msg = NULL;
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FunctionHandlingFunctions.WarnFunctionHandling
      call_user_func_array($func, $args);
    }
    catch (\Exception $e) {
      $msg = sprintf('%s(): %s', $func, $e->getMessage());
    }
    $end = hrtime(TRUE);
    $duration = $end - $start;

    $this->items[$func] = $duration;

    return $msg;
  }

  /**
   * Format a 2-column text table.
   *
   * @return string
   *   The table has following columns:
   *   - Durations (sorted)
   *   - Check names
   */
  public function getTextTable(string $separator): string {

    $fmt = '% 10.3f ms - %s';

    arsort($items);
    $lines = [];
    foreach ($this->items as $func => $duration) {
      $lines[] = sprintf($fmt, $duration / 1000000, $func);
    }

    $lines[] = sprintf($fmt, $this->misc / 1000000, 'misc');
    $lines[] = sprintf($fmt, $this->duration / 1000000, 'total');

    $lines = implode($separator, $lines);
    return $lines;
  }

  /**
   * Filter checks that executed between $minMs and $maxMs.
   *
   * @return array
   *   The format is ['func' => duration].
   */
  public function getByDuration(int $minMs = NULL, int $maxMs = NULL): array {

    $filtered = [];
    foreach ($this->items as $func => $duration) {
      $duration = $duration / 1000000;
      if (!empty($minMs) && $duration < $minMs) {
        continue;
      }
      if (!empty($maxMs) && $duration > $maxMs) {
        continue;
      }
      $filtered[$func] = $duration;
    }
    return $filtered;
  }

}

/**
 * The Status subsystem keeps track of the results of the checks.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class Status {

  /**
   * Holds list of statuses.
   *
   * @var array
   */
  private $items = [];

  /**
   * Holds name of currently executing context.
   *
   * @var string
   */
  private $name = 'unset';

  /**
   * Set context name.
   *
   * The idea is that when we start certain procedure then we set context first.
   * It makes much easier not to repeat the context name in all the subsequent
   * $status->set() calls.
   *
   * @param string $name
   *   The name of the context. Preferred chars: 'a-z0-9_-'.
   */
  public function setName(string $name): void {
    $this->name = $name;
  }

  /**
   * Set status for the current context.
   *
   * @param string $severity
   *   One of: 'error', 'warning', 'success', 'disabled'.
   * @param string $message
   *   Further details on the severity. Optional.
   */
  public function set(string $severity, string $message = ''): void {
    $this->items[$this->name] = [
      'severity' => $severity,
      'message' => $message,
    ];
  }

  /**
   * Filter check results by status code.
   *
   * @return array
   *   The format is ['name' => 'message'].
   */
  public function getBySeverity(string $severity): array {
    $filtered = [];
    foreach ($this->items as $name => $details) {
      if ($details['severity'] == $severity) {
        $filtered[$name] = $details['message'];
      }
    }
    return $filtered;
  }

  /**
   * Format check results as text table.
   *
   * @param string $separator
   *   String for glueing lines.
   *
   * @return string
   *   Multi-line table.
   */
  public function getTextTable(string $separator): string {
    $lines = [];
    foreach ($_status as $name => $details) {
      $lines[] = sprintf('%-20s %-10s %s', $name, $details['severity'], $details['message']);
    }
    $lines = implode($separator, $lines);
    return $lines;
  }

}
