<?php

/**
 * @file
 * The Ping Utility.
 *
 * FOR DRUPAL 8 OR 9 ONLY !
 * FILE IS SUPPOSED TO BE IN DRUPAL ROOT DIRECTORY (NEXT TO INDEX.PHP) !!
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

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

    $slows = $this->profile->getByDuration(1000, NULL);
    $payloads = $this->profile2logs($slows, 'slow');
    $this->logErrors($payloads);

    $payloads = $this->status->getBySeverity('warning');
    $payloads = $this->status2logs($payloads, 'warning');
    $this->logErrors($payloads);

    $payloads = $this->status->getBySeverity('error');
    $payloads = $this->status2logs($payloads, 'error');
    $this->logErrors($payloads);

    if (!empty($payloads)) {
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

    $token = $this->getToken($settings);
    if (!$this->isDebug($token) && !$this->isCli()) {
      return;
    }

    if ($this->isCli()) {
      print <<<TXT

<p>Debug token: $token</p>

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
    [$status, $payload] = $checker->getStatusInfo();
    $name = $checker->getName();
    $this->status->set($name, $status, $payload);
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
   * Convert Status array for logging.
   *
   * @param array $payloads
   *   List of payloads.
   * @param string $status
   *   Set status for all payloads.
   *
   * @return array
   *   Return upgraded payload array.
   */
  public function status2logs(array $payloads, string $status): array {
    $payloads2 = [];
    foreach ($payloads as $check => $payload) {
      $payloads2[] = array_merge([
        'check' => $check,
        'status' => $status,
      ], $payload);
    }
    return $payloads2;
  }

  /**
   * Log all slow requests.
   *
   * Fetch all slow requests from the profiling system,
   * and log them.
   *
   * @param array $slows
   *   Slow array.
   * @param string $status
   *   The status to assign.
   *
   * @return array
   *   Return array of slow messages.
   */
  public function profile2logs(array $slows, string $status): array {
    $payloads = [];
    foreach ($slows as $check => $duration) {
      $payloads[] = [
        'check' => $check,
        'status' => $status,
        'duration' => $duration,
        'unit' => 'ms',
      ];
    }
    return $payloads;
  }

  /**
   * Log errors according to the environment.
   *
   * We recognize following envs:
   * - silta -> stderr.
   * - lando -> stderr.
   * - the rest -> syslog().
   *
   * @param array $payloads
   *   Array of payload arrays, containing error message and additional info.
   */
  public function logErrors(array $payloads): void {

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

    foreach ($payloads as $payload) {
      $payload = json_encode($payload);
      $logger("ping: $payload");
    }
  }

  /**
   * Provide Debug Access Token.
   *
   * This is needed to limit access to debug info over the web.
   * Many methods are tried in sequence to define the code.
   *
   * @param array $settings
   *   The Drupal settings.
   *
   * @return string
   *   Access token.
   */
  public function getToken(array $settings): string {

    // $settings['ping_token'].
    if (!empty($settings['ping_token'])) {
      $token = $settings['ping_token'];
      return $token;
    }

    // Env(PING_TOKEN).
    if (!empty(getenv('PING_TOKEN'))) {
      $token = (string) getenv('PING_TOKEN');
      return $token;
    }

    // Md5(Concatenated-values-of-some-env-variables).
    $token = [];
    $env = getenv();
    ksort($env);
    foreach ($env as $key => $value) {
      if (preg_match('/^(DB|ENVIRONMENT_NAME|PROJECT_NAME|S+MTP|VARNISH|WARDEN)/', $key)) {
        // Remove newlines and other whitespace,
        // because the interpretation differs from shell to web.
        $value = preg_replace('/\s/', '', $value);
        $token[] = $value;
      }
    }
    if (!empty($token)) {
      $token = implode('-', $token);
      $token = md5($token);
      return $token;
    }

    // Md5($settings['hash_salt']).
    if (!empty($settings['hash_salt'])) {
      $token = md5($settings['hash_salt']);
      return $token;
    }

    // Md5(Hostname).
    $token = gethostname();
    $token = md5($token);
    return $token;
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
   * Currently it is matching '?debug=token'
   *
   * @param string $token
   *   The token to be checked if present in the request.
   *
   * @return bool
   *   Return if we need to emit debugging info.
   */
  public function isDebug(string $token): bool {

    // @codingStandardsIgnoreLine DrupalPractice.Variables.GetRequestData.SuperglobalAccessedWithVar
    $debug = $_GET['debug'] ?? NULL;
    if (empty($debug)) {
      return FALSE;
    }

    return $debug == $token;
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

  /**
   * The payload of the message.
   *
   * @var array
   */
  protected $payload = [];

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
   *     (array) $payload (related details)
   *   ]
   */
  public function getStatusInfo(): array {
    return [
      $this->status,
      $this->payload,
    ];
  }

  /**
   * Safety wrapper for the check function.
   *
   * The purpose of this function is to catch exceptions.
   *
   * @param string $status
   *   Status: 'disabled', 'success', 'warning', 'error'.
   * @param string $message
   *   The message of the status.
   * @param array $payload
   *   Additional details.
   */
  protected function setStatus(string $status, string $message = '', array $payload = []): void {
    $this->status = $status;
    $p = [];
    if (!empty($message)) {
      $p = array_merge($p, ['message' => $message]);
    }
    if (!empty($payload)) {
      $p = array_merge($p, $payload);
    }
    $this->payload = $p;
  }

  /**
   * Safety wrapper for the check function.
   *
   * The purpose of this function is to catch exceptions.
   */
  public function check(): void {
    $this->setStatus('success');
    try {
      $this->check2();
    }
    catch (\Exception $e) {
      $this->setStatus('error', 'Internal error.', [
        'function' => sprintf('%s::check2()', get_class($this)),
        'exception' => $e->getMessage(),
      ]);
    }
  }

  /**
   * The function that is going to do the actual check.
   *
   * The implementation should use setStatus().
   * The if not used, the status will be 'success'.
   */
  abstract protected function check2(): void;

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
  protected function check2(): void {

    /**
     * @psalm-suppress MissingFile
     */
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
    $this->settings = Settings::getAll();

    // Define file_uri_scheme if it does not exist, it's required by realpath().
    // The function file_uri_scheme is deprecated and will be removed in 9.0.0.
    if (!function_exists('file_uri_scheme')) {

      /**
       * Hmm.
       */
      // @codingStandardsIgnoreLine Drupal.NamingConventions.ValidFunctionName.NotCamelCaps
      function file_uri_scheme($uri) { // @phpstan-ignore-line
        return \Drupal::service('file_system')->uriScheme($uri);
      }

    }
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
  protected function check2(): void {

    // Check that the main database is active.
    $result = Database::getConnection()
      ->query('SELECT * FROM {users} WHERE uid = 1')
      ->fetchAllKeyed();

    $count = count($result);
    $expected = 1;
    if ($count == $expected) {
      return;
    }
    else {
      $this->setStatus('error', 'Master database returned invalid results.', [
        'actual_count' => $count,
        'expected_count' => $expected,
      ]);
      return;
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
   * Convert settings to usable data for this checker.
   *
   * @param array $settings
   *   The Drupal settings array.
   *
   * @return array
   *   Return array of format [['host' => (string)$hostname,
   *   'port' => (int)$portnr, 'bin' => (string)$bin]].
   */
  public static function connectionsFromSettings(array $settings): array {
    $servers = $settings['memcache']['servers'] ?? [];
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
  protected function check2(): void {

    if (empty($this->servers)) {
      $this->setStatus('disabled');
      return;
    }

    $good_count = 0;
    $bad_count = 0;
    $msgs = [];

    // Loop through the defined servers.
    foreach ($this->servers as $s) {

      // We are not relying on Memcache or Memcached classes.
      // For speed and simplicity we use just basic networking.
      $socket = @fsockopen($s['host'], $s['port'], $errno, $errstr, 1);
      if (!empty($errstr)) {
        $msgs[] = [
          'host' => $s['host'],
          'port' => $s['port'],
          'error' => $errstr,
        ];
        $bad_count++;
        continue;
      }
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      fwrite($socket, "stats\n");
      // Just check the first line of the reponse.
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      $line = fgets($socket);
      if (!preg_match('/^STAT /', $line)) {
        $msgs[] = [
          'host' => $s['host'],
          'port' => $s['port'],
          'message' => 'Unexpected response.',
          'response' => $line,
        ];
        $bad_count++;
        continue;
      }
      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      fclose($socket);

      $good_count++;
    }

    if ($good_count > 0 && $bad_count < 1) {
      return;
    }

    if ($good_count > 0 && $bad_count > 0) {
      $this->setStatus('warning', 'Connection warnings.', ['warnings' => $msgs]);
      \Drupal::logger('drupal_ping')->warning('Memcache connection warning. Warning messages: @warnings', ['@warnings' => json_encode($msgs)]);
      return;
    }

    if ($good_count < 1 && $bad_count > 0) {
      $this->setStatus('warning', 'Connection errors.', ['errors' => $msgs]);
      \Drupal::logger('drupal_ping')->error('Memcache connection error. Error messages: @errors', ['@errors' => json_encode($msgs)]);
      return;
    }

    \Drupal::logger('drupal_ping')->error('Memcache internal error.');
    $this->setStatus('warning', 'Internal error.');
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
   * Convert settings to usable data for this checker.
   *
   * @param array $settings
   *   The Drupal settings array.
   *
   * @return array
   *   Return array of format [(string) $host, (int) $port].
   */
  public static function connectionsFromSettings(array $settings): array {
    $host = $settings['redis.connection']['host'] ?? NULL;
    $port = empty($settings['redis.connection']['port']) ? NULL : (int) $settings['redis.connection']['port'];
    return [$host, $port];
  }

  /**
   * Check: Redis connectivity.
   *
   * Handles both:
   * - TCP/IP - both host and port are defined.
   * - Unix Socket - only host is defined as path.
   */
  protected function check2(): void {

    if (empty($this->host) && empty($this->port)) {
      $this->setStatus('disabled');
      return;
    }

    /*
     * In case of a socket,
     * only host is defined.
     */

    // Use PhpRedis, PRedis is outdated.
    $redis = new \Redis();
    if ($redis->connect($this->host, $this->port)) {
      return;
    }
    else {
      $this->setStatus('error', 'Unable to connect.', [
        'host' => $this->host,
        'port' => $this->port,
      ]);
      return;
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
   * Convert settings to usable data for this checker.
   *
   * We use ping-specific configuration to check Elasticsearch.
   * Because there are way too many ways how Elasticsearch
   * connections can be defined depending on libs/mods/versions.
   *
   * @param array $settings
   *   The Drupal settings array.
   *
   * @return array
   *   Return connections array extracted from settings,
   *   possibly reformatted.
   */
  public static function connectionsFromSettings(array $settings): array {
    $connections = $settings['ping_elasticsearch_connections'] ?? [];
    return $connections;
  }

  /**
   * Check: Elasticsearch connectivity by curl.
   */
  protected function check2(): void {

    if (empty($this->connections)) {
      $this->setStatus('disabled');
      return;
    }

    $warnings = [];
    $errors = [];

    // Loop through Elasticsearch connections.
    // Perform basic curl request,
    // and ensure we get green status back.
    foreach ($this->connections as $c) {

      switch ($c['severity']) {
        case 'warning':
          // @codingStandardsIgnoreLine DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
          $msgs = &$warnings;
          break;

        case 'error':
          // @codingStandardsIgnoreLine DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
          $msgs = &$errors;
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
        $msgs[] = [
          'url' => $url,
          'errno' => curl_errno($ch),
          'errstr' => curl_error($ch),
        ];
        curl_close($ch);
        continue;
      }
      curl_close($ch);

      $data = json_decode($json);
      if (empty($data)) {
        $msgs[] = [
          'url' => $url,
          'message' => 'Unable to decode JSON response',
        ];
        continue;
      }

      if (empty($data->status)) {
        $msgs[] = [
          'url' => $url,
          'message' => 'Response does not contain status',
        ];
        continue;
      }

      if ($data->status !== 'green') {
        $msgs[] = [
          'url' => $url,
          'status' => $data->status,
          'message' => 'Not green',
        ];
        continue;
      }
    }

    $warnings_count = count($warnings);
    $errors_count = count($errors);

    /**
     * @psalm-suppress RedundantCondition
     */
    // @phpstan-ignore-next-line
    if ($warnings_count < 1 && $errors_count < 1) {
      return;
    }

    /**
     * @psalm-suppress TypeDoesNotContainType
     */
    // @phpstan-ignore-next-line
    if ($warnings_count > 0 && $errors_count < 1) {
      $this->setStatus('warning', '', $warnings);
      return;
    }

    /**
     * @psalm-suppress RedundantCondition
     */
    if ($warnings_count < 1 && $errors_count > 0) {
      $this->setStatus('error', '', $errors);
      return;
    }

    /**
     * @psalm-suppress TypeDoesNotContainType
     */
    if ($warnings_count > 0 && $errors_count > 0) {
      $this->setStatus('error', '', [
        'warnings' => $warnings,
        'errors' => $errors,
      ]);
      return;
    }
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
  protected $file = '';

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
  protected function check2(): void {

    // Get current defined scheme.
    $scheme = \Drupal::config('system.file')->get('default_scheme');

    // Get the real path of the files uri.
    $path = \Drupal::service('file_system')->realpath($scheme . '://');

    // Need to inject the timestamp into filename,
    // much like ICMP ping injects timestamp into 'an echo request'.
    // Because on NFS-based systems mtime sometimes is random
    // from the perspective of the file creator.
    $prefix = sprintf('status_check__%d__', time());

    // Check that the files directory is operating properly.
    $tmp = \Drupal::service('file_system')->tempnam($path, $prefix);
    // Use env var for testing this code branch.
    // Cannot test it otherwise.
    if (empty($tmp) || !empty(getenv('TESTING_FS_CREATE'))) {
      $this->setStatus('error', 'Could not create temporary file in the files directory.', [
        'path' => $path,
      ]);
      return;
    }
    $this->file = $tmp;
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
  protected function check2(): void {

    if (empty($this->file)) {
      $this->setStatus('disabled');
      return;
    }

    // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
    if (!unlink($this->file)) {
      $this->setStatus('error', 'Could not delete newly created file in the files directory.', [
        'file' => $this->file,
      ]);
      return;
    }
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

    // Get current defined scheme.
    $scheme = \Drupal::config('system.file')->get('default_scheme');

    // Get the real path of the files uri.
    $this->path = \Drupal::service('file_system')->realpath($scheme . '://');
  }

  /**
   * Check: Filesystem item deletion.
   *
   * Note, it depends on 'Filesystem item creation' being executed before.
   */
  protected function check2(): void {

    // Use old format for the pattern to clean up files with old filenames too.
    $pattern = "{$this->path}/status_check_*";
    $files = glob($pattern, GLOB_ERR | GLOB_NOESCAPE | GLOB_NOSORT);
    if ($files === FALSE) {
      $this->setStatus('error', 'Unable to list files.', [
        'pattern' => $pattern,
      ]);
      return;
    }

    $removed = 0;
    foreach ($files as $file) {

      if (!is_file($file)) {
        continue;
      }

      if (filesize($file) !== 0) {
        continue;
      }

      // Get file mtime, derived from filename.
      // The timestamp is kept in the filename
      // because NFS mtime is sometimes random.
      $mtime = basename($file);
      if (!preg_match('/^status_check__(\d+)__/', $mtime, $matches)) {
        // Silently clean up old status check files.
        unlink($file);
        continue;
      }
      $mtime = (int) $matches[1];

      $time = time();

      // Sanity check.
      // Allow few secs of kernel clock drift.
      if ($mtime > $time + 5) {
        $this->setStatus('warning', 'File timestamp is in the future.', [
          'time' => $time,
          'mtime' => $mtime,
          'file' => $file,
        ]);
      }

      // Do not clean up fresh files.
      // In the multi-container environment
      // parallel pings would kill each other.
      if ($mtime > $time - 3600) {
        continue;
      }

      // @codingStandardsIgnoreLine PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem
      if (!unlink($file)) {
        $this->setStatus('error', 'Could not delete file in the public files directory.', [
          'file' => $file,
        ]);
        return;
      }
      $removed++;
    }

    if ($removed > 0) {
      $this->setStatus('warning', 'Orphaned fs check files deleted.', [
        'removed_count' => $removed,
      ]);
    }
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
  protected function check2(): void {

    if (!file_exists('_ping.custom.php')) {
      $this->setStatus('disabled');
      return;
    }

    // Note: the custom script has to use:
    // $status = 'success';
    // $status = 'disabled';
    // $status = 'warning';
    // $status = 'success';
    // $message = '...'; // The error message.
    // $payload[] = [...]; // Debug payload.
    $status = 'success';
    $message = '';
    $payload = [];
    include '_ping.custom.php';

    $this->setStatus($status, $message, $payload);
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
   * @param array $payload
   *   Payload of message/details on the severity. Optional.
   */
  public function set(string $name, string $severity, array $payload = []): void {
    $this->items[$name] = [
      'severity' => $severity,
      'payload' => $payload,
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
   *   The format is ['name' => 'payload'].
   */
  public function getBySeverity(string $severity): array {
    $filtered = [];
    foreach ($this->items as $name => $details) {
      if ($details['severity'] == $severity) {
        $filtered[$name] = $details['payload'];
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
      $severity = $details['severity'];
      $payload = $details['payload'];
      $payload = empty($payload) ? '' : json_encode($payload);
      $lines[] = sprintf('%-20s %-10s %s', $name, $severity, $payload);
    }
    $lines = implode($separator, $lines);
    return $lines;
  }

}
