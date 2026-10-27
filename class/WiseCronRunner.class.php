<?php
/* Copyright (C) 2026 Resilio SA
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       class/WiseCronRunner.class.php
 * \ingroup    wise
 * \brief      Scheduled job fetching the daily CAMT.053 statements from Wise.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/../lib/wise.lib.php';
require_once __DIR__.'/WiseClient.class.php';
require_once __DIR__.'/WiseStatement.class.php';

/**
 * Class WiseCronRunner
 */
class WiseCronRunner
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $output = '';

	/** @var string */
	public $error = '';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Fetch every missing daily statement up to yesterday for each configured currency.
	 *
	 * @param string ...$hours Hours the job works at, Swiss time, none to work now
	 * @return int 0 on success, -1 on error
	 */
	public function run(...$hours): int
	{
		global $conf;

		$this->output = '';
		$this->error = '';

		if (!wiseIsCheckHour($hours)) {
			$this->output = 'Not a check hour ('.implode(', ', $hours).')';
			return 0;
		}

		if (getDolGlobalString('WISE_FETCH_ENABLED') !== '1') {
			$this->output = 'Download disabled in the module setup';
			return 0;
		}

		$client = wiseCreateClient();
		$profileId = getDolGlobalInt('WISE_PROFILE_ID');
		$currencies = wiseCurrencies();
		if ($client === null || $profileId <= 0 || empty($currencies)) {
			$this->error = 'Wise is not configured: token, profile and currencies are required';
			return -1;
		}

		$balances = $client->listBalances($profileId);
		if ($balances === null) {
			$this->error = 'Cannot list the balances: '.$client->error;
			return -1;
		}

		$tracker = new WiseStatement($this->db);
		$yesterday = wiseYesterday();
		$lines = array();
		$errors = array();

		foreach ($currencies as $currency) {
			$balanceId = wiseBalanceId($balances, $currency);
			if ($balanceId === null) {
				$errors[] = $currency.': no balance in this currency on the profile';
				continue;
			}

			$lastDay = $tracker->lastDay($balanceId);
			$fetched = ($lastDay === false) ? null : $tracker->fetchedDays($balanceId, WiseStatement::firstDay($lastDay, $yesterday));
			if ($fetched === null) {
				$errors[] = $currency.': '.$tracker->error;
				continue;
			}

			$count = 0;
			foreach (WiseStatement::daysToFetch($fetched, $lastDay, $yesterday) as $day) {
				$content = $client->fetchStatement($profileId, $balanceId, $currency, $day);
				if ($content === null) {
					$errors[] = $currency.' '.$day.': '.$client->error;
					break;
				}

				$path = WiseStatement::relativePath((int) $conf->entity, $currency, $balanceId, $day);
				$fullPath = DOL_DATA_ROOT.'/'.$path;
				if (dol_mkdir(dirname($fullPath)) < 0 || file_put_contents($fullPath, $content, LOCK_EX) === false) {
					$errors[] = $currency.' '.$day.': cannot write '.$path;
					break;
				}
				dolChmod($fullPath);

				if (!$tracker->record($balanceId, $currency, $day, $path, hash('sha256', $content))) {
					$errors[] = $currency.' '.$day.': '.$tracker->error;
					break;
				}
				$count++;
			}

			$lines[] = $currency.': '.$count.' statement(s) fetched';
		}

		$this->output = implode("\n", array_merge($lines, $errors));
		if (!empty($errors)) {
			$this->error = implode('; ', $errors);
			return -1;
		}

		return 0;
	}
}
