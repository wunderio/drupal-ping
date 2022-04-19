# Drupal ping helper

[![Build Status](https://travis-ci.org/wunderio/drupal-ping.svg?branch=master)](https://travis-ci.org/wunderio/drupal-ping) [![Latest Stable Version](https://poser.pugx.org/wunderio/drupal-ping/v/stable)](https://packagist.org/packages/wunderio/drupal-ping) [![Total Downloads](https://poser.pugx.org/wunderio/drupal-ping/downloads)](https://packagist.org/packages/wunderio/drupal-ping) [![Latest Unstable Version](https://poser.pugx.org/wunderio/drupal-ping/v/unstable)](https://packagist.org/packages/wunderio/drupal-ping) [![License](https://poser.pugx.org/wunderio/drupal-ping/license)](https://packagist.org/packages/wunderio/drupal-ping)

This script can be used for Drupal8 and Drupal9 health-checks.

## Installation

1. Add this to your `composer.json`:

```json
{
    "extra": {
        "dropin-paths": {
            "web/": ["package:wunderio/drupal-ping:_ping.php"]
        }
    }
}
```

2. Then install the composer package as usual with:

```
composer require wunderio/drupal-ping:^2
```

3. Add `_ping.php` into the main project's `.gitignore`.

## Changelog

### v2.1

- Refactor code into Classes
- Add comprehensive test coverage
- Fix coding standard issues

### v2.0

Breaking Changes!

- `composer.json`: `extra`: `dropin-paths` has a new syntax!
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
* `disabled` - No memcached servers defined in settings
* `success` - All connections succeed
* `warning` - At least one connection succeeds, at least one connection fails
* `error` - All connections fail

Basic networking is used, no `Memcached` or `Memcache` class.

### Redis

By using `Redis` class, connection is established to the server.

In `settings.php` following has to be defined:

* `$settings['redis.connection']['host']`
* `$settings['redis.connection']['port']`

This test works on both TCP and Unix Sockets.
For the latter only `host` has to be defined as path.

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
* `disabled` - No Elasticsearch servers defined in settings
* `success` - All connections succeed
* `warning` - At least one connection failed, and all failed connections have been configured with 'severity' = 'warning'
* `error` - At least one connection failed, and all failed connections have been configured with 'severity' = 'error'

### FS Scheme

Check if public file system is functional
by creating there a temporary file and removing it.

### Custom ping

If a site needs any custom checks, then just create `_ping.custom.php`.
Use of `$status->setName()` and `$status->set()` to define the result.
The PHP file does not need to contain functions, just plain PHP is enough.
Check it out how other checks are created in the `_ping.php`.

## Ping Development & Testing

- `lando composer install` - Install code quality tools
- `lando start` - Install basic Drupal and services
- `lando test` - Execute phpunit tests
- `lando scan` - Run coding standard checks

Note: the Lando setup is defined so that D7 and D89 branched can be easily switched
both running their own own setup. Drupal and composer installations wont clash.
They have separate dirs.

`_ping.php` can be accessed over the lando url.
For example `http://localhost:51418/_ping.php`.
It can also be accessed from the shell `cd /app/drupal9/web ; php _ping.php`.
From the shell output the debug code can be attained.

## Maintainers

- [Janne Koponen](https://github.com/tharna)
- [Ragnar Kurm](https://github.com/ragnarkurmwunder)

## License

MIT
