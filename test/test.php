<?php

putenv("TESTING=1");
require('/app/_ping.php');

chdir('/app/test/drupal/web');

profiling_init(0);
status_init();
profiling_measure('bootstrap');

$checks = [
  'check_db' => 'db',
  'check_memcache' => 'memcache',
  'check_redis' => 'redis',
  'check_elasticsearch' => 'elasticsearch',
  'check_fs_scheme' => 'fs-scheme',
  'check_custom_ping' => 'custom-ping',
];
foreach ($checks as $func => $key) {
  profiling_init(0);
  status_init();
  profiling_measure($func);
  assert_array($key);
}

function assert_array($id) {
  global $status;
  if (count($status) !== 1) {
    print "$id: Unexpected result count.\n";
    var_dump($status);
    exit(1);
  }
  if ($status[$id]['severity'] !== 'success') {
    print "$id: Unexpected result status.\n";
    var_dump($status);
    exit(1);
  }
  printf("Success: %s\n", $id);
}
