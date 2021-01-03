<?php

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
