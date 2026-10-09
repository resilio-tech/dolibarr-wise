<?php
/* Copyright (C) 2026 Resilio SA
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    wise/lib/wise.lib.php
 * \ingroup wise
 * \brief   Library files with common functions for Wise
 */

/**
 * Prepare admin pages header
 *
 * @return array<int, array<int, string>>
 */
function wiseAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("wise@wise");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/wise/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/wise/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'wise@wise');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'wise@wise', 'remove');

	return $head;
}

/**
 * Whether the scheduled job has to work at this hour, Swiss time.
 *
 * @param array<int, int|string> $hours Hours the job works at, empty for every hour
 * @param int|null               $now   Timestamp, current time when null
 * @return bool
 */
function wiseIsCheckHour(array $hours, $now = null)
{
	if (empty($hours)) {
		return true;
	}

	$date = new DateTime('@'.($now === null ? time() : (int) $now));
	$date->setTimezone(new DateTimeZone('Europe/Zurich'));

	return in_array((int) $date->format('G'), array_map('intval', $hours), true);
}

/**
 * Yesterday, Swiss time.
 *
 * @param int|null $now Timestamp, current time when null
 * @return string YYYY-MM-DD
 */
function wiseYesterday($now = null)
{
	$date = new DateTime('@'.($now === null ? time() : (int) $now));
	$date->setTimezone(new DateTimeZone('Europe/Zurich'));
	$date->modify('-1 day');

	return $date->format('Y-m-d');
}

/**
 * Currencies whose statements are fetched, from the setup.
 *
 * @param string|null $value Setup value, read from the setup when null
 * @return string[]
 */
function wiseCurrencies($value = null)
{
	if ($value === null) {
		$value = getDolGlobalString('WISE_CURRENCIES');
	}

	$currencies = array();
	foreach (preg_split('/[\s,;]+/', strtoupper($value)) as $currency) {
		if (preg_match('/^[A-Z]{3}$/', $currency) && !in_array($currency, $currencies, true)) {
			$currencies[] = $currency;
		}
	}

	return $currencies;
}

/**
 * Id of the standard balance in a currency.
 *
 * @param array<int, array<string, mixed>> $balances Balances as listed by Wise
 * @param string                           $currency Currency
 * @return int|null Null if the profile has no balance in that currency
 */
function wiseBalanceId(array $balances, $currency)
{
	foreach ($balances as $balance) {
		if (isset($balance['id'], $balance['currency']) && $balance['currency'] === $currency && (!isset($balance['type']) || $balance['type'] === 'STANDARD')) {
			return (int) $balance['id'];
		}
	}

	return null;
}

/**
 * Wise client built from the setup.
 *
 * @return WiseClient|null Null when no token is set
 */
function wiseCreateClient()
{
	require_once __DIR__.'/../class/WiseClient.class.php';

	$token = dolDecrypt(getDolGlobalString('WISE_API_TOKEN'));
	if ($token === '') {
		return null;
	}
	$baseUrl = getDolGlobalString('WISE_ENVIRONMENT') === 'live' ? WiseClient::URL_LIVE : WiseClient::URL_SANDBOX;

	return new WiseClient($baseUrl, $token, dolDecrypt(getDolGlobalString('WISE_PRIVATE_KEY')));
}
