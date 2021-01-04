<?php

declare(strict_types=1);

namespace picasticks\Strava;

class StravaClubScoreboardException extends \Exception { }

class StravaClubScoreboard {
	// maxSpeed defined in distanceUnit/hour
	protected array $sports = array(
		'Ride' => array('label' => 'Ride', 'multiplyScoreBy' => 0.25),
		'Run'  => array('label' => 'Run', 'maxSpeed' => 12.0),
		'Walk' => array('label' => 'Walk/Hike', 'maxSpeed' => 8.0),
		'Hike' => array('convertTo' => 'Walk', 'maxSpeed' => 8.0),
	);

	public array $distanceUnit = array(
		'Miles' => 1609.344
	);

	// Whether to count manually added activites that lack GPS data
	public bool $allowManual = false;

	// Strava API request limit
	public int $requestLimit = 100;

	protected Client $client;
	protected $templateFunction; // @type callable
	protected string $responseStorage = 'response';
	protected string $manualStorage = 'manual';

	protected int $start;
	protected int $end;
	protected int $requestCount = 0;

	protected array $clubsCache;
	protected array $scoreCache;
	protected array $activityCache = array();
	protected array $activityWhitelist = array();

	public function __construct(string $storageDir) {
		$this->responseStorage = $storageDir.'/'.$this->responseStorage;
		$this->manualStorage = $storageDir.'/'.$this->manualStorage;
	}

	public function setClient(Client $client): void {
		$this->client = $client;
	}

	public function setTemplateFunction (callable $function): void {
		$this->templateFunction = $function;
	}

	// Set a valid sport and optionally associated label and scoring rules
	public function setSport(string $sportId, string $label = null, string $convertTo = null, int $multiplyScoreBy = null, int $distanceLimit = null, float $maxSpeed = null): void {
		if (!is_null($label))
			$sport['label'] = $label;
		if (!is_null($convertTo))
			$sport['convertTo'] = $convertTo;
		if (!is_null($multiplyScoreBy))
			$sport['multiplyScoreBy'] = $multiplyScoreBy;
		if (!is_null($distanceLimit))
			$sport['distanceLimit'] = $distanceLimit;
		if (!is_null($maxSpeed))
			$sport['maxSpeed'] = $maxSpeed;
		$this->sports[$sportId] = $sport;
	}

	// Append activity $id to activityWhitelist
	public function whitelistActivity(string $id): void {
		array_push($this->activityWhitelist, $id);
	}

	public function getRequestCount(): int {
		return $this->requestCount;
	}

	// Downloads club info from Strava
	public function downloadClub(int $clubId): void {
		$file = $this->getInfoFilename($clubId);
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

	// Return array of (int) $id => (array) club data
	public function getClubs(): array {
		if (isset($this->clubsCache))
			return $this->clubsCache;
		$clubs = array();
		foreach (array_diff(scandir($this->responseStorage), array('..', '.')) as $club) {
			if (file_exists($this->responseStorage."/$club/club.json")) {
				$club = json_decode(file_get_contents($this->responseStorage."/$club/club.json"), true);
				$clubs[((int) $club['id'])] = $club;
			}
		}
		$this->clubsCache = $clubs;
		return $clubs;
	}

	// Return data structure of all activities grouped by club and athlete
	public function getResults(): array {
		$this->loadData();
		return $this->scoreCache;
	}

	// get total for $type = 'distance' 'score' or 'moving_time'. Return value is mixed (float) or (int)
	public function getTotal(string $type, int $clubId = null, string $name = null, string $sport = null) {
		$total = $this->getTotals($clubId, $name, $sport);
		return $total[$type];
	}

	// get array('distance', 'score', 'moving_time') of totals, optionally filtered by input params
	public function getTotals(int $clubId = null, string $name = null, string $sport = null): array {
		$this->loadData();

		$total = array('distance' => (float) 0, 'score' => (float) 0, 'moving_time' => 0);
		foreach ($this->scoreCache as $id => $club) {
			if (is_null($clubId) || $clubId === $id) {
				foreach ($club['athletes'] as $person => $data) {
					if (is_null($name) || $name === $person) {
						foreach ($data['totals'] as $sporttype => $subtotals) {
							if (is_null($sport) || $sport === $sporttype) {
								foreach ($total as $item => $value) {
									$total[$item] += $subtotals[$item];
								}
							}
						}
					}
				}
			}
		}

		return $total;
	}

	// get ranked list of leaders for $sport
	public function getSportLeaders(string $sport): array {
		$this->loadData();

		// array of total distance, clubId, person
		$leaders = array();
		foreach ($this->scoreCache as $clubId => $data) {
			foreach ($data['athletes'] as $person => $personData) {
				$leaders[] = array($personData['totals'][$sport]['distance'], $clubId, $person);
			}
		}
		rsort($leaders);
		return $leaders;
	}

	// get ranked list of top activities for $clubId, $name and/or $sport
	public function getTopActivities(int $clubId = null, string $name = null, string $sport = null): array {
		$this->loadData();

		// array of score, distance, clubId, person, date, name, sport
		$activities = array();
		foreach ($this->scoreCache as $id => $club) {
			if (is_null($clubId) || $clubId === $id) {
				foreach ($club['athletes'] as $person => $data) {
					if (is_null($name) || $name === $person) {
						foreach ($data['activities'] as $activity) {
							if ((is_null($sport) || $sport === $activity['type'])) {
								$activities[] = array($activity['score'], $activity['distance'], $id, $person, $activity['date'], $activity['name'], $activity['type']);
							}
						}
					}
				}
			}
		}
		rsort($activities);
		return $activities;
	}

	public function getTopActivitiesHTML(string $sport, int $limit = 5): string {
		$activities = $this->getTopActivities(null, null, $sport);

		$html[] = sprintf(
			'<div class="activities" id="activities-%s"><h3>%s</h3>',
			$sport, $this->getLabel($sport));
		$html[] = '<table><tbody><tr><th>Athlete</th><th class="numeric">'.key($this->distanceUnit).'</th><th>Date</th><th>Description</th></tr>';
		foreach ($activities as $place => $row) {
			if ($place == $limit) break;
			$html[] = sprintf(
				'<tr><th><img src="%s" style="height:20px"><a href="%s">%s</a></th><td class="numeric">%s</td><td>%s</td><td>%s</td></tr>',
				$this->filterClubImage($this->scoreCache[($row[2])]['profile_medium'], $row[2]), $this->getPersonURL($row[2], $row[3]), ucfirst($row[3]), number_format($row[1], 1), $this->formatDate($row[4]), $row[5]);
		}
		$html[] = '</tbody></table></div>';

		return $this->applyTemplate(array('content' => implode("\n", $html)), 'activities');
	}

	public function getSportLeadersHTML(string $sport, int $limit = 5): string {
		$leaders = $this->getSportLeaders($sport);

		$html[] = sprintf(
			'<div class="leaders" id="leaders-%s"><h3>%s</h3>',
			$sport, $this->getLabel($sport));
		$html[] = '<table><tbody><tr><th>Athlete</th><th class="numeric">'.key($this->distanceUnit).'</th></tr>';
		foreach ($leaders as $place => $row) {
			if ($place == $limit) break;
			$html[] = sprintf(
				'<tr><th><img src="%s" style="height:20px"><a href="%s">%s</a></th><td class="numeric">%s</td></tr>',
				$this->filterClubImage($this->scoreCache[($row[1])]['profile_medium'], $row[1]), $this->getPersonURL($row[1], $row[2]), ucfirst($row[2]), number_format($row[0], 1));
		}
		$html[] = '</tbody></table></div>';

		return $this->applyTemplate(array('content' => implode("\n", $html)), 'leaders');
	}

	public function getPersonHTML(int $clubId, string $person): string {
		$this->loadData();

		$html[] = sprintf(
			'<h3 id="%d"><a href="https://www.strava.com/clubs/%s"><img src="%s"></a> %s</h3>',
			$clubId, (empty($this->scoreCache[$clubId]['url']) ? (string) $clubId : $this->scoreCache[$clubId]['url']), $this->filterClubImage($this->scoreCache[$clubId]['profile'], $clubId), ucfirst($person));
		$html[] = '<table class="athlete"><tbody><tr><th>Date</th><th>Event</th><th>Description</th><th class="numeric">Duration</th><th class="numeric">'.key($this->distanceUnit).'</th><th class="numeric">'.key($this->distanceUnit).' (Adjusted)</th></tr>';
		foreach ($this->scoreCache[$clubId]['athletes'][$person]['activities'] as $activity) {
			$html[] = sprintf(
				'<tr title="%s"><td>%s</td><td>%s</td><td>%s</td><td class="numeric">%s</td><td class="numeric">%s</td><td class="numeric">%s</td></tr>',
				$activity['id'], $this->formatDate($activity['date']), $this->getLabel($activity['type']), $activity['name'], $this->formatSeconds($activity['moving_time']), number_format($activity['distance'], 1), number_format($activity['score'], 1));
		}
		$totals = $this->getTotals($clubId, $person);
		$html[] = sprintf(
			'<tr><th colspan="3">Total</th><th class="numeric">%s</th><th class="numeric">%s</th><th class="numeric">%s</th></tr>',
			$this->formatSeconds($totals['moving_time']), number_format($totals['distance'], 1), number_format($totals['score'], 1));
		$html[] = '</tbody></table>';

		return $this->applyTemplate(array('content' => implode("\n", $html)), 'person');
	}

	public function getClubHTML(int $clubId): string {
		$this->loadData();

		$club = $this->scoreCache[$clubId];
		$html[] = sprintf(
			'<h3 id="%d"><a href="https://www.strava.com/clubs/%s"><img src="%s"> %s</a></h3>',
			$clubId, (empty($this->scoreCache[$clubId]['url']) ? (string) $clubId : $this->scoreCache[$clubId]['url']), $this->filterClubImage($club['profile'], $clubId), $club['name']);
		$html[] = sprintf('<table class="club"><tbody><tr><th>Athlete</th><th>Event</th><th class="numeric">Hours</th><th class="numeric">%s</th><th class="numeric">Total</th><th>Top Effort</th><th class="numeric">Total (Adjusted)</th></tr>', key($this->distanceUnit));
		foreach ($club['athletes'] as $name => $data) {
			$rows = count($data['totals']);
			$html[] = '<tr><th rowspan="'.$rows.'"><a href="'.$this->getPersonURL($clubId, $name).'">'.ucfirst($name).'</a></th>';
			$row = 1;
			foreach ($data['totals'] as $sport => $totals) {
				$html[] = sprintf(
					'<td>%s</td><td class="numeric">%s</td><td class="numeric">%s</td>',
					$this->getLabel($sport), $this->formatMinutes($totals['moving_time']), number_format($totals['distance'], 1));
				if ($row == 1) {
					$activities = $this->getTopActivities($clubId, $name);
					if (count($activities) > 0) {
						$top = sprintf(
							'%s '.strtolower(preg_replace('#s$#', '', key($this->distanceUnit))).' %s', 
							number_format($activities[0][1], 1), strtolower($activities[0][6]));
					} else {
						$top = '';
					}

					$html[] = sprintf(
						'<td class="numeric" rowspan="%d">%s</td><td rowspan="%d">%s</td><th class="numeric" rowspan="%d">%s</th>',
						$rows, number_format($this->getTotal('distance', $clubId, $name), 1), $rows, $top, $rows, number_format($this->getTotal('score', $clubId, $name), 1));
				}

				$html[] = '</tr>';
				$row++;
			}
		}
		$totals = $this->getTotals($clubId);
		$html[] = sprintf(
			'<tr><th>Club Total</th><th></th><th class="numeric">%s</th><th></th><th class="numeric">%s</th><th></th><th class="numeric">%s</th></tr>',
			$this->formatMinutes($totals['moving_time']), number_format($totals['distance'], 1), number_format($totals['score'], 1));
		$html[] = '</tbody></table>';

		return $this->applyTemplate(array('content' => implode("\n", $html)), 'club');
	}

	public function getScoreboardHTML(): string {
		$this->loadData();

		// Main scoreboard
		// First, build array of which sports should be included because not all sports will have actual scored distance logged
		$sports = array();
		foreach (array_keys($this->sports) as $sport) {
			if ($this->getTotal('score', null, null, $sport) > 0)
				$sports[] = $sport;
		}
		$html[] = '<table class="standings"><tbody><tr><th colspan="2">Club</th>';
		foreach ($sports as $sport)
			$html[] = '<th class="numeric">'.$this->getLabel($sport).'</th>';
		$html[] = '<th class="numeric">Total</th><th class="numeric">Total (Adjusted)</th></tr>';
		foreach (array_keys($this->scoreCache) as $place => $clubId) {
			$html[] = sprintf(
				'<tr><th>%d.</th><th><img src="%s" style="height:20px"><a href="#%d">%s</a></th>',
				$place + 1, $this->filterClubImage($this->scoreCache[$clubId]['profile_medium'], $clubId), $clubId, $this->scoreCache[$clubId]['name']);
			foreach ($sports as $sport) {
				$html[] = sprintf(
					'<td class="numeric">%s</td>',
					number_format($this->getTotal('distance', $clubId, null, $sport), 1));
			}
			$html[] = sprintf(
				'<td class="numeric">%s</td><th class="numeric">%s</th></tr>',
				number_format($this->getTotal('distance', $clubId), 1), number_format($this->getTotal('score', $clubId), 1));
		}
		$totaltotals = $this->getTotals();
		$html[] = '<tr><th colspan="2">Totals</th>';
		foreach ($sports as $sport) {
			$html[] = sprintf(
				'<td class="numeric">%s</td>',
				number_format($this->getTotal('distance', null, null, $sport), 1));
		}
		$html[] = sprintf(
			'<td class="numeric">%s</td><th class="numeric">%s</th></tr>',
			number_format($totaltotals['distance'], 1), number_format($totaltotals['score'], 1));
		$html[] = '</tbody></table>';

		// Leaders and top activities for each sport
		$leaders = array();
		$activities = array();
		foreach ($sports as $sport) {
			$leaders[] = $this->getSportLeadersHTML($sport);
			$activities[] = $this->getTopActivitiesHTML($sport);
		}

		// Club scoreboard for each club
		$clubs = array();
		foreach (array_keys($this->scoreCache) as $clubId)
			$clubs[] = $this->getClubHTML($clubId);

		return $this->applyTemplate(array(
			'homeurl' => './',
			'distance' => round($totaltotals['distance']),
			'moving_time' => round($totaltotals['moving_time'] / 3600),
			'scoreboard' => implode("\n", $html),
			'leaders' => implode("\n", $leaders),
			'activities' => implode("\n", $activities),
			'clubs' => implode("\n", $clubs),
		), 'scoreboard');
	}

	public function getCSV(): string {
		$this->loadData();

		$rows = array();
		foreach ($this->scoreCache as $clubId => $club) {
			foreach ($club['athletes'] as $name => $data) {
				foreach ($data['activities'] as $activity) {
					unset($activity['athlete']['resource_state']);
					$activity['athlete'] = implode(' ', $activity['athlete']);
					// Since workout_type is not always set, just unset
					unset($activity['workout_type']);
					$row = array_merge(array('id' => $clubId, 'club' => $club['name']), $activity);
					foreach ($row as $k => $v) {
						if (is_string($v) && preg_match('#[" ,]#', $v))
							$row[$k] = '"'.str_replace('"', '""', $v).'"';
					}
					// Header row
					if (count($rows) === 0)
						$rows[] = implode(',', array_keys($row));

					$rows[] = implode(',', $row);
				}
			}
		}

		return implode("\n", $rows);
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

	// Loads data into memory from downloaded JSON responses. Calculates scores and stores as data structure of all activities grouped by club and athlete
	protected function loadData(): void {
		if (isset($this->scoreCache)) return;

		// $start and $end will be determined based on activity data received. Initialize to impossible values.
		$start = strtotime(date('Y-m-d')) + 31536000;
		$end = 0;

		$clubs = $this->getClubs();
		foreach (array_keys($clubs) as $clubId) {
			$this->scoreCache[$clubId] = $clubs[$clubId];
			$club = array();
			foreach ($this->getDataFilenames($clubId) as $file) {
				// Get date from filename
				preg_match('#2[0-9]{3}-[01][0-9]-[0123][0-9]#', $file, $matches);
				$date = $matches[0];
				$timestamp = strtotime($date);

				foreach (json_decode(file_get_contents($file), true) as $activity) {
					// Skip anything less than 2 minutes in duration, and manual activities if not allowed
					if ($activity['moving_time'] > 120 && (!$this->isManual($activity) || $this->allowManual)) {
						$name = str_replace(' .', '', $activity['athlete']['firstname'].' '.$activity['athlete']['lastname']);
						// Set $sport. If convertTo is set for sport, change sport, e.g. Hike => Walk. Runs slower than 17:00 mile pace are walks.
						$sport = isset($this->sports[($activity['type'])]['convertTo']) ? $this->sports[($activity['type'])]['convertTo'] : $activity['type'];
						if ($sport == 'Run' && $activity['distance']/$activity['moving_time'] < 5700/3600)
							$sport = 'Walk';

						// convert meters to distanceUnit 
						$distance = (float) $activity['distance'] / current($this->distanceUnit);

						// Since there is no "activity ID" construct a key based on mostly immutable values for the activity
						$id = sha1(sprintf('%d%s%s%f%s%d%d%f', $clubId, $name, $date, $distance, $sport, $activity['moving_time'], $activity['elapsed_time'], $activity['total_elevation_gain']));

						// compute $score
						$score = $this->computeScore($id, $distance, $sport, $activity['moving_time'], $activity['elapsed_time']);

						// Append activity to activity log for each athlete
						$club[$name]['activities'][] = array_merge($activity, array('type' => $sport, 'distance' => $distance, 'date' => $date, 'score' => $score, 'id' => $id));

						// Add to running totals for each athlete for each sport
						if (isset($this->sports[$sport])) {
							if (isset($club[$name]['totals'][$sport])) {
								$club[$name]['totals'][$sport]['distance'] += $distance;
								$club[$name]['totals'][$sport]['moving_time'] += $activity['moving_time'];
								$club[$name]['totals'][$sport]['score'] += $score;
							} else {
								$club[$name]['totals'][$sport]['distance'] = $distance;
								$club[$name]['totals'][$sport]['moving_time'] = $activity['moving_time'];
								$club[$name]['totals'][$sport]['score'] = $score;
							}
						}
					}
				}
				// Set challenge $start and $end based on dates (earliest and latest) of downloaded files
				if ($timestamp < $start)
					$start = $timestamp;
				if ($timestamp > $end)
					$end = $timestamp;
			}

			// Re-sort activities using combination of date and original Strava order (so manual activities are interleaved in the right locations)
			foreach (array_keys($club) as $name)
				array_multisort(array_column($club[$name]['activities'], 'date'), array_keys($club[$name]['activities']), $club[$name]['activities']);

			$totals = array();
			// Need to set scoreCache temporarily to avoid getTotal from doing endless recursion
			$this->scoreCache[$clubId]['athletes'] = $club;
			foreach (array_keys($club) as $name) {
				$totals[$name] = $this->getTotal('score', $clubId, $name);
				// Sort totals by names of sports alphabetically so order of totals is consistent for every athlete
				ksort($club[$name]['totals']);
			}

			// Sort athletes by adjusted score
			array_multisort($totals, SORT_DESC, $club);

			$clubs[$clubId]['athletes'] = $club;
		}

		// Sort clubs by adjusted score
		$totals = array();
		foreach (array_keys($clubs) as $clubId)
			$totals[$clubId] = $this->getTotal('score', $clubId);
		arsort($totals);

		unset($this->scoreCache);
		foreach (array_keys($totals) as $clubId)
			$this->scoreCache[$clubId] = $clubs[$clubId];

		$this->start = $start;
		$this->end = $end;
	}

	protected function checkRequestLimit(): void {
		if ($this->getRequestCount() > $this->requestLimit) throw new StravaClubScoreboardException("Strava 15-minute limit of ".$this->requestLimit." requests is reached");
	}

	// Applies array of $vars using $template
	protected function applyTemplate(array $vars = array(), string $template): string {
		// Set some default values for $vars
		$vars = array_merge(array(
			'homeurl' => '../',
			'currentDay' => ($this->end - strtotime('2020-07-28'))/86400 + 1,
			'currentDate' => date('F j', $this->end),
			'timestamp' => date('D, d M Y H:i T'),
		), $vars);
		return call_user_func($this->templateFunction, $vars, $template);
	}

	protected function filterClubImage(string $url, int $clubId): string {
		switch ($url) {
		case 'avatar/club/medium.png':
			return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60' viewBox='0 0 500 500'%3E%3Cpath d='M427 73A250 250 0 1073 428 250 250 0 00427 73z'/%3E%3Cpath d='M400 400a212 212 0 11-300-300 212 212 0 01300 300z' fill='%23ff0'/%3E%3Cpath d='M289 182a29 29 0 1159 0 29 29 0 01-59 0zM157 182a29 29 0 1158 0 29 29 0 01-58 0zM363 349a14 14 0 11-26 11 88 88 0 00-82-52c-37 0-70 21-83 52a14 14 0 11-26-11c18-42 60-69 109-69 48 0 90 27 108 69z'/%3E%3C/svg%3E";
			break;
		case 'avatar/club/large.png':
			return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='124' height='124' viewBox='0 0 500 500'%3E%3Cpath d='M427 73A250 250 0 1073 428 250 250 0 00427 73z'/%3E%3Cpath d='M400 400a212 212 0 11-300-300 212 212 0 01300 300z' fill='%23ff0'/%3E%3Cpath d='M289 182a29 29 0 1159 0 29 29 0 01-59 0zM157 182a29 29 0 1158 0 29 29 0 01-58 0zM363 349a14 14 0 11-26 11 88 88 0 00-82-52c-37 0-70 21-83 52a14 14 0 11-26-11c18-42 60-69 109-69 48 0 90 27 108 69z'/%3E%3C/svg%3E";
			break;
		default:
			$filename = $this->getResponseDir($clubId).'/'.basename($url);
			if (!file_exists($filename)) {
				// Save local copy
				file_put_contents($filename, file_get_contents($url));
			}
		}
		return $url;
	}

	// Format $seconds as h:mm:ss or mm:ss if less than one hour
	protected function formatSeconds(int $seconds): string {
		if ($seconds >= 3600)
			return sprintf('%01d:%02d:%02d', ($seconds/3600),($seconds/60%60), $seconds%60);
		return sprintf('%02d:%02d', ($seconds/60%60), $seconds%60);
	}

	// Format $seconds as h:mm or :mm if less than one hour
	protected function formatMinutes(int $seconds): string {
		if ($seconds >= 3600)
			return sprintf('%01d:%02d', ($seconds/3600),($seconds/60%60));
		return sprintf(':%02d', ($seconds/60%60));
	}

	// Format YYYY-MM-DD date
	protected function formatDate(string $date): string {
		return sprintf('%d/%d/%s', intval(substr($date, 5, 2)), intval(substr($date, -2, 2)), substr($date, 0, 4));
	}

	// Return label for a sport
	protected function getLabel(string $sportId): string {
		return isset($this->sports[$sportId]) ? $this->sports[$sportId]['label'] : $sportId;
	}

	// Calculate score for an activity based on $distance and $sport
	protected function computeScore(string $id, float $distance, string $sport, int $moving = null, int $elapsed = null): float {
		if (!isset($this->sports[$sport]))
			return (float) 0;

		// Calculate score
		if (isset($this->sports[$sport]['distanceLimit']))
		   $distance = (float) min($distance, $this->sports[$sport]['distanceLimit']);
		if (isset($this->sports[$sport]['multiplyScoreBy']))
			$distance = (float) $distance * $this->sports[$sport]['multiplyScoreBy'];

		// Now some rules that attempt to enforce basic data quality and exclude obvious user errors
		if (!in_array($id, $this->activityWhitelist, true)) {
			// zero if maxSpeed is exceeded for sport
			if (!is_null($moving) && isset($this->sports[$sport]['maxSpeed']) && 3600 * $distance/$moving > $this->sports[$sport]['maxSpeed']) return (float) 0;

			// zero if $elapsed > 18 hours
			if (!is_null($elapsed) && $elapsed > 64800) return (float) 0;
		}

		return $distance;
	}

	protected function isManual(array $activity): bool {
		return $activity['total_elevation_gain'] == 0 && $activity['moving_time'] == $activity['elapsed_time'];
	}

	protected function getPersonURL(int $clubId, string $person): string {
		$dir = sprintf('%d', $clubId);
		return sprintf('%s/%s.html', $dir, preg_replace('#[\s.]#', '_', $person));
	}

	public function getPersonHTMLFilename(string $baseDir, int $clubId, string $person): string {
		$dir = sprintf('%s/%d', $baseDir, $clubId);
		if (!file_exists($dir)) mkdir($dir, 0755);
		return sprintf('%s/%s.html', $dir, preg_replace('#[\s.]#', '_', $person));
	}

	protected function getResponseDir(int $clubId): string {
		$dir = sprintf('%s/%d', $this->responseStorage, $clubId);
		if (!file_exists($dir)) mkdir($dir, 0700, true);
		return $dir;
	}

	protected function getManualDir(int $clubId): string {
		$dir = sprintf('%s/%d', $this->manualStorage, $clubId);
		if (!file_exists($dir)) mkdir($dir, 0700, true);
		return $dir;
	}

	protected function getInfoFilename(int $clubId): string {
		return sprintf('%s/club.json', $this->getResponseDir($clubId));
	}

	protected function getResponseFilename(int $clubId, int $timestamp): string {
		return sprintf('%s/results-%s.json', $this->getResponseDir($clubId), date('Y-m-d', $timestamp));
	}

	protected function getDataFilenames(int $clubId): array {
		return array_merge(
			glob($this->getResponseDir($clubId).'/results-*json'),
			glob($this->getManualDir($clubId).'/*json')
		);
	}
}

# vim: tabstop=4:shiftwidth=4:noexpandtab
