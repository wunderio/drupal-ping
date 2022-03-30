# Drupal ping helper

[![Build Status](https://travis-ci.org/wunderio/drupal-ping.svg?branch=7.x)](https://travis-ci.org/wunderio/drupal-ping) [![Latest Stable Version](https://poser.pugx.org/wunderio/drupal-ping/v/stable)](https://packagist.org/packages/wunderio/drupal-ping) [![Total Downloads](https://poser.pugx.org/wunderio/drupal-ping/downloads)](https://packagist.org/packages/wunderio/drupal-ping) [![Latest Unstable Version](https://poser.pugx.org/wunderio/drupal-ping/v/unstable)](https://packagist.org/packages/wunderio/drupal-ping) [![License](https://poser.pugx.org/wunderio/drupal-ping/license)](https://packagist.org/packages/wunderio/drupal-ping)

This script can be used for Drupal7 health-checks.

## Installation

Add this to your `composer.json`:

```json
{
    "extra": {
        "dropin-paths": {
            "web/": ["package:wunderio/drupal-ping:_ping.php"]
        }
    }
}
```

Then install the composer package as usual with:

```
composer require wunderio/drupal-ping:7.x-dev
```

## v2.0

Breaking Changes!

- `composer.json`: `extra`: `dropin-paths` has a syntax!
- `settings.php`: Elasticsearch has additional syntax for testing. See below.

## Usage

* One can visit `/_ping.php` to get a status
* By using `?debug=hash` query, one can get status check status table, and time profiling table. The `hash` is 4 first letters of the salt in `settings.php`.
* Find slow checks and checks errors in logs.

## Checks

### Main database

User #1 record is fetched from the database.

### Memcache

Assumes `$settings['memcache']['servers']` presence in the `settings.php`.

Following statuses are issued:
* `disable` - No memcached servers defined in settings
* `success` - All connections succeed
* `warning` - At least one connection succeeds, at least one connection fails
* `error` - All connections fail

Basic networking is used, no `Memcached` or `Memcache` class.

### Redis

By using `Redis` class, connection is established to the server.

In `settings.php` following has to be defined:

* `$conf['redis_client_host']`
* `$conf['redis_client_port']`

This test works on both TCP and Unix Sockets.
For the latter only `redis_client_host` has to be defined as path.

### Elasticsearch

In `settings.php` following has to be defined:

```
$settings['ping_elasticsearch_connections'] = [
  [
    'host' => 'hostname',
    'port' => 1234,
    'proto' => 'http', // http or https
    'severity' => 'warning', // warning or error
  ],
];
```

Elasticsearch check requires separate setting, because there are too many ways
how Elasticsearch config can be defined in the `settings.php` file, depending
on many factors.

The connection is establised by PHP `curl`, and then `/_cluster/health` is
being visited. The check expects to get `green` status in the response.

Following statuses are issued:
* `disable` - No Elasticsearch servers defined in settings
* `success` - All connections succeed
* `warning` - At least one connection succeeds, at least one connection fails
* `$['severity']` - All connections fail

### FS Scheme

Check if public file system is functional
by creating there a temporary file and removing it.

### Custom ping

If a site needs any custom checks, then just create `_ping.custom.php`.
Use of `status_set_name()` and `status_set()` to define the result.
The PHP file does not need to contain functions, just plain PHP is enough.
Check it out how other checks are created in the `_ping.php`.

## Testing

- `lando start` - Install basic Drupal and services
- `lando test` - Execute checks

## Maintainers

[Janne Koponen](https://github.com/tharna)
[Ragnar Kurm](https://github.com/ragnarkurmwunder)

## License

MIT
