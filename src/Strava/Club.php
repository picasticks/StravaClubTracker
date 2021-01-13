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

class ClubException extends \Exception { }

class Club {
	/**
	 * @var Strava API request limit
	 * @type int
	 */
	public int $requestLimit = 100;

	/**
	 * @var logger for status messages
	 * @type callable
	 */
	public ?string $logger = 'trigger_error';

	/**
	 * @var directory where Strava API response data is stored
	 * @type string
	 */
	protected string $responseStorage = 'response';

	protected Client $client;
	protected int $start;
	protected int $end;
	protected int $requestCount = 0;
	protected array $activityCache = array();

	/**
	 * Constructor
	 *
	 * @param string $storageDir filesystem directory to store downloaded JSON files
	 */
	public function __construct(string $storageDir) {
		$this->responseStorage = $storageDir.'/'.$this->responseStorage;
	}

	/**
	 * Set Strava API Client instance
	 *
	 * @param Client $client instance
	 *
	 * @return void
	 */
	public function setClient(Client $client): void {
		$this->client = $client;
	}

	/**
	 * Get count of current number of API requests to Strava
	 *
	 * @return int request count
	 */
	public function getRequestCount(): int {
		return $this->requestCount;
	}

	/**
	 * Get array of club data files
	 *
	 * @return array of filenames
	 */
	public function getClubFilenames(): array {
		$result = glob(sprintf('%s/*/club.json', $this->responseStorage));
		return is_array($result) ? $result : array();
	}

	/**
	 * Get map of club activity data files and timestamps
	 *
	 * @param int $clubId Club ID
	 *
	 * @return array array('filename' => timestamp)
	 */
	public function getDataFilenames(int $clubId): array {
		$result = glob(sprintf('%s/%d/*-2*.json', $this->responseStorage, $clubId));
		if (is_array($result)) {
			$map = array();
			foreach ($result as $filename) {
				preg_match('#2[0-9]{3}-[01][0-9]-[0123][0-9]#', $filename, $matches);
				if (isset($matches[0]))
					$map[$filename] = strtotime($matches[0]);
			}
			return $map;
		}

		return array();
	}

	/**
	 * Downloads club details from Strava
	 *
	 * @param int $clubId Club ID
	 *
	 * @return void
	 */
	public function downloadClub(int $clubId): void {
		$file = $this->getClubFilename($clubId);
		if (!file_exists($file)) {
			$this->log("Retrieve club info for club $clubId save to ".basename($file));
			$this->checkRequestLimit();
			file_put_contents($file, json_encode($this->client->getClub($clubId), JSON_PRETTY_PRINT));
			$this->requestCount++;
		}
	}

	/**
	 * Downloads club activity data from Strava
	 *
	 * @param int $clubId Club ID
	 * @param int $start Start date (UNIX timestamp)
	 * @param int $end End date (UNIX timestamp)
	 *
	 * @return void
	 */
	public function downloadClubActivities(int $clubId, int $start, int $end): void {
		/* OK so the Strava API seems to be broken, at least my experiements in Insomnia seem to show this. Findings:
			 * Cannot have both before and after parameters set at the same time. Response == message: Bad Request, field: before after, code: both provided
			 * page parameter does nothing. The first page is always returned
			 * max value of per_page is 200

		   As a result in this implementation we will just save as .json files all the activities that are "after" each date. Then we will have to diff the results to get results by date. Fortunately these activities are returned in chronological order (oldest first) so it's not necessary to work around the pagination issue.
		*/
		while ($start <= $end) {
			$file = $this->getResponseFilename($clubId, $start);
			if (!file_exists($file)) {
				$this->log("Retrieve club activities for club $clubId for date ".date('Y-m-d', $start)." save to ".basename($file));
				$onstart = $this->getClubActivities($clubId, $start);
				$ondayafter = $this->getClubActivities($clubId, $start + 86400);
				$diff = array_udiff($onstart, $ondayafter, function ($a, $b) {
					return strcmp(serialize($a), serialize($b));
				});

				file_put_contents($file, json_encode($diff, JSON_PRETTY_PRINT));
			}
			$start += 86400;
		}
	}

	/**
	 * Log message using $this-logger
	 *
	 * @param string $msg message
	 * @param int|null $error_type (optional) PHP error type
	 *
	 * @return void
	 */
	public function log(string $msg, ?int $error_type = E_USER_NOTICE): void {
		if (!is_null($this->logger))
			call_user_func($this->logger, $msg, $error_type);
	}

	/* Helper methods */

	/**
	 * Get club activities wrapper method
	 *
	 * Calls getClubActivities API method and caches the response, since each response will be needed 
	 * more than once by downloadClubActivities() whenever multiple days in a row are being processed
	 * (array_udiff on subsequent days). Eliminates duplicate API calls to Strava.
	 *
	 * @param int $clubId Club ID
	 * @param int $start Start date (UNIX timestamp)
	 *
	 * @return array Client::getClubActivities() response
	 */
	protected function getClubActivities(int $clubId, int $start): array {
		$cacheKey = sprintf('%d%d', $clubId, $start);
		if (isset($this->activityCache[$cacheKey])) {
			$this->log("Returning item for club $clubId from cache");
			return $this->activityCache[$cacheKey];
		}
		$this->log("Calling API for item for club $clubId -- not in cache");
		$this->checkRequestLimit();
		$response = $this->client->getClubActivities($clubId, 1, 200, $start);
		$this->requestCount++;
		$this->activityCache[$cacheKey] = $response;
		return $response;
	}

	/**
	 * Check Strava API request limit against $this->requestLimit
	 *
	 * @throws ClubException when request limit is exceeded
	 *
	 * @return void
	 */
	protected function checkRequestLimit(): void {
		if ($this->getRequestCount() >= $this->requestLimit) throw new ClubException("Strava 15-minute limit of ".$this->requestLimit." requests is reached");
	}

	/**
	 * Returns filename for saving club details
	 *
	 * @param int $clubId Club ID
	 *
	 * @return string filename
	 */
	protected function getClubFilename(int $clubId): string {
		return sprintf('%s/club.json', $this->getResponseDir($clubId));
	}

	/**
	 * Returns filename for saving activity data
	 *
	 * @param int $clubId Club ID
	 * @param int $timestamp activity date
	 *
	 * @return string filename
	 */
	protected function getResponseFilename(int $clubId, int $timestamp): string {
		return sprintf('%s/results-%s.json', $this->getResponseDir($clubId), date('Y-m-d', $timestamp));
	}

	/**
	 * Returns storage directory, creates it if it doesn't exist
	 *
	 * @param int $clubId Club ID
	 *
	 * @return string directory pathname
	 */
	protected function getResponseDir(int $clubId): string {
		$dir = sprintf('%s/%d', $this->responseStorage, $clubId);
		if (!file_exists($dir)) mkdir($dir, 0700, true);
		return $dir;
	}
}

# vim: tabstop=4:shiftwidth=4:noexpandtab
