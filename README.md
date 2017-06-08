# Drupal ping helper

This script can be used for Drupal8 healthchecks.

## Installation
You can install this by adding this into your composer.json:
```
{
    "require": {
        "wunderio/drupal-ping": "^1.0"
    },
    "extra": {
        "dropin-paths": {
            "web/": ["type:web-dropin"],
        }
    }
}
```

## Maintainers
[Janne Koponen](https://github.com/tharna)

## License
MIT