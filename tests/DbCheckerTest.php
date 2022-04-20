<?php

use Drupal\Core\Database\Database;
use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \DbChecker
 */
class DbCheckerTest extends TestCase {

  public static function setUpBeforeClass(): void {
    require_once 'init.php';
  }

  /**
   * Make sure after the tests everything is intact.
   */
  public static function tearDownAfterClass(): void {
    db_query('update {users} set uid = 1 WHERE uid = 100');
  }

  /**
   * @covers ::check2
   */
  public function testCheckSuccess(): void {
    db_query('update {users} set uid = 1 WHERE uid = 100');
    $c = new DbChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['success', ''], $status);
  }

  /**
   * @covers ::check2
   */
  public function testCheckError(): void {
    db_query('update {users} set uid = 100 WHERE uid = 1');
    $c = new DbChecker();
    $c->check();
    $status = $c->getStatusInfo();
    $this->assertEquals(['error', 'result_count=0 expected=1 Master database invalid results.'], $status);
  }

}
