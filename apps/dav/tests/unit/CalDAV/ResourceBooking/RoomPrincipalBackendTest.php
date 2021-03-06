<?php
/**
 * @copyright Copyright (c) 2018, Georg Ehrke
 *
 * @author Georg Ehrke <oc.list@georgehrke.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\DAV\Tests\unit\CalDAV\ResourceBooking;

use OCA\DAV\CalDAV\ResourceBooking\RoomPrincipalBackend;

Class RoomPrincipalBackendTest extends AbstractPrincipalBackendTest {
	public function setUp() {
		parent::setUp();

		$this->principalBackend = new RoomPrincipalBackend(self::$realDatabase,
			$this->userSession, $this->groupManager, $this->logger, $this->proxyMapper);

		$this->mainDbTable = 'calendar_rooms';
		$this->metadataDbTable = 'calendar_rooms_md';
		$this->foreignKey = 'room_id';

		$this->principalPrefix = 'principals/calendar-rooms';
		$this->expectedCUType = 'ROOM';

		$this->createTestDatasetInDb();
	}
}
