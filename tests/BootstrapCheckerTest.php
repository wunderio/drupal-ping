<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \BootstrapChecker
 */
class BootstrapCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  /**
   * @covers ::check2
   */
  public function testCheckSuccess(): void {
    global $_bootstrapChecker;
    $status = $_bootstrapChecker->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::getSettings
   */
  public function testSettings(): void {
    global $_bootstrapChecker;
    $settings = $_bootstrapChecker->getSettings();
    $this->assertNotEmpty($settings);
    $this->assertIsArray($settings);
  }

  /**
   * @covers ::check2
   */
  public function testDrupalRoot(): void {
    $this->assertNotEmpty(DRUPAL_ROOT);
  }

}
