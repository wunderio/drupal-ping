<?php

/**
 * @file
 * This is a settings.php for testing _ping.php functionality.
 */

// DB.
$db = json_decode(getenv('LANDO_INFO'))->mariadb;
$databases['default']['default'] = [
  'database' => $db->creds->database,
  'username' => $db->creds->user,
  'password' => $db->creds->password,
  'host' => $db->internal_connection->host,
  'port' => $db->internal_connection->port,
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

// REDIS.
$redis = json_decode(getenv('LANDO_INFO'))->redis;
$settings['redis.connection']['host'] = $redis->internal_connection->host;
$settings['redis.connection']['port'] = $redis->internal_connection->port;

// MEMCACHED.
$memcached = json_decode(getenv('LANDO_INFO'))->memcached;
$hostport = sprintf('%s:%s', $memcached->internal_connection->host, $memcached->internal_connection->port);
$settings['memcache']['servers'] = [
  $hostport => 'default',
];

// ELASTICSEARCH.
$settings['ping_elasticsearch_connections'] = [
  [
    // Host and port are not exposed by the custom image, therefore hardcode.
    'host' => 'elasticsearch',
    'port' => 9200,
    // Proto: http or https.
    'proto' => 'http',
    // Severity: warning or error.
    'severity' => 'warning',
  ],
];

// For debugging add "?debug=test" to the query - 4 letters of the hash.
$settings['hash_salt'] = 'testing';

// Ignore settings added by Drupal install below this line.
