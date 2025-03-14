# Drupal ping helper

[![Build Status](https://travis-ci.org/wunderio/drupal-ping.svg?branch=master)](https://travis-ci.org/wunderio/drupal-ping) [![Latest Stable Version](https://poser.pugx.org/wunderio/drupal-ping/v/stable)](https://packagist.org/packages/wunderio/drupal-ping) [![Total Downloads](https://poser.pugx.org/wunderio/drupal-ping/downloads)](https://packagist.org/packages/wunderio/drupal-ping) [![Latest Unstable Version](https://poser.pugx.org/wunderio/drupal-ping/v/unstable)](https://packagist.org/packages/wunderio/drupal-ping) [![License](https://poser.pugx.org/wunderio/drupal-ping/license)](https://packagist.org/packages/wunderio/drupal-ping)

This script can be used for Drupal8 through Drupal11 uptime monitoring.
It is not suitable for subsystem health-checks for things like container or load balancer.

## Installation

1. Add this to your `composer.json`:

```json
{
    "extra": {
        "dropin-paths": {
            "web/": [
                "type:web-dropin",
                "package:wunderio/drupal-ping:_ping.php"
            ]
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

See [Releases](https://github.com/wunderio/drupal-ping/releases).

## Usage

* Visit `/_ping.php` to get a system status
* By using `?debug=token` additional status check table and time profiling information is displayed
  * See [Debug Mode](#debug-mode) how to obtain the `token` value
* Find slow checks and checks errors in logs

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
* `error` - At least one connection failed, and at least one of the failed connections have been configured with 'severity' = 'error'

### FS Scheme

Consists of 3 tests:

- Check if a file can be created within the public filesystem.
- Check if the test-file can be deleted from the public filesystem.
- Check if there are any leftover test-files, and remove them.

### Custom ping

If a site needs any custom checks, then just create `_ping.custom.php`.
Use of `$status->setName()` and `$status->set()` to define the result.
The PHP file does not need to contain functions, just plain PHP is enough.
Check it out how other checks are created in the `_ping.php`.

## Debug Mode

`_ping.php` can be accessed over the web.
For example `https://example.com/_ping.php`.
It can also be accessed from the shell `cd /path/web ; php _ping.php`.
From the shell output the debug token can be attained.
Then visit the ping again with `https://example.com/_ping.php?debug=token`.

The token is generated in one of the following ways.
These methods are listed by precedance.
If earlier fails (is empty), then next one is tried.
* Drupal settings `$settings['ping_token']`
* Environment variable `PING_TOKEN`
* Md5 of the combination of environment variable values where the variable names matches regex `/^(DB|ENVIRONMENT_NAME|GIT|PHP|PROJECT_NAME|S+MTP|VARNISH|WARDEN)/` - NB! This method assumes environment variable consistency between webserver and shell.
* Md5 of Drupal settings `$settings['hash_salt']`
* Md5 of the hostname

## Ping Development & Testing

### Setting up development environment

1. Clone development and testing environment
  * `git clone git@github.com:wunderio/drupal-project.git ~/projects/drupal-ping`
  * Yes, save it as `drupal-ping`.
1. `cd drupal-ping/`
1. Clone the ping project itself
  * `git clone git@github.com:wunderio/drupal-ping.git`
  * Yes, save it as `drupal-ping` too, inside the folder of the same name. It is the actual repo we are going to work with.
  * Checkout or create your development branch.
1. Link `.lando.yml`
  * `rm -f .lando.yml` - at the top-level folder, remove the Lando conf file.
  * `ln drupal-ping/.lando.yml` - link the Lando conf from the ping repo folder. Don't create this as a soft (`-s`) link because Lando would mount the project where the original file is. Therefore create the hard link which is indistinguishable for Lando.
1. Link `.lando/`
  * `rm -rf .lando` - at the top-level folder, remove the Lando folder.
  * `ln -s drupal-ping/.lando` - link the Lando scripts folder from the ping repo folder.
1. `lando start`
1. Note that the Drupal install will mess up `settings.php` a bit, don't commit.
1. https://ping.lndo.site/_ping.php
1. `lando scan`
1. `lando test`

### Development commands

- `lando test` - Execute phpunit tests
- `lando scan` - Run coding standard checks

## Maintainers

- [Janne Koponen](https://github.com/tharna)
- [Ragnar Kurm](https://github.com/ragnarkurmwunder)
- [Juhani Moilanen](https://github.com/Juhani-moilanen)

## License

MIT
