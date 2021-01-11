<?php

declare(strict_types=1);

use picasticks\Strava\Club;
use picasticks\Strava\ClubException;
//use Strava\API\Client;
//use Strava\API\Service\REST;
use picasticks\Strava\Client;
use picasticks\Strava\REST;
use Strava\API\OAuth;

require_once '../lib/vendor/autoload.php';

// Define list of Strava Club IDs to include
$clubs = array(
	123456,
	123456,
	123456,
	123456,
	123456,
	123456,
);

// Set start and end date
$startDate = '2020-12-01';
$endDate   = '2020-12-31';

// Set a TZ for date calculations
date_default_timezone_set('America/New_York');

// Replace with your Strava API credentials and the URI of this script
$oauth = new OAuth([
	'clientId'     => 12345,
	'clientSecret' => 'your-client-secret',
	'redirectUri'  => 'https://your.example.org/example_update.php'
]);

if (!isset($_GET['code'])) {
	echo '<p><a href="'.$oauth->getAuthorizationUrl([
		'scope' => [
			'read',
		]
	]).'">Connect to Strava API and and download updates</a><p>';
} else {
	$token = $oauth->getAccessToken('authorization_code', ['code' => $_GET['code']]);
	$adapter = new \GuzzleHttp\Client(['base_uri' => 'https://www.strava.com/api/v3/']);
	$service = new REST($token->getToken(), $adapter);

	$club = new Club(dirname(__DIR__).'/json');
	$club->setClient(new Client($service));

	// Override library's default Strava API request limit (default is 100)
	//$club->requestLimit = 42;

	// Set logger to null to skip logging
	//$club->logger = null;

	// Set start and end timestamps. Set end to earliest of most recent complete day (yesterday) or $endDate.
	$start = strtotime($startDate);
	$end = min(strtotime($endDate), (strtotime(date('Y-m-d')) - 86400));
	$club->log('Updating using date range '.date('Y-m-d', $start).' to '.date('Y-m-d', $end));

	// Download data from Strava. Only downloads when local files aren't already present.
	try {
		foreach ($clubs as $clubId) {
			// Get club details
			$club->downloadClub($clubId);

			// Get club activities for each day between $start and $end
			$club->downloadClubActivities($clubId, $start, $end);
		}
		$club->log(sprintf("Done! Made %s API requests to Strava", $club->getRequestCount()));
	} catch (ClubException $e) {
		$club->log('Configured API limit reached: '.$e->getMessage());
	}
}

# vim: tabstop=4:shiftwidth=4:noexpandtab
