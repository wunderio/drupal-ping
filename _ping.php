<?php

// FOR DRUPAL 7 ONLY!
// FILE IS SUPPOSED TO BE IN DRUPAL ROOT DIRECTORY (NEXT TO INDEX.PHP) !!

// @todo - implement try {} catch {} into most checks

main();
// Exit immediately, note the shutdown function registered at the top of the file.
exit();

function main() {

  // Setup

  profiling_init(hrtime(TRUE));
  setup_shutdown();
  setup_newrelic();
  set_header(503);
  status_init();

  // Actual stuff

  profiling_measure('bootstrap');
  profiling_measure('check_db');
  profiling_measure('check_memcached');
  profiling_measure('check_redis_tcp');
  profiling_measure('check_redis_unix');
  profiling_measure('check_fs_scheme');
  profiling_measure('check_custom_ping');

  // Finish

  profiling_finish(hrtime(TRUE));
  $errors = status_by_severity('error');
  if (count($errors) > 0) {
    finish_error();
  }
  else {
    finish_success();
  }
}

//
// Setup & Finish
//

// Register our shutdown function so that no other shutdown functions run before this one.
// This shutdown function calls exit(), immediately short-circuiting any other shutdown functions,
// such as those registered by the devel.module for statistics.
function setup_shutdown() {
  register_shutdown_function(function () {
    exit();
  });
}

// We want to ignore _ping.php from New Relic statistics,
// because with 180rpm and less than 10s avg response times,
// _ping.php skews the overall statistics significantly.
function setup_newrelic() {
  if (extension_loaded('newrelic')) {
    newrelic_ignore_transaction();
  }
}

function set_header($code) {
  $map = [
    200 => 'OK',
    500 => 'Internal Server Error',
    503 => 'Service Unavailable',
  ];
  $header = sprintf('HTTP/1.1 %d %s', $code, $map[$code]);
  header($header);
}

function log_errors($errors) {

  if (getenv('SILTA_CLUSTER')) {
    $logger = function (string $msg) {
      error_log($msg);
    };
  }
  else {
    $logger = function (string $msg) {
      syslog(LOG_ERR|LOG_LOCAL6, $msg);
    };
  }

  foreach ($errors as $name => $message) {
    $logger("$name: $message");
  }
}

function finish_error($errors) {

  log_errors($errors);

  $code = 500;
  set_header($code);

  $tbl = status_tbl();
  print <<<TXT
INTERNAL ERROR $code

<pre>
$tbl
</pre>

Errors on this server will cause it to be removed from the load balancer.
TXT;
}

function finish_success() {

  $code = 200;
  set_header($code);

  // Split up this message, to prevent the remote chance of monitoring software
  // reading the source code if mod_php fails and then matching the string.
  print "CONGRATULATIONS $code";
  print PHP_EOL;

  if (!isset($_GET['debug'])) {
    return;
  }

  $status_tbl = status_tbl();
  $profiling_tbl = profiling_tbl();
  print <<<TXT
<pre>
$status_tbl
</pre>

<pre>
$profiling_tbl
</pre>
TXT;
}

//
// Actual functionality (to be profiled)
//

// Drupal bootstrap.
function bootstrap() {
  define('DRUPAL_ROOT', getcwd());
  require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
  drupal_bootstrap(DRUPAL_BOOTSTRAP_DATABASE);
}

// Check that the main database is active.
function check_db() {

  $name = 'db';

  $result = db_query('SELECT * FROM {users} WHERE uid = 1');

  if ($result->rowCount() > 0) {
    status_set($name, 'ok', '');
  }
  else {
    status_set($name, 'error', 'Master database not returning results.');
  }
}

// Check that all memcache instances are running on this server.
function check_memcached() {

  global $conf;

  $name = 'memcache';

  $servers = $conf['memcache_servers'] ?? NULL;
  if (empty($servers)) {
    status_set($name, 'info', 'Not configured');
    return;
  }

  if (class_exists('Memcache')) {
    $i = 1;
    foreach ($servers as $address => $bin) {
      list($ip, $port) = explode(':', $address);
      if (memcache_connect($ip, $port)) {
        status_set("$name-$i", 'ok', '');
      }
      else {
        status_set("$name-$i", 'error', "ip=$ip port=$port bin=$bin - unable to connect");
      }
      $i++;
    }
    return;
  }

  if (class_exists('Memcached')) {
    $i = 1;
    $mc = new Memcached();
    foreach ($servers as $address => $bin) {
      list($ip, $port) = explode(':', $address);
      if ($mc->addServer($ip, $port)) {
        status_set("$name-$i", 'ok', '');
      }
      else {
        status_set("$name-$i", 'error', "ip=$ip port=$port bin=$bin - unable to connect");
      }
      $i++;
    }
    return;
  }

  status_set($name, 'error', 'Memcache configured, but Memcache or Memcached class is not present.');
}

// @todo - Refactor Redis TCP & UNIX code
// Check that Redis instace is running correctly using PhpRedis
// TCP/IP connection
function check_redis_tcp() {

  global $conf;

  $name = 'redis-tcp';

  $host = $conf['redis_client_host'] ?? NULL;
  $port = $conf['redis_client_port'] ?? NULL;

  if (empty($host) || empty($port)) {
    status_set($name, 'info', 'Not configured');
    return;
  }

  $redis = new Redis();
  if ($redis->connect($host, $port)) {
    status_set($name, 'ok', '');
  }
  else {
    status_set($name, 'error', "host=$host port=$port - unable to connect");
  }
}

// @todo - Refactor Redis TCP & UNIX code
// UNIX socket connection
function check_redis_unix() {

  global $conf;

  $name = 'redis-unix';

  $socket = $conf['redis_cache_socket'] ?? NULL;
  if (empty($socket)) {
    status_set($name, 'info', 'Not configured');
    return;
  }

  $redis = new Redis();

  if ($redis->connect($socket)) {
    status_set($name, 'ok', '');
  }
  else {
    status_set($name, 'error', "socket=$socket - unable to connect");
  }
}

// Define file_uri_scheme if it does not exist, it's required by realpath().
// The function file_uri_scheme is deprecated and will be removed in 9.0.0.
function check_fs_scheme() {

  $name = 'fs_scheme';

  $tmp = tempnam(variable_get('file_directory_path', conf_path() . '/files'), 'status_check_');
  if (empty($tmp)) {
    status_set($name, 'error', 'Could not create temporary file in the files directory.');
    return;
  }

  if (!unlink($tmp)) {
    status_set($name, 'error', 'Could not delete newly create files in the files directory.');
    return;
  }

  status_set($name, 'ok', '');
}

// Custom checks
function check_custom_ping() {

  $name = 'custom-ping';

  if (!file_exists('_ping.custom.php')) {
    status_set($name, 'info', 'Not configured');
    return;
  }

  // Note: the custom script has to use status_set() interface for the messages!
  include '_ping.custom.php';
}

//
// Profiling
//

function profiling_init(int $time) {
  global $profiling;
  $profiling = [];
  $profiling['init'] = $time;
}

function profiling_finish(int $time) {
  global $profiling;
  $profiling['finish'] = $time;
}

function profiling_measure(string $func) {

  $start = hrtime(TRUE);
  $func();
  $end = hrtime(TRUE);
  $duration = $end - $start;

  global $profiling;
  $profiling[$func] = $duration;
}

function profiling_tbl() {
  global $profiling;

  // Calculate 'misc'.
  // Misc is time spent on non-measured things.
  $profiling['misc'] = 0;
  $measured = 0;
  foreach ($profiling as $func => $duration) {
    if (in_array($func, ['init', 'finish'])) {
      continue;
    }
    $measured += $duration;
  }
  $profiling['misc'] = $profiling['finish'] - $profiling['init'] - $measured;

  arsort($profiling);
  $lines = [];
  $measured = 0;
  foreach ($profiling as $func => $duration) {
    if (in_array($func, ['init', 'finish'])) {
      continue;
    }
    $duration = $duration / 1000000;
    $lines[] = sprintf('% 10.3f ms - %s', $duration, $func);
  }

  $total = $profiling['finish'] - $profiling['init'];
  $total = $total / 1000000;
  $total = sprintf('% 10.3f ms - %s', $total, 'total');
  $lines[] = $total;

  $lines = implode(PHP_EOL, $lines);
  return $lines;
}

//
// Status
//

function status_init() {
  global $status;
  $status = [];
}

function status_set(string $name, string $severity, string $message) {
  global $status;
  $status[$name] = [
    'severity' => $severity,
    'message' => $message,
  ];
}

function status_by_severity(string $severity) {
  $filtered = [];
  global $status;
  foreach ($status as $name => $details) {
    if ($details['severity'] == $severity) {
      $filtered[$name] = $details['message'];
    }
  }
  return $filtered;
}

function status_tbl() {
  global $status;
  $lines = [];
  foreach ($status as $name => $details) {
    $lines[] = sprintf('%-15s %-10s %s', $name, $details['severity'], $details['message']);
  }
  $lines = implode(PHP_EOL, $lines);
  return $lines;
}
