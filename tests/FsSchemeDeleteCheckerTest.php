<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \FsSchemeDeleteChecker
 */
class FsSchemeDeleteCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    if (class_exists('App')) {
      return;
    }
    chdir('/app/drupal9/web');
    putenv('TESTING=1');
    require '_ping.php';
    global $_bootstrapChecker;
    $_bootstrapChecker = new BootstrapChecker();
    $_bootstrapChecker->check();
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
