<?php

declare(strict_types=1);

use \PHPUnit\Framework\TestCase;

// we need to import Checker class before we can extend it.
require_once 'init.php';

class DummyChecker extends Checker {

  protected $name;
  protected $testStatus;
  protected $testMessage;
  protected $testData;

  public function __construct(string $name = '', string $status = '', string $message = '', array $data = []) {
    $this->name = $name;
    $this->testStatus = $status;
    $this->testMessage = $message;
    $this->testData = $data;
  }

  protected function check2(): void {
    if ($this->testStatus == 'exception') {
      throw new \Exception('test-error');
    }
    $this->setStatus($this->testStatus, $this->testMessage, $this->testData);
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
   * @covers ::getStatusInfo
   * @covers ::setStatus
   * @covers ::check
   */
  public function testCheckStatusNoData(): void {
    $expected = ['status', ''];
    $c = new DummyChecker('', $expected[0]);
    $c->check();
    $data = $c->getStatusInfo();
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::getStatusInfo
   * @covers ::setStatus
   * @covers ::check
   */
  public function testCheckStatusYesData(): void {
    $c = new DummyChecker('', 'status', 'msg');
    $c->check();
    $data = $c->getStatusInfo();
    $expected = ['status', json_encode(['message' => 'msg'])];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::check
   */
  public function testCheckStatusException(): void {
    $c = new DummyChecker('', 'exception');
    $c->check();
    $data = $c->getStatusInfo();
    $expected = [
      'message' => 'Internal error.',
      'function' => 'DummyChecker::check2()',
      'exception' => 'test-error',
    ];
    $expected = json_encode($expected);
    $this->assertEquals(['error', $expected], $data);
  }

  /**
   * @covers ::getStatusInfo
   */
  public function testGetStatusInfoAll(): void {
    $c = new DummyChecker('', 'error', 'Message', ['key' => 'value']);
    $c->check();
    $data = $c->getStatusInfo();
    $expected = [
      'message' => 'Message',
      'key' => 'value',
    ];
    $expected = json_encode($expected);
    $this->assertEquals(['error', $expected], $data);
  }

}
