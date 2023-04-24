<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \FsSchemeCreateChecker
 */
class FsSchemeCreateCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  protected function setUp(): void {
    // Clean up check files
    $pattern = "/app/drupal/web/sites/default/files/status_check__*__*";
    $files = glob($pattern, GLOB_ERR | GLOB_NOESCAPE | GLOB_NOSORT);
    foreach ($files as $file) {
      unlink($file);
    }

    putenv('TESTING_FS_CREATE');
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

    $base = basename($file);
    $this->assertMatchesRegularExpression('/^status_check__\d+__[a-zA-Z0-9]+$/', $base);
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
