<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \FsSchemeCreateChecker
 */
class FsSchemeCreateCheckerTest extends TestCase {

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
  public function testCheckSuccess(): void {
    $c = new FsSchemeCreateChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
    $file = $c->getFile();
    $this->assertFileExists($file);
  }

}
