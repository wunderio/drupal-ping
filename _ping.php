<?php

/**
 * @file
 * The Ping Utility.
 *
 * FOR DRUPAL 7 ONLY!
 * FILE IS SUPPOSED TO BE IN DRUPAL ROOT DIRECTORY (NEXT TO INDEX.PHP) !!
 */

declare(strict_types=1);

if (empty(getenv('TESTING'))) {
  $app = new App();
  $app->run();
  // Exit immediately.
  // Note the shutdown function registered at the beginning.
  exit();
}

/**
 * The Application itself.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class App {

  /**
   * Profile object.
   *
   * @var object
   */
  private $profile;

  /**
   * Status object.
   *
   * @var object
   */
  private $status;

  /**
   * The main function.
   */
  public function run(): void {

    /*
     * Setup
     */

    // Start profiling as early as possible.
    $this->profile = new Profile();
    $this->status = new Status();

    $this->setupShutdown();
    $this->disableNewrelic();
    // Will be corrected later when not failing.
    $this->setHeader(503);

    /*
     * Actual stuff.
     */

    $check = $this->check('BootstrapChecker');

    $settings = $check->getSettings();

    $check = $this->check('DbChecker');

    $servers = MemcacheChecker::connectionsFromSettings($settings);

    $check = $this->check('MemcacheChecker', [$servers]);

    [$host, $port] = RedisChecker::connectionsFromSettings($settings);

    $check = $this->check('RedisChecker', [$host, $port]);

    $connections = ElasticsearchChecker::connectionsFromSettings($settings);

    $check = $this->check('ElasticsearchChecker', [$connections]);

    $check = $this->check('FsSchemeCreateChecker');

    $file = $check->getFile();

    $check = $this->check('FsSchemeDeleteChecker', [$file]);

    $check = $this->check('FsSchemeCleanupChecker');

    $check = $this->check('CustomPingChecker');

    /*
     * Finish.
     */

    $this->profile->stop();

    $slow = $this->getSlow($this->profile);
    $this->logErrors($slow, 'slow');

    $warnings = $this->status->getBySeverity('warning');
    $this->logErrors($warnings, 'warning');

    $errors = $this->status->getBySeverity('error');

    if (!empty($errors)) {
      $this->logErrors($errors, 'error');
      $code = 500;
      $msg = 'INTERNAL ERROR';
    }
    else {
      $code = 200;
      $msg = 'CONGRATULATIONS';
    }
    $this->setHeader($code);
    // Split up this message, to prevent the remote chance
    // of monitoring software reading the source code
    // if mod_php fails and then matching the string.
    print "$msg $code" . PHP_EOL;

    $code = $this->getDebugCode($settings);
    if (!$this->isDebug($code) && !$this->isCli()) {
      return;
    }

    if ($this->isCli()) {
      print <<<TXT

<p>Debug code: $code</p>

TXT;
    }

    $status_tbl = $this->status->getTextTable(PHP_EOL);
    $profiling_tbl = $this->profile->getTextTable(PHP_EOL);
    print <<<TXT

<pre>
$status_tbl
</pre>

<pre>
$profiling_tbl
</pre>

TXT;
  }

  /**
   * Custom shutdown.
   *
   * Register our shutdown function so that no other shutdown functions run
   * before this one.  This shutdown function calls exit(), immediately
   * short-circuiting any other shutdown functions, such as those registered by
   * the devel.module for statistics.
   */
  public function setupShutdown(): void {
    // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FunctionHandlingFunctions.WarnFunctionHandling
    register_shutdown_function(function () {
      exit();
    });
  }

  /**
   * Perform the check.
   *
   * @param string $class
   *   The checker class.
   * @param array $args
   *   The args for checker constructor.
   *
   * @return object
   *   Return the checker object.
   */
  public function check(string $class, array $args = []): object {
    $checker = new $class(...$args);
    $this->profile->measure([$checker, 'check'], $checker->getName());
    [$s, $i] = $checker->getStatusInfo();
    $name = $checker->getName();
    $this->status->set($name, $s, $i);
    return $checker;
  }

  /**
   * Disable NewRelic.
   *
   * We want to ignore _ping.php from New Relic statistics.
   * _ping.php skews the overall statistics significantly.
   */
  public function disableNewrelic(): void {
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
  public function setHeader(int $code): string {
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
  public function logErrors(array $errors, string $category): void {

    if (!empty(getenv('TESTING'))) {
      $logger = function (string $msg) {
        global $_logs;
        if (empty($_logs)) {
          $_logs = [];
        }
        $_logs[] = $msg;
      };
    }
    elseif (!empty(getenv('SILTA_CLUSTER')) || !empty(getenv('LANDO'))) {
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
   * Log all slow requests.
   *
   * Fetch all slow requests from the profiling system,
   * and log them.
   *
   * @return array
   *   return array of slow messages.
   */
  public function getSlow(object $profile): array {
    $slow = $profile->getByDuration(1000, NULL);
    foreach ($slow as &$value) {
      $value = "duration=$value ms";
    }
    return $slow;
  }

  /**
   * Compute Debug Code.
   *
   * This is needed to limit access to debug info over the web.
   * Many methods are tried in sequence to define the code.
   *
   * @param array $settings
   *   The Drupal settings.
   *
   * @return string
   *   Access code.
   */
  public function getDebugCode(array $settings): string {

    // $settings['ping_debug'].
    if (!empty($settings['ping_debug'])) {
      $code = $settings['ping_debug'];
      return $code;
    }

    // Echo "$PROJECT_NAME-$ENVIRONMENT_NAME" | md5sum.
    if (!empty(getenv('SILTA_CLUSTER'))) {
      $proj = getenv('PROJECT_NAME');
      $env = getenv('ENVIRONMENT_NAME');
      $code = md5("$proj-$env");
      return $code;
    }

    // Echo "$DB_HOST_DRUPAL-$DB_NAME_DRUPAL-$DB_PASS_DRUPAL-$DB_PORT_DRUPAL-$DB_USER_DRUPAL"
    // | md5sum.
    if (!empty(getenv('DB_NAME_DRUPAL'))) {
      $host = getenv('DB_HOST_DRUPAL');
      $name = getenv('DB_NAME_DRUPAL');
      $pass = getenv('DB_PASS_DRUPAL');
      $port = getenv('DB_PORT_DRUPAL');
      $user = getenv('DB_USER_DRUPAL');
      $code = md5("$host-$name-$pass-$port-$user");
      return $code;
    }

    // Md5($settings['hash_salt']).
    if (!empty($settings['hash_salt'])) {
      $code = md5($settings['hash_salt']);
      return $code;
    }

    // Hostname | md5sum.
    $code = gethostname();
    $code = md5($code);
    return $code;
  }

  /**
   * Detect if we are running in CLI mode.
   *
   * @return bool
   *   True = CLI mode, False = WEB mode.
   */
  public function isCli(): bool {
    $isCli = php_sapi_name() === 'cli';
    return $isCli;
  }

  /**
   * Detect if debug information should be provided on request.
   *
   * Currently it is matching '?debug=code'
   *
   * @param string $code
   *   The code to be checked if present in the request.
   *
   * @return bool
   *   Return if we need to emit debugging info.
   */
  public function isDebug(string $code): bool {

    // @codingStandardsIgnoreLine DrupalPractice.Variables.GetRequestData.SuperglobalAccessedWithVar
    $debug = $_GET['debug'] ?? NULL;
    if (empty($debug)) {
      return FALSE;
    }

    return $debug == $code;
  }

}

/*
 * Actual functionality (to be profiled).
 */

/**
 * Abstract Checker class.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
abstract class Checker {

  /**
   * Warnings happened during the check.
   *
   * @var array
   */
  protected $warnings = [];

  /**
   * Errors happened during the check.
   *
   * @var array
   */
  protected $errors = [];

  /**
   * The status for the check result.
   *
   * @var string
   */
  protected $status = '';

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = '';

  /*
   * Configure the checker.
   * abstract public function __construct(...config...);
   */

  /**
   * Get human-readable checker name.
   *
   * @return string
   *   Return human-readable checker name.
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * Return checker status.
   *
   * @return array
   *   Array of [
   *     (string) $status ('success'|'disabled'|'error'|'warning'),
   *     (string) $info (warning or error info)
   *   ]
   */
  public function getStatusInfo(): array {

    $status = '';
    $info = [];

    if (!empty($this->status)) {
      $status = $this->status;
      goto ret;
    }

    if (!empty($this->warnings)) {
      $status = 'warning';
      $info = array_merge($info, $this->warnings);
    }

    if (!empty($this->errors)) {
      $status = 'error';
      $info = array_merge($info, $this->errors);
    }

    ret:
    return [
      $status,
      implode('; ', $info),
    ];
  }

  /**
   * Safety wrapper for the check function.
   *
   * The purpose of this function is to catch exceptions.
   */
  public function check(): void {
    $this->status = '';
    try {
      $this->status = $this->check2();
    }
    catch (\Exception $e) {
      $this->errors[] = sprintf('%s::check2(): %s', get_class($this), $e->getMessage());
    }
  }

  /**
   * The function that is going to do the actual check.
   *
   * If errors or warnings are issued, then they have to be added
   * to internal arrays of $this->warnings and $this->errors,
   * and return ''.
   *
   * @return string
   *   The function should return 'success', 'disabled',
   *   or '' if errors or warnigs.
   */
  abstract protected function check2(): string;

}

/**
 * Check Drupal Bootstrap.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class BootstrapChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'bootstrap';

  /**
   * Drupal Settings.
   *
   * @var array
   */
  protected $settings;

  /**
   * Get Drupal Settings.
   *
   * @return array
   *   The Drupal settings.
   */
  public function getSettings(): array {
    return empty($this->settings) ? [] : $this->settings;
  }

  /**
   * Drupal bootstrap.
   *
   * This is a prerequisite for the rest of the checks.
   * It is a check, but also a setup.
   */
  protected function check2(): string {

    // Define DRUPAL_ROOT if it's not yet defined by bootstrap.
    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', getcwd());
    }
    require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
    drupal_bootstrap(DRUPAL_BOOTSTRAP_DATABASE);

    // Get current settings.
    // And save them for other functions.
    global $conf;
    $this->settings = $conf;

    return 'success';
  }

}

/**
 * Check Drupal Dababase availability.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class DbChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'db';

  /**
   * Check: Main database connectivity.
   */
  protected function check2(): string {

    // Check that the main database is active.
    $result = db_query('SELECT * FROM {users} WHERE uid = 1');

    $count = $result->rowCount();
    $expected = 1;
    if ($count == $expected) {
      return 'success';
    }
    else {
      $this->errors[] = "result_count=$count expected=$expected Master database invalid results.";
      return '';
    }
  }

}

/**
 * Check Memcache connectivity.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class MemcacheChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'memcache';

  /**
   * The list of servers to be checked.
   *
   * @var array
   */
  protected $servers;

  /**
   * Set configuration.
   *
   * @param array $servers
   *   List of servers.
   */
  public function __construct(array $servers = NULL) {
    $this->servers = $servers;
  }

  /**
   * Convert conf to usable data for this checker.
   *
   * @param array $conf
   *   The Drupal conf array.
   *
   * @return array
   *   Return array of format [['host' => (string)$hostname,
   *   'port' => (int)$portnr, 'bin' => (string)$bin]].
   */
  public static function connectionsFromSettings(array $conf): array {
    $servers = $conf['memcache_servers'] ?? [];
    $servers2 = [];
    foreach ($servers as $address => $bin) {
      [$host, $port] = explode(':', $address);
      $servers2[] = [
        'host' => $host,
        'port' => (int) $port,
        'bin' => $bin,
      ];
    }
    return $servers2;
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
  protected function check2(): string {

    if (empty($this->servers)) {
      return 'disabled';
    }

    $good_count = 0;
    $bad_count = 0;
    $msgs = [];

    // Loop through the defined servers.
    foreach ($this->servers as $s) {

      // We are not relying on Memcache or Memcached classes.
      // For speed and simplicity we use just basic networking.
      $socket = fsockopen($s['host'], $s['port'], $errno, $errstr, 1);
      if (!empty($errstr)) {
        $msgs[] = "host={$s['host']} port={$s['port']} - $errstr";
        $bad_count++;
        continue;
      }
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      fwrite($socket, "stats\n");
      // Just check the first line of the reponse.
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      $line = fgets($socket);
      if (!preg_match('/^STAT /', $line)) {
        $msgs[] = "host={$s['host']} port={$s['port']} response='$line' - Unexpected response";
        $bad_count++;
        continue;
      }
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      fclose($socket);

      $good_count++;
    }

    if ($good_count > 0 && $bad_count < 1) {
      return 'success';
    }

    if ($good_count > 0 && $bad_count > 0) {
      $this->warnings = array_merge($this->warnings, $msgs);
      // Warnings.
      return '';
    }

    if ($good_count < 1 && $bad_count > 0) {
      $this->errors = array_merge($this->errors, $msgs);
      // Errors.
      return '';
    }

    return 'internal_error';
  }

}

/**
 * Check Redis connectivity.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class RedisChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'redis';

  /**
   * Hostname.
   *
   * @var string
   */
  protected $host;

  /**
   * Port.
   *
   * @var int
   */
  protected $port;

  /**
   * Set configuration.
   *
   * @param string $host
   *   Hostname.
   * @param int $port
   *   Port.
   */
  public function __construct(string $host = NULL, int $port = NULL) {
    $this->host = $host;
    $this->port = $port;
  }

  /**
   * Convert conf to usable data for this checker.
   *
   * @param array $conf
   *   The Drupal conf array.
   *
   * @return array
   *   Return array of format [(string) $host, (int) $port].
   */
  public static function connectionsFromSettings(array $conf): array {

    $host = $conf['redis_client_host'] ?? NULL;
    $port = empty($conf['redis_client_port']) ? NULL : (int) $conf['redis_client_port'];
    return [$host, $port];
  }

  /**
   * Check: Redis connectivity.
   *
   * Handles both:
   * - TCP/IP - both host and port are defined.
   * - Unix Socket - only host is defined as path.
   */
  protected function check2(): string {

    if (empty($this->host) && empty($this->port)) {
      return 'disabled';
    }

    /*
     * In case of a socket,
     * only host is defined.
     */

    // Use PhpRedis, PRedis is outdated.
    $redis = new \Redis();
    if ($redis->connect($this->host, $this->port)) {
      return 'success';
    }
    else {
      $this->errors[] = "host={$this->host} port={$this->port} - unable to connect";
      return '';
    }
  }

}

/**
 * Check ElasticSearch connectivity.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class ElasticsearchChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'elasticsearch';

  /**
   * Connections to be checked.
   *
   * @var array
   */
  protected $connections;

  /**
   * Set configuration.
   *
   * @param array $connections
   *   Connections from connectionsFromSettings().
   */
  public function __construct(array $connections = NULL) {
    $this->connections = $connections;
  }

  /**
   * Convert conf to usable data for this checker.
   *
   * We use ping-specific configuration to check Elasticsearch.
   * Because there are way too many ways how Elasticsearch
   * connections can be defined depending on libs/mods/versions.
   *
   * @param array $conf
   *   The Drupal conf array.
   *
   * @return array
   *   Return connections array extracted from conf,
   *   possibly reformatted.
   */
  public static function connectionsFromSettings(array $conf): array {
    $connections = $conf['ping_elasticsearch_connections'] ?? [];
    return $connections;
  }

  /**
   * Check: Elasticsearch connectivity by curl.
   */
  protected function check2(): string {

    if (empty($this->connections)) {
      return 'disabled';
    }

    $good_count = 0;
    $bad_count = 0;

    // Loop through Elasticsearch connections.
    // Perform basic curl request,
    // and ensure we get green status back.
    foreach ($this->connections as $c) {

      switch ($c['severity']) {
        case 'warning':
          // @codingStandardsIgnoreLine DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
          $msgs = &$this->warnings;
          break;

        case 'error':
          // @codingStandardsIgnoreLine DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
          $msgs = &$this->errors;
          break;
      }

      $url = sprintf('%s://%s:%d%s', $c['proto'], $c['host'], $c['port'], '/_cluster/health');
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 2000);
      curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2000);
      curl_setopt($ch, CURLOPT_USERAGENT, "ping");
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      $json = curl_exec($ch);
      if (empty($json)) {
        $msgs[] = sprintf('url=%s - errno=%d errstr="%s"', $url, curl_errno($ch), curl_error($ch));
        curl_close($ch);
        $bad_count++;
        continue;
      }
      curl_close($ch);

      $data = json_decode($json);
      if (empty($data)) {
        $msgs[] = sprintf('url=%s - %s', $url, 'Unable to decode JSON response');
        $bad_count++;
        continue;
      }

      if (empty($data->status)) {
        $msgs[] = sprintf('url=%s - %s', $url, 'Response does not contain status');
        $bad_count++;
        continue;
      }

      if ($data->status !== 'green') {
        $msgs[] = sprintf('url=%s status=%s - %s', $url, $data->status, 'Not green');
        $bad_count++;
        continue;
      }

      $good_count++;
    }

    if ($good_count > 0 && $bad_count < 1) {
      return 'success';
    }

    // Warnings or Errors.
    return '';
  }

}

/**
 * Check if file can be created in public files.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class FsSchemeCreateChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'fs-scheme-create';

  /**
   * The path to the file.
   *
   * @var string
   */
  protected $file;

  /**
   * Return the created file path.
   *
   * @return string
   *   Return path to the created file.
   */
  public function getFile(): string {
    return $this->file;
  }

  /**
   * Check: Filesystem item creation.
   *
   * Note, 'Filesystem item deletion' depends on this, and is executed after.
   */
  protected function check2(): string {

    $path = variable_get('file_directory_path', conf_path() . '/files');
    $tmp = tempnam($path, 'status_check_');
    if (empty($tmp)) {
      $this->errors[] = "path=$path - Could not create temporary file in the files directory.";
      return '';
    }

    $this->file = $tmp;

    return 'success';
  }

}

/**
 * Check if file can be removed from public files.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class FsSchemeDeleteChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'fs-scheme-delete';

  /**
   * The filename fo be attempted to remove.
   *
   * @var string
   */
  protected $file;

  /**
   * Constructor for file removal.
   *
   * @param string $file
   *   Filename to be attempted to remove.
   */
  public function __construct(string $file = NULL) {
    $this->file = $file;
  }

  /**
   * Check: Filesystem item deletion.
   *
   * Note, it depends on 'Filesystem item creation' being executed before.
   */
  protected function check2(): string {

    if (empty($this->file)) {
      return 'disabled';
    }

    // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
    if (!unlink($this->file)) {
      $this->errors[] = "file={$this->file} - Could not delete newly created file in the files directory.";
      return '';
    }

    return 'success';
  }

}

/**
 * Check if there are leftover files in public files, remove them.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class FsSchemeCleanupChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'fs-scheme-cleanup';

  /**
   * Public files dir.
   *
   * @var string
   */
  protected $path;

  /**
   * Constructor for file cleanup.
   *
   * @param string $path
   *   Optional public files path. Needed for testing.
   */
  public function __construct(string $path = NULL) {

    // For testing.
    if (!empty($path)) {
      $this->path = $path;
      return;
    }

    // Get the real path of the files uri.
    $this->path = variable_get('file_directory_path', conf_path() . '/files');
  }

  /**
   * Check: Filesystem item deletion.
   *
   * Note, it depends on 'Filesystem item creation' being executed before.
   */
  protected function check2(): string {

    $pattern = "{$this->path}/status_check_*";
    $files = glob($pattern, GLOB_ERR | GLOB_NOESCAPE | GLOB_NOSORT);
    if ($files === FALSE) {
      $this->errors[] = "pattern=$pattern Unable to list files.";
      return '';
    }

    $removed = 0;
    foreach ($files as $file) {
      if (!is_file($file)) {
        continue;
      }
      if (filesize($file) !== 0) {
        continue;
      }
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      if (!unlink($file)) {
        $this->errors[] = "file=$file - Could not delete file in the public files directory.";
        return '';
      }
      $removed++;
    }

    if ($removed > 0) {
      $this->errors[] = "removed=$removed Orphaned fs check files deleted.";
      return '';
    }

    return 'success';
  }

}

/**
 * Check by Custom Ping.
 */
// @codingStandardsIgnoreLine Drupal.Classes.ClassFileName.NoMatch
class CustomPingChecker extends Checker {

  /**
   * The name of the checker.
   *
   * @var string
   */
  protected $name = 'custom-ping';

  /**
   * Check: Custom ping.
   *
   * _ping.custom.php will be executed if present.
   */
  protected function check2(): string {

    if (!file_exists('_ping.custom.php')) {
      return 'disabled';
    }

    // We set the status in advance,
    // but it will be overridden by the custom ping,
    // or by catch(){}.
    $status = 'success';

    // Note: the custom script has to use:
    // $status = 'success'; // if successful.
    // $status = 'disabled'; // if disabled.
    // $status = ''; // if warnings or errors.
    // $this->warnings[] = '...'; // if warnings.
    // $this->errors[] = '...'; // if errors.
    include '_ping.custom.php';

    return $status;
  }

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
   * Time[ns] spent on php startup.
   *
   * @var int
   */
  private $preboot = 0;

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
    $this->preboot = $this->spent();
  }

  /**
   * Stop global execution measurement.
   */
  public function stop(): void {

    $this->stop = hrtime(TRUE);

    $this->duration = $this->spent();
    if (empty($this->duration)) {
      $this->duration = $this->stop - $this->start;
      return;
    }
  }

  /**
   * Nanoseconds spent from the execution of the script.
   *
   * @return int
   *   Nanoseconds from the start of the execution.
   */
  private function spent(): int {
    $zero = $_SERVER['REQUEST_TIME_FLOAT'];
    if (empty($zero)) {
      return 0;
    }
    $now = microtime(TRUE);
    $duration = (int) (($now - $zero) * 1e9);
    return $duration;
  }

  /**
   * Execute a function, and measure execution time and catch errors.
   *
   * The time measures is being recorded internally, but not returned.
   * The error-catching is way too convenient and tempting here,
   * though it violates the single responsibility.
   *
   * @param callable $func
   *   A specific check to be run under profiling.
   * @param string $name
   *   The name of the measurement.
   */
  public function measure(callable $func, string $name): void {
    $start = hrtime(TRUE);
    $func();
    $end = hrtime(TRUE);
    $duration = $end - $start;
    $this->items[$name] = $duration;
  }

  /**
   * Return Intenal data for testing.
   */
  public function get(): array {
    return $this->items;
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

    arsort($this->items);
    $lines = [];
    foreach ($this->items as $func => $duration) {
      $lines[] = sprintf($fmt, $duration / 1000000, $func);
    }

    $lines[] = '';
    $lines[] = sprintf($fmt, $this->preboot / 1000000, 'preboot');
    $lines[] = sprintf($fmt, $this->duration / 1000000, 'total');

    $lines = implode($separator, $lines);
    return $lines;
  }

  /**
   * Filter checks that executed between $minMs and $maxMs.
   *
   * @return array
   *   The format is ['func' => durationMs].
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
   * Set status for the current context.
   *
   * @param string $name
   *   The name of object of the status.
   * @param string $severity
   *   One of: 'error', 'warning', 'success', 'disabled'.
   * @param string $message
   *   Further details on the severity. Optional.
   */
  public function set(string $name, string $severity, string $message = ''): void {
    $this->items[$name] = [
      'severity' => $severity,
      'message' => $message,
    ];
  }

  /**
   * Get all statuses.
   *
   * Needed for testing.
   *
   * @return array
   *   Return status structure.
   */
  public function get(): array {
    return $this->items;
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
    foreach ($this->items as $name => $details) {
      $lines[] = sprintf('%-20s %-10s %s', $name, $details['severity'], $details['message']);
    }
    $lines = implode($separator, $lines);
    return $lines;
  }

}
