<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \App
 */
class AppTest extends TestCase {

  public static function setUpBeforeClass(): void {
    if (class_exists('App')) {
      return;
    }
    chdir('/app/drupal9/web');
    putenv('TESTING=1');
    require '_ping.php';
    $b = new BootstrapChecker();
    $b->check();
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
      'check1' => 'msg1',
      'check2' => 'msg2',
    ], 'test');
    $expected = [
      'ping: test: check1: msg1',
      'ping: test: check2: msg2',
    ];
    $this->assertEquals($expected, $_logs);
  }

  /**
   * @covers ::getSlow
   */
  public function testGetSlow(): void {
    $a = new App();
    $p = new Profile();

    $func1 = function () {};
    $name1 = 'test1';
    $p->measure($func1, $name1);

    $func2 = function () { sleep(1); };
    $name2 = 'test2';
    $p->measure($func2, $name2);

    $data = $a->getSlow($p);
    $this->assertIsArray($data);
    $this->assertCount(1, $data);
    $this->assertMatchesRegularExpression('/^duration=\d+\.\d+ ms$/', $data['test2']);
  }

  /**
   * @covers ::getDebugCode
   */
  public function testGetDebugCodePingDebug(): void {
    $a = new App();
    $code = '1234';
    $settings = ['ping_debug' => $code];
    $data = $a->getDebugCode($settings);
    $this->assertEquals($code, $data);
  }

  /**
   * @covers ::getDebugCode
   */
  public function testGetDebugCodeSilta(): void {
    $a = new App();
    putenv('SILTA_CLUSTER=1');
    putenv('PROJECT_NAME=a');
    putenv('ENVIRONMENT_NAME=b');
    $settings = [];
    $data = $a->getDebugCode($settings);
    putenv('SILTA_CLUSTER');
    putenv('PROJECT_NAME');
    putenv('ENVIRONMENT_NAME');
    $this->assertEquals('8ca2ed590cf2ea2404f2e67641bcdf50', $data);
  }

  /**
   * @covers ::getDebugCode
   */
  public function testGetDebugCodeVirtualServer(): void {
    $a = new App();
    putenv('DB_HOST_DRUPAL=host');
    putenv('DB_NAME_DRUPAL=name');
    putenv('DB_PASS_DRUPAL=pass');
    putenv('DB_PORT_DRUPAL=port');
    putenv('DB_USER_DRUPAL=user');
    $settings = [];
    $data = $a->getDebugCode($settings);
    putenv('DB_HOST_DRUPAL');
    putenv('DB_NAME_DRUPAL');
    putenv('DB_PASS_DRUPAL');
    putenv('DB_PORT_DRUPAL');
    putenv('DB_USER_DRUPAL');
    $this->assertEquals('9ea396789d54a514eb63e12126c5ae4a', $data);
  }

  /**
   * @covers ::getDebugCode
   */
  public function testGetDebugCodeHashSalt(): void {
    $a = new App();
    $settings = ['hash_salt' => 'qwertyuiop'];
    $data = $a->getDebugCode($settings);
    $this->assertEquals('6eea9b7ef19179a06954edd0f6c05ceb', $data);
  }

  /**
   * @covers ::getDebugCode
   */
  public function testGetDebugCodeHostName(): void {
    $a = new App();
    $settings = [];
    $data = $a->getDebugCode($settings);
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
