<?php

// FOR DRUPAL 8 OR 9 ONLY !
// FILE IS SUPPOSED TO BE IN DRUPAL ROOT DIRECTORY (NEXT TO INDEX.PHP) !!

// @todo - implement try {} catch {} into most checks

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

main();
// Exit immediately, note the shutdown function registered at the top of the file.
exit();

function main(): void {

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
  log_slow_checks();
  $errors = status_by_severity('error');
  if (count($errors) > 0) {
    finish_error($errors);
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
function setup_shutdown(): void {
  register_shutdown_function(function () {
    exit();
  });
}

// We want to ignore _ping.php from New Relic statistics,
// because with 180rpm and less than 10s avg response times,
// _ping.php skews the overall statistics significantly.
function setup_newrelic(): void {
  if (extension_loaded('newrelic')) {
    newrelic_ignore_transaction();
  }
}

function set_header(int $code): void {
  $map = [
    200 => 'OK',
    500 => 'Internal Server Error',
    503 => 'Service Unavailable',
  ];
  $header = sprintf('HTTP/1.1 %d %s', $code, $map[$code]);
  header($header);
}

function log_errors(array $errors, string $category): void {

  if (!empty(getenv('SILTA_CLUSTER')) || !empty(getenv('LANDO'))) {
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
    $logger("ping: $category: $name: $message");
  }
}

function finish_error(array $errors): void {

  log_errors($errors, 'error');

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

function finish_success(): void {

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

function log_slow_checks() {
  $slow = profiling_by_duration(1000.0, NULL);
  foreach ($slow as &$value) {
    $value = "duration=$value ms";
  }
  log_errors($slow, 'slow');
}

//
// Actual functionality (to be profiled)
//

// Drupal bootstrap.
function bootstrap(): void {

  $autoloader = require_once 'autoload.php';
  $request = Request::createFromGlobals();
  $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
  $kernel->boot();

  // Define DRUPAL_ROOT if it's not yet defined by bootstrap.
  if (!defined('DRUPAL_ROOT')) {
    define('DRUPAL_ROOT', getcwd());
  }

  // Get current settings.
  global $drupal_settings;
  $drupal_settings = Settings::getAll();
}

// Check that the main database is active.
function check_db(): void {

  status_set_name('db');

  // Check that the main database is active.
  $result = \Drupal\Core\Database\Database::getConnection()
    ->query('SELECT * FROM {users} WHERE uid = 1')
    ->fetchAllKeyed();

  if (count($result) > 0) {
    status_set('success');
  }
  else {
    status_set('error', 'Master database not returning results.');
  }

}

// Check that all memcache instances are running on this server.
function check_memcached(): void {

  global $drupal_settings;

  status_set_name('memcache');

  $servers = $drupal_settings['memcache']['servers'] ?? NULL;
  if (empty($servers)) {
    status_set('disabled');
    return;
  }

  if (class_exists('Memcache')) {
    $i = 1;
    foreach ($servers as $address => $bin) {
      list($ip, $port) = explode(':', $address);
      status_set_name("memcache-$i");
      if (memcache_connect($ip, $port)) {
        status_set('success');
      }
      else {
        status_set('error', "ip=$ip port=$port bin=$bin - unable to connect");
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
      status_set_name("memcached-$i");
      if ($mc->addServer($ip, $port)) {
        status_set('success');
      }
      else {
        status_set('error', "ip=$ip port=$port bin=$bin - unable to connect");
      }
      $i++;
    }
    return;
  }

  status_set('error', 'Memcache configured, but Memcache or Memcached class is not present.');
}

// @todo - Refactor Redis TCP & UNIX code
// Check that Redis instace is running correctly using PhpRedis
// TCP/IP connection
function check_redis_tcp(): void {

  global $conf;

  status_set_name('redis-tcp');

  $host = $conf['redis_client_host'] ?? NULL;
  $port = $conf['redis_client_port'] ?? NULL;

  if (empty($host) || empty($port)) {
    status_set('disabled');
    return;
  }

  $redis = new Redis();
  if ($redis->connect($host, $port)) {
    status_set('success');
  }
  else {
    status_set('error', "host=$host port=$port - unable to connect");
  }
}

// @todo - Refactor Redis TCP & UNIX code
// UNIX socket connection
function check_redis_unix(): void {

  global $drupal_settings;

  status_set_name('redis-unix');

  $host = $drupal_settings['redis.connection']['host'] ?? NULL;
  $port = $drupal_settings['redis.connection']['port'] ?? NULL;

  if (empty($host)) {
    status_set('disabled');
    return;
  }

  // @Todo, use Redis client interface.
  $redis = new \Redis();

  if (!empty($port)) {
    if ($redis->connect($host, $port)) {
      status_set('success');
    }
    else {
      status_set('error', "host=$host port=$port - unable to connect");
    }
    return;
  }

  if ($redis->connect($host)) {
    status_set('success');
  }
  else {
    status_set('error', "host=$host - unable to connect");
  }
}

// Define file_uri_scheme if it does not exist, it's required by realpath().
// The function file_uri_scheme is deprecated and will be removed in 9.0.0.
function check_fs_scheme(): void {

  status_set_name('fs_scheme');

  if (!function_exists('file_uri_scheme')) {
    function file_uri_scheme($uri) {
      return \Drupal::service('file_system')->uriScheme($uri);
    }
  }

  // Get current defined scheme.
  $scheme = \Drupal::config('system.file')->get('default_scheme');

  // Get the real path of the files uri.
  $files_path = \Drupal::service('file_system')->realpath($scheme . '://');

  // Check that the files directory is operating properly.
  $tmp = \Drupal::service('file_system')->tempnam($files_path, 'status_check_');
  if (empty($tmp)) {
    status_set('error', 'Could not create temporary file in the files directory.');
    return;
  }

  if (!unlink($tmp)) {
    status_set('error', 'Could not delete newly create files in the files directory.');
    return;
  }

  status_set('success');
}

// Custom checks
function check_custom_ping(): void {

  status_set_name('custom-ping');

  if (!file_exists('_ping.custom.php')) {
    status_set('disabled');
    return;
  }

  // We set the status in advance,
  // but it will be overridden by the custom ping
  // or by cathc(){}.
  status_set('success');
  // Note: the custom script has to use status_set() interface for the messages!
  include '_ping.custom.php';
}

//
// Profiling
//

function profiling_init(int $time): void {
  global $profiling;
  $profiling = [];
  $profiling['init'] = $time;
}

function profiling_finish(int $time): void {
  global $profiling;
  $profiling['finish'] = $time;
}

function profiling_measure(string $func): void {

  $start = hrtime(TRUE);
  try {
    $func();
  }
  catch (\Exception $e) {
    $msg = sprintf('%s(): %s', $func, $e->getMessage());
    status_set('error', $msg);
  }
  $end = hrtime(TRUE);
  $duration = $end - $start;

  global $profiling;
  $profiling[$func] = $duration;
}

function profiling_tbl(): string {
  global $profiling;

  // Calculate 'misc'.
  // Misc is time spent on non-measured things.
  $profiling['misc'] = 0;
  $measured = 0;
  foreach ($profiling as $func => $duration) {
    if (in_array($func, ['init', 'finish', 'misc'])) {
      continue;
    }
    $measured += $duration;
  }
  $profiling['misc'] = $profiling['finish'] - $profiling['init'] - $measured;

  arsort($profiling);
  $lines = [];
  $measured = 0;
  foreach ($profiling as $func => $duration) {
    if (in_array($func, ['init', 'finish', 'misc'])) {
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

function profiling_by_duration(int $minMs = NULL, int $maxMs = NULL): array {
  global $profiling;

  $filtered = [];
  foreach ($profiling as $func => $duration) {
    if (in_array($func, ['init', 'finish', 'misc'])) {
      continue;
    }
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

//
// Status
//

function status_init(): void {
  global $status;
  global $status_name;
  $status = [];
  $status_name = 'unset';
}

function status_set_name(string $name) {
  global $status_name;
  $status_name = $name;
}

function status_set(string $severity, string $message = ''): void {
  global $status;
  global $status_name;
  $status[$status_name] = [
    'severity' => $severity,
    'message' => $message,
  ];
}

function status_by_severity(string $severity): array {
  $filtered = [];
  global $status;
  foreach ($status as $name => $details) {
    if ($details['severity'] == $severity) {
      $filtered[$name] = $details['message'];
    }
  }
  return $filtered;
}

function status_tbl(): string {
  global $status;
  $lines = [];
  foreach ($status as $name => $details) {
    $lines[] = sprintf('%-15s %-10s %s', $name, $details['severity'], $details['message']);
  }
  $lines = implode(PHP_EOL, $lines);
  return $lines;
}
