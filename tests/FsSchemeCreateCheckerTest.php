<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \FsSchemeCreateChecker
 */
class FsSchemeCreateCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  /**
   * @covers ::check2
   */
  public function testCheckSuccess(): void {
    $c = new FsSchemeCreateChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', []], $status);
    $file = $c->getFile();
    $this->assertFileExists($file);
  }

}
