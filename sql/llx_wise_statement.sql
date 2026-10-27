-- Copyright (C) 2026 Resilio SA
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.

CREATE TABLE llx_wise_statement(
	rowid					integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity					integer DEFAULT 1 NOT NULL,
	balance_id				bigint NOT NULL,
	currency				varchar(3) NOT NULL,
	statement_date			date NOT NULL,
	file_path				varchar(255) NOT NULL,
	file_hash				varchar(64) NOT NULL,
	date_fetch				datetime NOT NULL,
	tms						timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
