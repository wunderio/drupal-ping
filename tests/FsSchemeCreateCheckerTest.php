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
   * @covers ::getFile
   */
  public function testCheckSuccess(): void {
    $c = new FsSchemeCreateChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', []], $status);
    $file = $c->getFile();
    $this->assertFileExists($file);
  }

  /**
   * @covers ::check2
   */
  public function testCheckUnwritableDir(): void {
    $c = new FsSchemeCreateChecker();
    putenv('TESTING_FS_CREATE=1');
    $c->check();
    putenv('TESTING_FS_CREATE');
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', [
      'message' => 'Could not create temporary file in the files directory.',
      'path' => '/app/drupal/web/sites/default/files',
    ]], $status);
  }

  /**
   * @covers ::getFile
   */
  public function testCheckGetFileUninitialized(): void {
    $c = new FsSchemeCreateChecker();
    $file = $c->getFile();
    $this->assertEquals('', $file);
  }

}
