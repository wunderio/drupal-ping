<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \App
 */
class AppTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  protected function setUp(): void {
    // Cleanup env before every test
    foreach (getenv() as $key => $value) {
      if (preg_match('/^(DB|ENVIRONMENT_NAME|GIT|PHP|PROJECT_NAME|S+MTP|VARNISH|WARDEN)/', $key)) {
        putenv($key);
      }
    }
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor(): void {
    $a = new App();
    $this->assertIsObject($a);
  }

  /**
   * @covers ::logErrors
   */
  public function testlogErrors(): void {
    putenv('TESTING=1');
    $a = new App();
    global $_logs;
    $_logs = NULL;
    $a->logErrors([
      ['check1' => 'msg1'],
      ['check2' => 'msg2'],
    ]);
    $expected = [
      'ping: {"check1":"msg1"}',
      'ping: {"check2":"msg2"}',
    ];
    $this->assertEquals($expected, $_logs);
  }

  /**
   * @covers ::status2logs
   */
  public function testStatus2Logs(): void {
    $a = new App();
    $data = $a->status2logs(['a' => [], 'b' => ['x' => 'y']], 'test_status');
    $this->assertEquals([[
      'check' => 'a',
      'status' => 'test_status',
    ], [
      'check' => 'b',
      'status' => 'test_status',
      'x' => 'y',
    ]], $data);
  }

  /**
   * @covers ::profile2logs
   */
  public function testProfile2logs(): void {
    $a = new App();
    $p = new Profile();

    $func1 = function () {};
    $name1 = 'test1';
    $p->measure($func1, $name1);

    $func2 = function () { sleep(1); };
    $name2 = 'test2';
    $p->measure($func2, $name2);

    $slows = $p->getByDuration(1000, NULL);
    $data = $a->profile2logs($slows, 'slow');

    $this->assertIsArray($data);
    $this->assertCount(1, $data);
    $data = reset($data);
    $this->assertIsFloat($data['duration']);
    unset($data['duration']);
    $this->assertEquals([
      "check" => "test2",
      "status" => "slow",
      "unit" => "ms",
    ], $data);
  }

  /**
   * @covers ::getToken
   */
  public function testGetTokenSettings(): void {
    $a = new App();
    $token = '1234';
    $settings = ['ping_token' => $token];
    $data = $a->getToken($settings);
    $this->assertEquals($token, $data);
  }

  /**
   * @covers ::getToken
   */
  public function testGetTokenEnvToken(): void {
    $token = '1234';
    putenv("PING_TOKEN=$token");
    $a = new App();
    $data = $a->getToken([]);
    $this->assertEquals($token, $data);
  }

  /**
   * @covers ::getToken
   */
  public function testGetTokenEnv(): void {
    $a = new App();
    foreach ([
      'DB_NAME',
      'ENVIRONMENT_NAME',
      'GIT_TEST',
      'PHP_TEST',
      'PROJECT_NAME',
      'SMTP',
      'SSMTP',
      'VARNISH_TEST',
      'WARDEN_TEST',
    ] as $key) {
      putenv("$key=test");
    }
    $settings = [];
    $data = $a->getToken($settings);
    $this->assertEquals('7c3df2116154d33f51c6d77db9aa3dbc', $data);
  }

  /**
   * @covers ::getToken
   */
  public function testGetTokenHashSalt(): void {
    $a = new App();
    $settings = ['hash_salt' => 'qwertyuiop'];
    $data = $a->getToken($settings);
    $this->assertEquals('6eea9b7ef19179a06954edd0f6c05ceb', $data);
  }

  /**
   * @covers ::getToken
   */
  public function testGetTokenHostName(): void {
    $a = new App();
    $settings = [];
    $data = $a->getToken($settings);
    $this->assertEquals(md5(gethostname()), $data);
  }

  /**
   * @covers ::isCli
   */
  public function testIsCli(): void {
    $a = new App();
    $data = $a->isCli();
    $this->assertTrue($data);
  }

  /**
   * @covers ::isDebug
   */
  public function testIsDebugTrue(): void {
    $a = new App();
    $code = 'xxx';
    $_GET['debug'] = $code;
    $data = $a->isDebug($code);
    unset($_GET['debug']);
    $this->assertTrue($data);
  }

  /**
   * @covers ::isDebug
   */
  public function testIsDebugFalse1(): void {
    $a = new App();
    $code = 'xxx';
    unset($_GET['debug']);
    $data = $a->isDebug($code);
    $this->assertFalse($data);
  }

  /**
   * @covers ::isDebug
   */
  public function testIsDebugFalse2(): void {
    $a = new App();
    $code = 'xxx';
    $_GET['debug'] = 'yyy';
    $data = $a->isDebug($code);
    unset($_GET['debug']);
    $this->assertFalse($data);
  }

}
