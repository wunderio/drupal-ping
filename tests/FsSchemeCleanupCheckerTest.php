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

    $scheme = \Drupal::config('system.file')->get('default_scheme');
    $path = \Drupal::service('file_system')->realpath($scheme . '://');
    $file = tempnam($path, 'status_check_');
    $c = new FsSchemeCleanupChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $data = [
      'message' => 'Orphaned fs check files deleted.',
      'removed_count' => 1
    ];
    $data = json_encode($data);
    $this->assertEquals(['warning', $data], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckErrorList(): void {

    $scheme = \Drupal::config('system.file')->get('default_scheme');
    $path = \Drupal::service('file_system')->realpath($scheme . '://');
    $c = new FsSchemeCleanupChecker('/nonexistent');
    $c->check();
    $status = $c->getStatusInfo();
    $data = [
      'message' => 'Unable to list files.',
      'pattern' => '/nonexistent/status_check_*',
    ];
    $data = json_encode($data);
    $this->assertEquals(['error', $data], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckDir(): void {

    // Make sure it is clean.
    $c = new FsSchemeCleanupChecker();
    $c->check();

    $scheme = \Drupal::config('system.file')->get('default_scheme');
    $path = \Drupal::service('file_system')->realpath($scheme . '://');
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

    $scheme = \Drupal::config('system.file')->get('default_scheme');
    $path = \Drupal::service('file_system')->realpath($scheme . '://');
    $file = tempnam($path, 'status_check_');
    file_put_contents($file, 'test');
    $c = new FsSchemeCleanupChecker();
    $c->check();
    unlink($file);
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

}
