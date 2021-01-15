<?php

/*
    StravaClubTracker https://github.com/picasticks/StravaClubTracker
    Copyright (C) 2021 Joel Hardi

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

declare(strict_types=1);

namespace picasticks\Strava;

use \Strava\API\Exception as ClientException;
use \Strava\API\Service\Exception as ServiceException;

// Extend base Client to add "after" parameter to /club/[id]/activities API call
// "before" parameter is also valid but Strava returns an error when both "before" and "after" are set, they can't be used simultaneously with this API method
class Client extends \Strava\API\Client {
	/**
	 * List club activities
	 *
	 * @param int $id
	 * @param int $page (optional)
	 * @param int $per_page (optional)
	 * @param int $after (optional) epoch timestamp to return activities after
	 *
	 * @throws ClientException
	 *
	 * @return array Club activities
	 */
	public function getClubActivities($id, $page = null, $per_page = null, $after = null) {
		try {
			return $this->service->getClubActivities($id, $page, $per_page, $after);
		} catch (ServiceException $e) {
			throw new ClientException('[SERVICE] ' . $e->getMessage());
		}
	}
}

# vim: tabstop=4:shiftwidth=4:noexpandtab
