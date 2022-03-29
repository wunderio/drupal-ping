<?php

# DB
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

// REDIS
$redis = json_decode(getenv('LANDO_INFO'))->redis;
$conf['redis_client_host'] = $redis->internal_connection->host;
$conf['redis_client_port'] = $redis->internal_connection->port;

// MEMCACHED
$memcached = json_decode(getenv('LANDO_INFO'))->memcached;
$hostport = sprintf('%s:%s', $memcached->internal_connection->host, $memcached->internal_connection->port);
$conf['memcache_servers'] = [
  $hostport => 'default',
];

// ELASTICSEARCH
$elasticsearch = json_decode(getenv('LANDO_INFO'))->elasticsearch;
$conf['ping_elasticsearch_connections'] = [
  [
    'host' => $elasticsearch->internal_connection->host,
    'port' => $elasticsearch->internal_connection->port,
    'proto' => 'http', // http or https
    'severity' => 'warning', // warning or error
  ],
];

# For debugging add "?debug=test" to the query - 4 letters of the hash.
$drupal_hash_salt = 'testing';

# Ignore settings added by Drupal install below this line.
