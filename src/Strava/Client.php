<?php

namespace picasticks\Strava;

use \Strava\API\Exception as ClientException;
use \Strava\API\Service\Exception as ServiceException;

// Extend base Client to add "after" parameter to /club/[id]/activities API call
// "before" parameter is also valid but Strava returns an error when both "before" and "after" are set, they can't be used simultaneously with this API method

class Client extends \Strava\API\Client
{
    /**
     * List club activities
     *
     * @param int $id
     * @param int $page
     * @param int $per_page
     * @param int $after epoch timestamp in seconds
     * @return  array
     * @throws  Exception
     */
    public function getClubActivities($id, $page = null, $per_page = null, $after = null)
    {
        try {
            return $this->service->getClubActivities($id, $page, $per_page, $after);
        } catch (ServiceException $e) {
            throw new ClientException('[SERVICE] ' . $e->getMessage());
        }
    }
}
