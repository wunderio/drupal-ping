<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Status
 */
class StatusTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  /**
   * @covers ::set
   */
  public function testSetPlain(): void {
    $s = new Status();
    $name = 'test';
    $status = 'success';
    $s->set($name, $status);
    $data = $s->get();
    $expected = [
      $name => [
        'severity' => $status,
        'payload' => [],
      ],
    ];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::set
   */
  public function testSetMsg(): void {
    $s = new Status();
    $name = 'test';
    $status = 'success';
    $msg = ['msg' => 'Test'];
    $s->set($name, $status, $msg);
    $data = $s->get();
    $expected = [
      $name => [
        'severity' => $status,
        'payload' => $msg,
      ],
    ];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::getBySeverity
   */
  public function testGetBySeverity(): void {

    $s = new Status();

    $name1 = 'test1';
    $status1 = 'success';
    $s->set($name1, $status1);

    $name2 = 'test2';
    $status2 = 'disabled';
    $s->set($name2, $status2);

    $data = $s->getBySeverity($status1);

    $expected = [
      $name1 => [],
    ];
    $this->assertEquals($expected, $data);
  }

  /**
   * @covers ::getTextTable
   */
  public function testGetTextTable(): void {

    $s = new Status();

    $name1 = 'test1';
    $status1 = 'success';
    $msg1 = ['msg' => 'Test'];
    $s->set($name1, $status1, $msg1);

    $name2 = 'test2';
    $status2 = 'disabled';
    $s->set($name2, $status2);

    $sep = PHP_EOL;
    $data = $s->getTextTable($sep);

    $expected = [
      "test1                success    {\"msg\":\"Test\"}",
      "test2                disabled   ",
    ];
    $expected = implode($sep, $expected);

    $this->assertEquals($expected, $data);
  }

}
