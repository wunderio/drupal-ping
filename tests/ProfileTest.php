<?php

use \PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Profile
 */
class ProfileTest extends TestCase {

  public static function setUpBeforeClass(): void {
    if (class_exists('App')) {
      return;
    }
    chdir('/app/drupal9/web');
    putenv('TESTING=1');
    require '_ping.php';
    $b = new BootstrapChecker();
    $b->check();
  }

  /**
   * @covers ::measure
   */
  public function testMeasure(): void {
    $p = new Profile();
    $func = function () {};
    $name = 'test';
    $p->measure($func, $name);
    $data = $p->get();
    $this->assertIsInt($data[$name]);
    $this->assertGreaterThan(0, $data[$name]);

  }

  /**
   * @covers ::getByDuration
   */
  public function testGetByDuration(): void {
    $p = new Profile();

    $func1 = function () { usleep(0); };
    $name1 = 'test1';
    $p->measure($func1, $name1);

    $func2 = function () { usleep(100000); };
    $name2 = 'test2';
    $p->measure($func2, $name2);

    $data = $p->getByDuration();
    $this->assertEquals(2, count($data));
    $this->assertIsFloat($data[$name1]);
    $this->assertIsFloat($data[$name2]);
    $this->assertLessThan(100, $data[$name1]);
    $this->assertGreaterThan(100, $data[$name2]);

    $data = $p->getByDuration(100, NULL);
    $this->assertEquals(1, count($data));

    $data = $p->getByDuration(NULL, 100);
    $this->assertEquals(1, count($data));

    $data = $p->getByDuration(500, 1000);
    $this->assertEquals(0, count($data));
  }

  /**
   * @covers ::getTextTable
   */
  public function testGetTextTable(): void {
    $p = new Profile();

    $func1 = function () { usleep(0); };
    $name1 = 'test1';
    $p->measure($func1, $name1);

    $func2 = function () { usleep(100000); };
    $name2 = 'test2';
    $p->measure($func2, $name2);

    $p->stop();

    $sep = PHP_EOL;
    $text = $p->getTextTable($sep);
    $text = explode($sep, $text);
    $this->assertEquals(5, count($text));
    $text = array_filter($text, function ($l) { return strlen($l) > 0; });
    $this->assertEquals(4, count($text));
    $text = array_filter($text, function ($l) { return !preg_match('/^ *\d+\.\d+ ms - [a-z0-9]+$/', $l); });
    $this->assertEquals(0, count($text));
  }

}
