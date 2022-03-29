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
  'collation' => 'utf8mb4_general_ci',
];

// REDIS
$redis = json_decode(getenv('LANDO_INFO'))->redis;
$settings['redis.connection']['host'] = $redis->internal_connection->host;
$settings['redis.connection']['port'] = $redis->internal_connection->port;

// MEMCACHED
$memcached = json_decode(getenv('LANDO_INFO'))->memcached;
$hostport = sprintf('%s:%s', $memcached->internal_connection->host, $memcached->internal_connection->port);
$settings['memcache']['servers'][$hostport] = 'default';

// ELASTICSEARCH
$elasticsearch = json_decode(getenv('LANDO_INFO'))->elasticsearch;
$settings['ping_elasticsearch_connections'] = [
  [
    'host' => $elasticsearch->internal_connection->host,
    'port' => $elasticsearch->internal_connection->port,
    'proto' => 'http',
    'severity' => 'warning', // warning or error
  ],
];

# Ignore settings added by Drupal install below this line.
