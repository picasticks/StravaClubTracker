<?php

declare(strict_types=1);

namespace picasticks\Strava;

class StravaClubException extends \Exception { }

class StravaClub {
	// Strava API request limit
	public int $requestLimit = 100;

	protected Client $client;

	protected string $responseStorage = 'response';

	protected int $start;
	protected int $end;
	protected int $requestCount = 0;

	protected array $activityCache = array();

	public function __construct(string $storageDir) {
		$this->responseStorage = $storageDir.'/'.$this->responseStorage;
	}

	public function setClient(Client $client): void {
		$this->client = $client;
	}

	public function getRequestCount(): int {
		return $this->requestCount;
	}

	public function getClubFilenames(): array {
		$result = glob(sprintf('%s/*/club.json', $this->responseStorage));
		return is_array($result) ? $result : array();
	}

	public function getDataFilenames(int $clubId): array {
		$result = glob(sprintf('%s/%d/results-*.json', $this->responseStorage, $clubId));
		return is_array($result) ? $result : array();
	}

	// Downloads club info from Strava
	public function downloadClub(int $clubId): void {
		$file = $this->getClubFilename($clubId);
		if (!file_exists($file)) {
			trigger_error("Retrieve club info for club $clubId save to ".basename($file));
			$this->checkRequestLimit();
			file_put_contents($file, json_encode($this->client->getClub($clubId), JSON_PRETTY_PRINT));
			$this->requestCount++;
		}
	}

	// Downloads activities from Strava
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
				trigger_error("Retrieve club activities for club $clubId for date ".date('Y-m-d', $start)." save to ".basename($file));
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

	// Helper methods
	// Calls getClubActivities API method and caches the result, since each response will be needed more than once by downloadClubActivities() whenever multiple days in a row are being processed (array_udiff on subsequent days) -- eliminates duplicate API calls to Strava
	protected function getClubActivities(int $clubId, int $start): array {
		$cacheKey = sprintf('%d%d', $clubId, $start);
		if (isset($this->activityCache[$cacheKey])) {
			trigger_error("Returning item for club $clubId from cache");
			return $this->activityCache[$cacheKey];
		}
		trigger_error("Calling API for item for club $clubId -- not in cache");
		$this->checkRequestLimit();
		$response = $this->client->getClubActivities($clubId, 1, 200, $start);
		$this->requestCount++;
		$this->activityCache[$cacheKey] = $response;
		return $response;
	}

	protected function checkRequestLimit(): void {
		if ($this->getRequestCount() > $this->requestLimit) throw new StravaClubScoreboardException("Strava 15-minute limit of ".$this->requestLimit." requests is reached");
	}

	protected function getClubFilename(int $clubId): string {
		return sprintf('%s/club.json', $this->getResponseDir($clubId));
	}

	protected function getResponseFilename(int $clubId, int $timestamp): string {
		return sprintf('%s/results-%s.json', $this->getResponseDir($clubId), date('Y-m-d', $timestamp));
	}

	protected function getResponseDir(int $clubId): string {
		$dir = sprintf('%s/%d', $this->responseStorage, $clubId);
		if (!file_exists($dir)) mkdir($dir, 0700, true);
		return $dir;
	}
}

# vim: tabstop=4:shiftwidth=4:noexpandtab
