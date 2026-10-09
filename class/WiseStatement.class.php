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
 * \file       class/WiseStatement.class.php
 * \ingroup    wise
 * \brief      Daily statements already fetched from Wise.
 */

/**
 * Class WiseStatement
 */
class WiseStatement
{
	const TABLE = 'wise_statement';

	const FIRST_RUN_DAYS = 31;

	/** @var DoliDB */
	private $db;

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
	 * Days to fetch for a balance: the last FIRST_RUN_DAYS days up to yesterday,
	 * reaching further back to the day after the last fetched one after a long stop.
	 *
	 * @param string[]    $fetched    Days already fetched, YYYY-MM-DD
	 * @param string|null $lastDay    Last day ever fetched, null if none
	 * @param string      $yesterday  Yesterday, YYYY-MM-DD
	 * @return string[] Days, oldest first
	 */
	public static function daysToFetch(array $fetched, $lastDay, string $yesterday): array
	{
		$start = self::firstDay($lastDay, $yesterday);

		$days = array();
		$day = new DateTime($start, new DateTimeZone('UTC'));
		$end = new DateTime($yesterday, new DateTimeZone('UTC'));
		while ($day <= $end) {
			$value = $day->format('Y-m-d');
			if (!in_array($value, $fetched, true)) {
				$days[] = $value;
			}
			$day->modify('+1 day');
		}

		return $days;
	}

	/**
	 * First day the catch up looks at.
	 *
	 * @param string|null $lastDay   Last day ever fetched, null if none
	 * @param string      $yesterday Yesterday, YYYY-MM-DD
	 * @return string YYYY-MM-DD
	 */
	public static function firstDay($lastDay, string $yesterday): string
	{
		$start = new DateTime($yesterday, new DateTimeZone('UTC'));
		$start->modify('-'.(self::FIRST_RUN_DAYS - 1).' days');

		if ($lastDay !== null) {
			$next = new DateTime($lastDay, new DateTimeZone('UTC'));
			$next->modify('+1 day');
			if ($next < $start) {
				$start = $next;
			}
		}

		return $start->format('Y-m-d');
	}

	/**
	 * Where a statement is stored, relative to the data root.
	 *
	 * @param int    $entity    Entity
	 * @param string $currency  Currency, three letters
	 * @param int    $balanceId Balance id
	 * @param string $day       Day, YYYY-MM-DD
	 * @return string|null Null if a value cannot be part of a path
	 */
	public static function relativePath(int $entity, string $currency, int $balanceId, string $day)
	{
		if (!preg_match('/^[A-Z]{3}$/', $currency) || !preg_match('/^(\d{4})-\d{2}-\d{2}$/', $day, $reg)) {
			return null;
		}

		return 'wise/'.$entity.'/statements/'.$currency.'/'.$reg[1].'/wise_'.$currency.'_'.$balanceId.'_'.$day.'.xml';
	}

	/**
	 * Days already fetched for a balance since a given day.
	 *
	 * @param int    $balanceId Balance id
	 * @param string $since     YYYY-MM-DD
	 * @return string[]|null Null on error
	 */
	public function fetchedDays(int $balanceId, string $since)
	{
		global $conf;

		$sql = "SELECT statement_date FROM ".MAIN_DB_PREFIX.self::TABLE;
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND balance_id = ".((int) $balanceId);
		$sql .= " AND statement_date >= '".$this->db->escape($since)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$days = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$days[] = substr($obj->statement_date, 0, 10);
		}

		return $days;
	}

	/**
	 * Last day fetched for a balance.
	 *
	 * @param int $balanceId Balance id
	 * @return string|null|false YYYY-MM-DD, null if none, false on error
	 */
	public function lastDay(int $balanceId)
	{
		global $conf;

		$sql = "SELECT MAX(statement_date) as last_day FROM ".MAIN_DB_PREFIX.self::TABLE;
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND balance_id = ".((int) $balanceId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$obj = $this->db->fetch_object($resql);

		return ($obj && $obj->last_day) ? substr($obj->last_day, 0, 10) : null;
	}

	/**
	 * Record a fetched statement.
	 *
	 * @param int    $balanceId Balance id
	 * @param string $currency  Currency
	 * @param string $day       YYYY-MM-DD
	 * @param string $path      Path relative to the data root
	 * @param string $hash      SHA-256 of the content
	 * @return bool
	 */
	public function record(int $balanceId, string $currency, string $day, string $path, string $hash): bool
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.self::TABLE." (entity, balance_id, currency, statement_date, file_path, file_hash, date_fetch)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $balanceId).", '".$this->db->escape($currency)."'";
		$sql .= ", '".$this->db->escape($day)."', '".$this->db->escape($path)."', '".$this->db->escape($hash)."'";
		$sql .= ", '".$this->db->idate(dol_now())."')";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return false;
		}

		return true;
	}
}
