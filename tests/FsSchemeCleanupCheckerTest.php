<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \FsSchemeCleanupChecker
 */
class FsSchemeCleanupCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  /**
   * @covers ::check2
   */
  public function testCheckSuccess(): void {

    // Make sure it is clean.
    $c = new FsSchemeCleanupChecker();
    $c->check();

    // Now check on the clean dir.
    $c = new FsSchemeCleanupChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckWarning(): void {

    // Make sure it is clean.
    $c = new FsSchemeCleanupChecker();
    $c->check();

    $path = variable_get('file_directory_path', conf_path() . '/files');
    $file = tempnam($path, 'status_check_');
    $c = new FsSchemeCleanupChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['warning', 'removed=1 Orphaned fs check files deleted.'], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckErrorList(): void {

    $path = variable_get('file_directory_path', conf_path() . '/files');
    $c = new FsSchemeCleanupChecker('/nonexistent');
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', 'pattern=/nonexistent/status_check_* Unable to list files.'], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckDir(): void {

    // Make sure it is clean.
    $c = new FsSchemeCleanupChecker();
    $c->check();

    $path = variable_get('file_directory_path', conf_path() . '/files');
    $c = new FsSchemeCleanupChecker();
    $d = "$path/test";
    mkdir($d);
    $c->check();
    rmdir($d);
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckNonEmpty(): void {

    // Make sure it is clean.
    $c = new FsSchemeCleanupChecker();
    $c->check();

    $path = variable_get('file_directory_path', conf_path() . '/files');
    $file = tempnam($path, 'status_check_');
    file_put_contents($file, 'test');
    $c = new FsSchemeCleanupChecker();
    $c->check();
    unlink($file);
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

}
