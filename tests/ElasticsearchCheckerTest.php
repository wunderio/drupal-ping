<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \ElasticsearchChecker
 */
class ElasticsearchCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    if (class_exists('App')) {
      return;
    }
    chdir('/app/drupal9/web');
    putenv('TESTING=1');
    require '_ping.php';
    global $_bootstrapChecker;
    $_bootstrapChecker = new BootstrapChecker();
    $_bootstrapChecker->check();
  }

  /**
   * @covers ::connectionsFromSettings
   */
  public function testConnectionsFromSettings(): void {
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'https',
          'host' => 'elasticsearch',
          'port' => '9200',
          'severity' => 'warning',
        ],
      ],
    ];
    $data = ElasticsearchChecker::connectionsFromSettings($settings);
    $this->assertEquals($settings['ping_elasticsearch_connections'], $data);
  }

  /**
   * @covers ::connectionsFromSettings
   */
  public function testConnectionsFromSettingsNULL(): void {
    $settings = [];
    $data = ElasticsearchChecker::connectionsFromSettings($settings);
    $this->assertEquals([], $data);
  }

  /**
   * @covers ::check2
   */
  public function testCheckDisabledNULL(): void {
    $c = new ElasticsearchChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['disabled', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckDisabledEmptyArray(): void {
    $c = new ElasticsearchChecker([]);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['disabled', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckSuccess(): void {
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9200',
          'severity' => 'warning',
        ],
      ],
    ];
    $connections = ElasticsearchChecker::connectionsFromSettings($settings);
    $c = new ElasticsearchChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckBadProto(): void {
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'https',
          'host' => 'elasticsearch',
          'port' => '9200',
          'severity' => 'error',
        ],
      ],
    ];
    $connections = ElasticsearchChecker::connectionsFromSettings($settings);
    $c = new ElasticsearchChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', 'url=https://elasticsearch:9200/_cluster/health - errno=35 errstr="error:1408F10B:SSL routines:ssl3_get_record:wrong version number"'], $status);
  }

  /**
   * @covers ::check2
   */
  /*
  // Too slow to test - ca 12 sec.
  public function testCheckBadHost(): void {
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'http',
          'host' => 'elasticsearch-test',
          'port' => '9200',
          'severity' => 'error',
        ],
      ],
    ];
    $connections = ElasticsearchChecker::connectionsFromSettings($settings);
    $c = new ElasticsearchChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', 'url=http://elasticsearch-test:9200/_cluster/health - errno=28 errstr="Resolving timed out after 2000 milliseconds"'], $status);
  }
  */

  /**
   * @covers ::check2
   */
  public function testCheckBadPort(): void {
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9201',
          'severity' => 'error',
        ],
      ],
    ];
    $connections = ElasticsearchChecker::connectionsFromSettings($settings);
    $c = new ElasticsearchChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', 'url=http://elasticsearch:9201/_cluster/health - errno=7 errstr="Failed to connect to elasticsearch port 9201: Connection refused"'], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckCombo1(): void {
    // 1 success, 1 failure => warning
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9200',
          'severity' => 'warning',
        ],
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9201',
          'severity' => 'warning',
        ],
      ],
    ];
    $connections = ElasticsearchChecker::connectionsFromSettings($settings);
    $c = new ElasticsearchChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['warning', 'url=http://elasticsearch:9201/_cluster/health - errno=7 errstr="Failed to connect to elasticsearch port 9201: Connection refused"'], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckCombo2(): void {
    // 2 failure => warning
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9201',
          'severity' => 'warning',
        ],
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9201',
          'severity' => 'warning',
        ],
      ],
    ];
    $connections = ElasticsearchChecker::connectionsFromSettings($settings);
    $c = new ElasticsearchChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['warning', 'url=http://elasticsearch:9201/_cluster/health - errno=7 errstr="Failed to connect to elasticsearch port 9201: Connection refused"; url=http://elasticsearch:9201/_cluster/health - errno=7 errstr="Failed to connect to elasticsearch port 9201: Connection refused"'], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckCombo3(): void {
    // 1 success, 1 failure => error
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9200',
          'severity' => 'error',
        ],
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9201',
          'severity' => 'error',
        ],
      ],
    ];
    $connections = ElasticsearchChecker::connectionsFromSettings($settings);
    $c = new ElasticsearchChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', 'url=http://elasticsearch:9201/_cluster/health - errno=7 errstr="Failed to connect to elasticsearch port 9201: Connection refused"'], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckCombo4(): void {
    // 2 failure => error
    $settings = [
      'ping_elasticsearch_connections' => [
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9201',
          'severity' => 'error',
        ],
        [
          'proto' => 'http',
          'host' => 'elasticsearch',
          'port' => '9201',
          'severity' => 'error',
        ],
      ],
    ];
    $connections = ElasticsearchChecker::connectionsFromSettings($settings);
    $c = new ElasticsearchChecker($connections);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', 'url=http://elasticsearch:9201/_cluster/health - errno=7 errstr="Failed to connect to elasticsearch port 9201: Connection refused"; url=http://elasticsearch:9201/_cluster/health - errno=7 errstr="Failed to connect to elasticsearch port 9201: Connection refused"'], $status);
  }

}
