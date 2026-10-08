# Wise - Dolibarr Module

Dolibarr module meant to fetch the Wise bank statements (CAMT.053) through the Wise API and reconcile them with the bank lines. Tracking of card spending will come later.

## Status

Skeleton only: the module installs and has a setup page, but does nothing yet.

## Requirements

- Dolibarr 23 or later
- PHP 7.4 or later
- Bank module enabled

## Installation

Copy the `wise` folder into `htdocs/custom/`, then enable the module in Setup > Modules > Financial.

## Tests

```bash
phpunit test/phpunit/
```

## License

GPLv3, see COPYING.
