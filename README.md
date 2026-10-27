# Wise - Dolibarr Module

Dolibarr module that fetches the daily Wise bank statements (CAMT.053) through the Wise API. Reconciliation with the bank lines and tracking of card spending will come later.

## Status

A scheduled job downloads every daily CAMT.053 statement still missing up to yesterday, for each configured balance, and keeps track of what it took. The files are stored under `documents/wise/<entity>/statements/<currency>/<year>/`.

On the first run it goes 31 days back. After a longer stop it goes back to the day after the last statement it took. A day is a Swiss day (Europe/Zurich).

## Requirements

- Dolibarr 23 or later
- PHP 7.4 or later
- Bank module enabled

## Installation

Copy the `wise` folder into `htdocs/custom/`, then enable the module in Setup > Modules > Financial.

## Setup

1. In Wise (sandbox first: https://wise-sandbox.com), go to Your Account > Connect and manage apps > API tokens and create a personal API token (read only is enough). It is shown only once.
2. Generate an RSA key pair:
   ```bash
   openssl genrsa -out wise-private.pem 2048
   openssl rsa -pubout -in wise-private.pem -out wise-public.pem
   ```
3. In the same API tokens page, use Manage public keys and upload `wise-public.pem`.
4. In Dolibarr, open Setup > Modules > Wise, choose the environment, paste the token and the content of `wise-private.pem`, then save. Both are stored encrypted and never shown again.
5. Press Test connection. It lists the profiles: put the business profile id in the setup, save, and press Test connection again. It then lists the balances and asks for yesterday's statement of each configured currency, showing whether Wise asked for a strong authentication and whether the signature was accepted.
6. When the test is green, set the download to Yes and enable the job "Fetch the Wise CAMT.053 statements" in Setup > Scheduled jobs. It runs every hour and works at the hours given as parameter, Swiss time (7 by default).

The statement endpoint is protected by strong customer authentication for profiles outside US, AU, NZ, SG, CA and MY, asked again every 90 days. The module answers it by signing the one-time token with the private key. The current Wise documentation says personal tokens cannot read balance statements outside those regions, which is what the test connection has to confirm on the real profile.

## Tests

```bash
phpunit test/phpunit/
```

## License

GPLv3, see COPYING.
