<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \MemcacheChecker
 */
class MemcacheCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  /**
   * @covers ::connectionsFromSettings
   */
  public function testConnectionsFromSettingsNULL(): void {
    $settings = [];
    $data = MemcacheChecker::connectionsFromSettings($settings);
    $this->assertEquals([], $data);
  }

  /**
   * @covers ::connectionsFromSettings
   */
  public function testConnectionsFromSettings(): void {
    $settings = [
      'memcache' => [
        'servers' => [
          'host1:1234' => 'test1',
          'host2:2345' => 'test2',
        ],
      ],
    ];
    $data = MemcacheChecker::connectionsFromSettings($settings);
    $expected = [
      [
        'host' => 'host1',
        'port' => 1234,
        'bin' => 'test1',
      ],
      [
        'host' => 'host2',
        'port' => 2345,
        'bin' => 'test2',
      ],
    ];
    $this->assertEquals($expected, $data);
  }


  /**
   * @covers ::check2
   */
  public function testCheckDisabledNone(): void {
    $c = new MemcacheChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['disabled', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckDisabledEmpty(): void {
    $c = new MemcacheChecker([]);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['disabled', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckSuccess(): void {
    $memcached = json_decode(getenv('LANDO_INFO'))->memcached;
    $host = $memcached->internal_connection->host;
    $port = $memcached->internal_connection->port;
    $settings['memcache']['servers'] = [
      "$host:$port" => 'default',
    ];
    $connections = MemcacheChecker::connectionsFromSettings($settings);
    $c = new MemcacheChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckErrorPort(): void {
    $memcached = json_decode(getenv('LANDO_INFO'))->memcached;
    $host = $memcached->internal_connection->host;
    $port = 64000; // $memcached->internal_connection->port;
    $settings['memcache']['servers'] = [
      "$host:$port" => 'default',
    ];
    $connections = MemcacheChecker::connectionsFromSettings($settings);
    $c = new MemcacheChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $data = [
      'message' => 'Connection errors.',
      'errors' => [[
        'host' => 'memcached',
        'port' => 64000,
        'error' => 'Connection refused',
      ]],
    ];
    $data = json_encode($data);
    $this->assertEquals(['error', $data], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckErrorHost(): void {
    $memcached = json_decode(getenv('LANDO_INFO'))->memcached;
    $host = 'localhost'; // $memcached->internal_connection->host;
    $port = $memcached->internal_connection->port;
    $settings['memcache']['servers'] = [
      "$host:$port" => 'default',
    ];
    $connections = MemcacheChecker::connectionsFromSettings($settings);
    $c = new MemcacheChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $data = [
      'message' => 'Connection errors.',
      'errors' => [[
        'host' => 'localhost',
        'port' => 11211,
        'error' => 'Cannot assign requested address',
      ]],
    ];
    $data = json_encode($data);
    $this->assertEquals(['error', $data], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckSuccessMulti(): void {
    $memcached = json_decode(getenv('LANDO_INFO'))->memcached;
    $host = $memcached->internal_connection->host;
    $port = (int) $memcached->internal_connection->port;
    $connections = [
      [
        'host' => $host,
        'port' => $port,
      ],
      [
        'host' => $host,
        'port' => $port,
      ],
    ];
    $c = new MemcacheChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckWarningMulti(): void {
    $memcached = json_decode(getenv('LANDO_INFO'))->memcached;
    $host = $memcached->internal_connection->host;
    $port = (int) $memcached->internal_connection->port;
    $connections = [
      [
        'host' => $host,
        'port' => $port + 1,
      ],
      [
        'host' => $host,
        'port' => $port,
      ],
    ];
    $c = new MemcacheChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $data = [
      'message' => 'Connection warnings.',
      'warnings' => [
        [
          'host' => 'memcached',
          'port' => 11212,
          'error' => 'Connection refused',
        ],
      ],
    ];
    $data = json_encode($data);
    $this->assertEquals(['warning', $data], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckErrorMulti(): void {
    $memcached = json_decode(getenv('LANDO_INFO'))->memcached;
    $host = $memcached->internal_connection->host;
    $port = (int) $memcached->internal_connection->port;
    $connections = [
      [
        'host' => $host,
        'port' => $port + 1,
      ],
      [
        'host' => $host,
        'port' => $port + 1,
      ],
    ];
    $c = new MemcacheChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $data = [
      'message' => 'Connection errors.',
      'errors' => [
        [
          'host' => 'memcached',
          'port' => 11212,
          'error' => 'Connection refused',
        ],
        [
          'host' => 'memcached',
          'port' => 11212,
          'error' => 'Connection refused',
        ],
      ],
    ];
    $data = json_encode($data);
    $this->assertEquals(['error', $data], $status);
  }

}
