<?php

if (!class_exists('App')) {
  chdir('/app/drupal7/web');
  putenv('TESTING=1');
  require_once '_ping.php';
  global $_bootstrapChecker;
  $_bootstrapChecker = new BootstrapChecker();
  $_bootstrapChecker->check();
}
