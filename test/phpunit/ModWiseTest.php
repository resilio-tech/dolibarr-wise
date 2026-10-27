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
 * \file       test/phpunit/ModWiseTest.php
 * \ingroup    wise
 * \brief      PHPUnit tests for the module descriptor
 */

use PHPUnit\Framework\TestCase;

/**
 * Class ModWiseTest
 */
class ModWiseTest extends TestCase
{
	/**
	 * Source of the module descriptor.
	 *
	 * @return string
	 */
	private function descriptor(): string
	{
		return (string) file_get_contents(__DIR__.'/../../core/modules/modWise.class.php');
	}

	/**
	 * The descriptor declares the module number reserved for it.
	 *
	 * @return void
	 */
	public function testModuleNumber(): void
	{
		$this->assertMatchesRegularExpression('/\$this->numero = 580000;/', $this->descriptor());
	}

	/**
	 * The descriptor version is a plain version string, as the build and bump workflows expect.
	 *
	 * @return void
	 */
	public function testVersion(): void
	{
		$this->assertMatchesRegularExpression("/\\\$this->version = '\\d+\\.\\d+\\.\\d+';/", $this->descriptor());
	}

	/**
	 * Every language file defines the strings the module uses.
	 *
	 * @return void
	 */
	public function testLanguageFilesDefineUsedKeys(): void
	{
		$keys = array('ModuleWiseName', 'ModuleWiseDesc', 'WiseDescription', 'WiseAbout');
		foreach (glob(__DIR__.'/../../admin/*.php') as $page) {
			preg_match_all('/["\'](Wise[A-Z][A-Za-z]*)["\']/', (string) file_get_contents($page), $matches);
			$keys = array_merge($keys, $matches[1]);
		}
		$keys = array_unique($keys);

		$files = glob(__DIR__.'/../../langs/*/wise.lang');
		$this->assertCount(2, $files);

		foreach ($files as $file) {
			$content = (string) file_get_contents($file);
			foreach ($keys as $key) {
				$this->assertMatchesRegularExpression('/^'.$key.'\s*=/m', $content, basename(dirname($file)).' misses '.$key);
			}
		}
	}
}
