<?php

/**
 * @file
 * Test the _ping.php functionality.
 */

putenv("TESTING=1");
require '/app/_ping.php';

chdir('/app/test/drupal9/web');

$profile = new Profile();
$status = new Status();
$profile->measure('bootstrap', [$status]);

$checks = [
  'check_db' => 'db',
  'check_memcache' => 'memcache',
  'check_redis' => 'redis',
  'check_elasticsearch' => 'elasticsearch',
  'check_fs_scheme_create' => 'fs-scheme-create',
  'check_fs_scheme_delete' => 'fs-scheme-delete',
  'check_custom_ping' => 'custom-ping',
];
foreach ($checks as $func => $key) {
  $profile = new Profile();
  $status = new Status();
  $msg = $profile->measure($func, [$status]);
  if (!empty($msg)) {
    print "$id: Crashed.\n";
    var_dump($msg);
    exit(1);
  }
  assert_array($key, $status);
}

/**
 * Check expected test results.
 */
function assert_array($id, object $status) {
  $items = $status->getBySeverity('success');
  if (count($items) !== 1) {
    print "$id: Unexpected result count.\n";
    var_dump($status);
    exit(1);
  }
  printf("Success: %s\n", $id);
}
