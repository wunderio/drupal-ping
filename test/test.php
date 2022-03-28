<?php

putenv("TESTING=1");
require('/app/_ping.php');

chdir('/app/test/drupal/web');

profiling_init(0);
status_init();
profiling_measure('bootstrap');

profiling_init(0);
status_init();
profiling_measure('check_db');
assert_array('db');

profiling_init(0);
status_init();
profiling_measure('check_memcache');
assert_array('memcache-1');

profiling_init(0);
status_init();
profiling_measure('check_redis');
assert_array('redis');

profiling_init(0);
status_init();
profiling_measure('check_elasticsearch');
assert_array('elasticsearch');

profiling_init(0);
status_init();
profiling_measure('check_fs_scheme');
assert_array('fs_scheme');

profiling_init(0);
status_init();
profiling_measure('check_custom_ping');
assert_array('custom-ping');

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
