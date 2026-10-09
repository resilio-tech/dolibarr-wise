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
 * \file       test/phpunit/WiseStatementTest.php
 * \ingroup    wise
 * \brief      PHPUnit tests for the days to fetch, the storage path and the setup helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../class/WiseStatement.class.php';
require_once __DIR__.'/../../lib/wise.lib.php';

/**
 * Class WiseStatementTest
 */
class WiseStatementTest extends TestCase
{
	/**
	 * The first run takes the last 31 days up to yesterday.
	 *
	 * @return void
	 */
	public function testFirstRunTakesTheLast31Days(): void
	{
		$days = WiseStatement::daysToFetch(array(), null, '2026-10-08');

		$this->assertCount(31, $days);
		$this->assertSame('2026-09-08', $days[0]);
		$this->assertSame('2026-10-08', $days[30]);
	}

	/**
	 * A day already fetched is not asked again and a missed one is caught up.
	 *
	 * @return void
	 */
	public function testOnlyMissingDaysAreFetched(): void
	{
		$fetched = array();
		for ($day = 8; $day <= 30; $day++) {
			$fetched[] = sprintf('2026-09-%02d', $day);
		}
		$fetched = array_merge($fetched, array('2026-10-01', '2026-10-02', '2026-10-04', '2026-10-05'));

		$this->assertSame(array('2026-10-03', '2026-10-06', '2026-10-07', '2026-10-08'), WiseStatement::daysToFetch($fetched, '2026-10-05', '2026-10-08'));
	}

	/**
	 * Nothing to do when yesterday is already there.
	 *
	 * @return void
	 */
	public function testNothingToDoWhenUpToDate(): void
	{
		$fetched = array();
		for ($i = 0; $i < 31; $i++) {
			$fetched[] = date('Y-m-d', strtotime('2026-10-08 -'.$i.' days'));
		}

		$this->assertSame(array(), WiseStatement::daysToFetch($fetched, '2026-10-08', '2026-10-08'));
	}

	/**
	 * After a long stop the job goes back to the day after the last one fetched.
	 *
	 * @return void
	 */
	public function testALongStopIsCaughtUpFromTheLastDay(): void
	{
		$this->assertSame('2026-07-01', WiseStatement::firstDay('2026-06-30', '2026-10-08'));
		$days = WiseStatement::daysToFetch(array(), '2026-06-30', '2026-10-08');
		$this->assertSame('2026-07-01', $days[0]);
		$this->assertSame('2026-10-08', end($days));
		$this->assertCount(100, $days);
	}

	/**
	 * Statements are stored per entity, currency and year.
	 *
	 * @return void
	 */
	public function testRelativePath(): void
	{
		$this->assertSame('wise/1/statements/CHF/2026/wise_CHF_200001_2026-10-08.xml', WiseStatement::relativePath(1, 'CHF', 200001, '2026-10-08'));
	}

	/**
	 * Values that could leave the directory are refused.
	 *
	 * @return void
	 */
	public function testRelativePathRefusesUnsafeValues(): void
	{
		$this->assertNull(WiseStatement::relativePath(1, '../', 200001, '2026-10-08'));
		$this->assertNull(WiseStatement::relativePath(1, 'chf', 200001, '2026-10-08'));
		$this->assertNull(WiseStatement::relativePath(1, 'CHF', 200001, '2026-10-08/../x'));
	}

	/**
	 * The currencies typed in the setup are cleaned up.
	 *
	 * @return void
	 */
	public function testCurrencies(): void
	{
		$this->assertSame(array('CHF', 'EUR'), wiseCurrencies(' chf, EUR;chf  x12 '));
		$this->assertSame(array(), wiseCurrencies(''));
	}

	/**
	 * The standard balance of a currency is found in the list Wise returns.
	 *
	 * @return void
	 */
	public function testBalanceId(): void
	{
		$balances = json_decode((string) file_get_contents(__DIR__.'/fixtures/balances.json'), true);
		$balances[] = array('id' => 300001, 'currency' => 'USD', 'type' => 'SAVINGS');

		$this->assertSame(200002, wiseBalanceId($balances, 'EUR'));
		$this->assertNull(wiseBalanceId($balances, 'USD'));
	}

	/**
	 * Yesterday and the check hour follow the Swiss clock.
	 *
	 * @return void
	 */
	public function testSwissClock(): void
	{
		$now = strtotime('2026-10-08T22:30:00Z');

		$this->assertSame('2026-10-08', wiseYesterday($now));
		$this->assertTrue(wiseIsCheckHour(array('0'), $now));
		$this->assertFalse(wiseIsCheckHour(array('22'), $now));
		$this->assertTrue(wiseIsCheckHour(array(), $now));
	}
}
