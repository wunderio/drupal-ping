<?php

// FOR DRUPAL 7 ONLY!
// FILE IS SUPPOSED TO BE IN DRUPAL ROOT DIRECTORY (NEXT TO INDEX.PHP) !!

main();

function main(): void {

  // Setup

  profiling_init(hrtime(TRUE));
  status_init();

  if (!empty(getenv('TESTING'))) {
    return;
  }

  setup_shutdown();
  disable_newrelic();
  // Will be corrected later when not failing.
  set_header(503);

  // Actual stuff

  profiling_measure('bootstrap');
  profiling_measure('check_db');
  profiling_measure('check_memcache');
  profiling_measure('check_redis');
  profiling_measure('check_elasticsearch');
  profiling_measure('check_fs_scheme_create');
  profiling_measure('check_fs_scheme_delete');
  profiling_measure('check_custom_ping');

  // Finish

  profiling_finish(hrtime(TRUE));

  log_slow_checks();

  $warnings = status_by_severity('warning');
  log_errors($warnings, 'warning');

  $errors = status_by_severity('error');
  finish($errors);

  // Exit immediately.
  // Note the shutdown function registered at the beginning.
  exit();
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
function disable_newrelic(): void {
  if (extension_loaded('newrelic')) {
    newrelic_ignore_transaction();
  }
}

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

// Log errors according to environment.
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

function finish(array $errors = []): void {

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

  $status_tbl = status_tbl();
  $profiling_tbl = profiling_tbl();
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

function log_slow_checks() {
  $slow = profiling_by_duration(1000.0, NULL);
  foreach ($slow as &$value) {
    $value = "duration=$value ms";
  }
  log_errors($slow, 'slow');
}

function is_debug(): bool {

  $debug = $_GET['debug'] ?? NULL;
  if (empty($debug)) {
    return FALSE;
  }

  global $drupal_hash_salt;
  $hash = $drupal_hash_salt ?? '';
  $hash = substr($hash, 0, 4);

  return $debug == $hash;
}

//
// Actual functionality (to be profiled)
//

// Drupal bootstrap.
function bootstrap(): void {
  define('DRUPAL_ROOT', getcwd());
  require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
  drupal_bootstrap(DRUPAL_BOOTSTRAP_DATABASE);
}

// Check that the main database is active.
function check_db(): void {

  status_set_name('db');

  $result = db_query('SELECT * FROM {users} WHERE uid = 1');

  $count = $result->rowCount();
  if ($count > 0) {
    status_set('success');
  }
  else {
    status_set('error', "result_count=$count expected=1 Master database invalid results.");
  }
}

// Check that all memcache instances are running on this server.
function check_memcache(): void {

  global $conf;

  status_set_name('memcache');

  $servers = $conf['memcache_servers'] ?? NULL;
  if (empty($servers)) {
    status_set('disabled');
    return;
  }

  $good_count = 0;
  $bad_count = 0;
  $errors = [];

  // Loop through the defined servers
  foreach ($servers as $address => $bin) {

    list($host, $port) = explode(':', $address);

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
    status_set('success');
    return;
  }

  if ($good_count > 0 && $bad_count > 0) {
    status_set('warning', implode('; ', $errors));
    return;
   }

  if ($good_count < 1 && $bad_count > 0) {
    status_set('error', implode('; ', $errors));
    return;
  }
}

// Handles both:
// * TCP/IP - both host and port are defined
// * Unix Socket - only host is defined as path
function check_redis(): void {

  global $conf;

  status_set_name('redis');

  $host = $conf['redis_client_host'] ?? NULL;
  $port = $conf['redis_client_port'] ?? NULL;

  if (empty($host) || empty($port)) {
    status_set('disabled');
    return;
  }

  // In case of a socket,
  // only host is defined.

  // Use PhpRedis, PRedis is outdated.
  $redis = new \Redis();
  if ($redis->connect($host, $port)) {
    status_set('success');
  }
  else {
    status_set('error', "host=$host port=$port - unable to connect");
  }
}

function check_elasticsearch(): void {

  global $conf;

  status_set_name('elasticsearch');

  // We use ping-specific configuration to check Elasticsearch.
  // Because there are way too many ways how Elasticsearch
  // connections can be defined depending on libs/mods/versions.
  $connections = $conf['ping_elasticsearch_connections'] ?? NULL;
  if (empty($connections)) {
    status_set('disabled');
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
    status_set('success');
    return;
  }

  if ($good_count > 0 && $bad_count > 0) {
    status_set('warning', implode('; ', $errors));
    return;
  }

  if ($good_count < 1 && $bad_count > 0) {
    status_set($c['severity'], implode('; ', $errors));
    return;
  }
}

function check_fs_scheme_create(): void {

  status_set_name('fs-scheme-create');

  $path = variable_get('file_directory_path', conf_path() . '/files');
  $tmp = tempnam($path, 'status_check_');
  if (empty($tmp)) {
    status_set('error', "path=$path - Could not create temporary file in the files directory.");
    return;
  }

  global $check_fs_scheme_file;
  $check_fs_scheme_file = $tmp;

  status_set('success');
}

function check_fs_scheme_delete(): void {

  status_set_name('fs-scheme-delete');

  global $check_fs_scheme_file;
  $tmp = $check_fs_scheme_file;

  if (empty($tmp)) {
    status_set('disabled');
    return;
  }

  if (!unlink($tmp)) {
    status_set('error', "file=$tmp - Could not delete newly created file in the files directory.");
    return;
  }

  status_set('success');
}

// Custom ping checks.
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
// Time Profiling
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

// Return a 2-column text table:
// * Durations (sorted)
// * Check names
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

// Return checks that executed between $minMs and $maxMs.
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

// Return check results by status code.
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

// Return check results as text table.
function status_tbl(): string {
  global $status;
  $lines = [];
  foreach ($status as $name => $details) {
    $lines[] = sprintf('%-20s %-10s %s', $name, $details['severity'], $details['message']);
  }
  $lines = implode(PHP_EOL, $lines);
  return $lines;
}
