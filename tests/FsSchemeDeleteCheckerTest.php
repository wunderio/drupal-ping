<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \FsSchemeDeleteChecker
 */
class FsSchemeDeleteCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  /**
   * @covers ::check2
   */
  public function testCheckDisabled(): void {
    $c = new FsSchemeDeleteChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['disabled', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckSuccess(): void {
    $file = tempnam('/tmp', 'ping_test-');
    $c = new FsSchemeDeleteChecker($file);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckError(): void {
    $file = sprintf('/tmp/ping_test-%d', time());
    $c = new FsSchemeDeleteChecker($file);
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', "file=$file - Could not delete newly created file in the files directory."], $status);
  }

}
