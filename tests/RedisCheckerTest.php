<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \RedisChecker
 */
class RedisCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  /**
   * @covers ::connectionsFromSettings
   */
  public function testConnectionsFromSettings(): void {
    $settings = [
      'redis.connection' => [
        'host' => 'redis',
        'port' => '1234',
      ],
    ];
    $data = RedisChecker::connectionsFromSettings($settings);
    $this->assertEquals(['redis', 1234], $data);
  }

  /**
   * @covers ::connectionsFromSettings
   */
  public function testConnectionsFromSettingsSocket(): void {
    $settings = [
      'redis.connection' => [
        'host' => 'redis',
      ],
    ];
    $data = RedisChecker::connectionsFromSettings($settings);
    $this->assertEquals(['redis', NULL], $data);
  }

  /**
   * @covers ::connectionsFromSettings
   */
  public function testConnectionsFromSettingsNothing(): void {
    $settings = [];
    $data = RedisChecker::connectionsFromSettings($settings);
    $this->assertEquals([NULL, NULL], $data);
  }

  /**
   * @covers ::check2
   */
  public function testCheckDisabled(): void {
    $c = new RedisChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['disabled', ''], $status);
  }

  /**
   * @covers ::check2
   */
  /*
  // Not easy to test.
  public function testCheckSocket(): void {
    $connection = ['/var/run/socket/redis', NULL];
    $c = new RedisChecker([]);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }
  */

  /**
   * @covers ::check2
   */
  public function testCheckSuccess(): void {
    $redis = json_decode(getenv('LANDO_INFO'))->redis;
    $settings = [
      'redis.connection' => [
        'host' => $redis->internal_connection->host,
        'port' => $redis->internal_connection->port,
      ],
    ];
    $connection = RedisChecker::connectionsFromSettings($settings);
    $c = new RedisChecker(...$connection);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::check2
   */
  /*
  // Too slow for testing, ca 12 sec.
  public function testCheckErrorHost(): void {
    $redis = json_decode(getenv('LANDO_INFO'))->redis;
    $settings = [
      'redis.connection' => [
        'host' => 'redis-test',
        'port' => $redis->internal_connection->port,
      ],
    ];
    $connection = RedisChecker::connectionsFromSettings($settings);
    $c = new RedisChecker(...$connection);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', ''], $status);
  }
  */

  /**
   * @covers ::check2
   */
  public function testCheckErrorHost(): void {
    $redis = json_decode(getenv('LANDO_INFO'))->redis;
    $settings = [
      'redis.connection' => [
        'host' => $redis->internal_connection->host,
        'port' => $redis->internal_connection->port + 1,
      ],
    ];
    $connection = RedisChecker::connectionsFromSettings($settings);
    $c = new RedisChecker(...$connection);
    $c->check();
    $status = $c->getStatusInfo();
    $data = [
      'message' => 'Internal error.',
      'function' => 'RedisChecker::check2()',
      'exception' => 'Connection refused',
    ];
    $data = json_encode($data);
    $this->assertEquals(['error', $data], $status);
  }

}
