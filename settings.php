<?php

/**
 * @file
 * This is a settings.php for testing _ping.php functionality.
 */

if (empty($conf)) {
  $conf = [];
}

if (empty($databases)) {
  $databases = [];
}

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
];

// REDIS.
$redis = json_decode(getenv('LANDO_INFO'))->redis;
$conf['redis_client_host'] = $redis->internal_connection->host;
$conf['redis_client_port'] = $redis->internal_connection->port;

// MEMCACHED.
$memcached = json_decode(getenv('LANDO_INFO'))->memcached;
$hostport = sprintf('%s:%s', $memcached->internal_connection->host, $memcached->internal_connection->port);
$conf['memcache_servers'] = [
  $hostport => 'default',
];

// ELASTICSEARCH.
$conf['ping_elasticsearch_connections'] = [
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
// @codingStandardsIgnoreLine DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
$drupal_hash_salt = 'testing';

// @codingStandardsIgnoreLine DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
// Ignore settings added by Drupal install below this line.
