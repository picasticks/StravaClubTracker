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

// Extend base Client to add "after" parameter to /club/[id]/activities API call
// "before" parameter is also valid but Strava returns an error when both "before" and "after" are set, they can't be used simultaneously with this API method
class REST extends \Strava\API\Service\REST {
	public function getClubActivities($id, $page = null, $per_page = null, $after = null)
	{
		$path = 'clubs/' . $id . '/activities';
		$parameters['query'] = [
			'after' => $after,
			'page' => $page,
			'per_page' => $per_page,
			'access_token' => $this->getToken(),
		];
		return $this->getResponse('GET', $path, $parameters);
	}
}

# vim: tabstop=4:shiftwidth=4:noexpandtab
