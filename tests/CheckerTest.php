<?php

use \PHPUnit\Framework\TestCase;

// we need to import Checker class before we can extend it.
if (!class_exists('App')) {
  chdir('/app/drupal9/web');
  putenv('TESTING=1');
  require '_ping.php';
  global $_bootstrapChecker;
  $_bootstrapChecker = new BootstrapChecker();
  $_bootstrapChecker->check();
}

class DummyChecker extends Checker {

  protected $name;
  protected $testStatus;

  public function __construct($name = '', $status = '', $warnings = [], $errors = []) {
    $this->name = $name;
    $this->testStatus = $status;
    $this->warnings = array_merge($this->warnings, $warnings);
    $this->errors = array_merge($this->errors, $errors);
  }

  protected function check2(): string {
    if ($this->testStatus == 'exception') {
      throw new \Exception('test-error');
    }
    return $this->testStatus;
  }

}

/**
 * @coversDefaultClass \Checker
 */
class CheckerTest extends TestCase {

  /**
   * @covers ::getName
   */
  public function testGetName(): void {
    $expected = 'dummy';
    $c = new DummyChecker($expected);
    $data = $c->getName();
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::check
   * @covers ::getStatusInfo
   */
  public function testCheckStatusSuccess(): void {
    $expected = ['xxx', ''];
    $c = new DummyChecker('', $expected[0]);
    $c->check();
    $data = $c->getStatusInfo();
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::check
   */
  public function testCheckStatusException(): void {
    $c = new DummyChecker('', 'exception');
    $c->check();
    $data = $c->getStatusInfo();
    $expected = ['error', 'DummyChecker::check2(): test-error'];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::getStatusInfo
   */
  public function testGetStatusInfoWarning(): void {
    $w = 'test-warning';
    $c = new DummyChecker('', '', [$w]);
    $c->check();
    $data = $c->getStatusInfo();
    $expected = ['warning', $w];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::getStatusInfo
   */
  public function testGetStatusInfoWarnings(): void {
    $w = ['test-warning1', 'test-warning2'];
    $c = new DummyChecker('', '', $w);
    $c->check();
    $data = $c->getStatusInfo();
    $expected = ['warning', implode('; ', $w)];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::getStatusInfo
   */
  public function testGetStatusInfoError(): void {
    $e = 'test-error';
    $c = new DummyChecker('', '', [], [$e]);
    $c->check();
    $data = $c->getStatusInfo();
    $expected = ['error', $e];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::getStatusInfo
   */
  public function testGetStatusInfoErrors(): void {
    $e = ['test-error1', 'test-error2'];
    $c = new DummyChecker('', '', [], $e);
    $c->check();
    $data = $c->getStatusInfo();
    $expected = ['error', implode('; ', $e)];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::getStatusInfo
   */
  public function testGetStatusInfoWarningError(): void {
    $w = 'test-warning';
    $e = 'test-error';
    $c = new DummyChecker('', '', [$w], [$e]);
    $c->check();
    $data = $c->getStatusInfo();
    $expected = ['error', implode('; ', [$w, $e])];
    $this->assertEquals($expected, $data);
  }

}
