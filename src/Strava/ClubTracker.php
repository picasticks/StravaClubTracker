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

class ClubTrackerException extends \Exception { }

class ClubTracker {
	/**
	 * @var Distance unit to use and length in meters. e.g. 'Miles' => 1609.344 or 'KM' => 1000
	 * @type array(string 'label' => (float) meters)
	 */
	public array $distanceUnit = array(
		'Miles' => 1609.344
	);

	/**
	 * @var Whether to count manually added Strava activities that lack GPS data
	 * @type int
	 */
	public bool $allowManual = false;

	/**
	 * @var Array of sports to include and formatting/counting rules for sports
	 * @type array of sport ID => attributes
	 */
	protected array $sports = array();

	/**
	 * @var Whitelist of activities that are always counted, bypassing sanity checks
	 * @type array of activity IDs
	 */
	protected array $activityWhitelist = array();

	/**
	 * @var template function/method
	 * @type callable
	 */
	protected $templateFunction;

	// Club and activity data
	protected int $start;
	protected int $end;
	protected array $clubData;
	protected array $resultsData;

	// Club instance
	protected Club $data;

	/**
	 * Constructor
	 *
	 * @param Club $data Club instance object
	 */
	public function __construct(Club $data) {
		$this->data = $data;
		$this->loadClubData();
	}

	/**
	 * Set template function
	 *
	 * @param callable $function callable to apply array of template variables to template
	 *
	 * @return void
	 */
	public function setTemplateFunction (callable $function): void {
		$this->templateFunction = $function;
	}

	/**
	 * Add or set a sport, including label and totaling rules
	 *
	 * Attributes may include:
	 *
	 *   string $label (optional) to use for sport name in formatted output (if not set, $sportId is used).
	 *
	 *   string $convertTo (optional) sport ID of another sport to which this sport ID's activities should be converted. Use to combine multiple Strava sports together for simplified reporting, e.g. to merge "Walk" and "Hike".
	 *
	 *   float $distanceMultiplier (optional) Multiplier to apply to distance to compute adjusted total. e.g. setting Ride to 0.25 and Walk to 1 means each Walk mile is counted the same as 4 Ride miles.
	 *
	 *   float $maxSpeed (optional) Maximum speed for a single activity for a sport, in distance units per hour. Activities that exceed this limit are counted as 0 (the user should edit them in Strava and either set the correct activity type, or edit the activity to remove distance covered in a vehicle).
	 *
	 *   float $distanceLimit (optional) Hard distance limit for a single activity for a sport. Activities that exceed this limit are counted up to the distanceLimit.
	 *
	 * @param string $sportId sport ID
	 * @param array $attributes (optional)
	 *
	 * @return void
	 */
	public function setSport(string $sportId, array $attributes = array()): void {
		$types = array(
			'label' => 'string',
			'convertTo' => 'string',
			'distanceMultiplier' => 'float',
			'maxSpeed' => 'float',
			'distanceLimit' => 'float',
		);

		$sport = array();
		foreach ($types as $name => $type) {
			if (isset($attributes[$name])) {
				settype($attributes[$name], $type);
				$sport[$name] = $attributes[$name];
			}
		}
		$this->sports[$sportId] = $sport;
	}

	/**
	 * Add activity to activity whitelist
	 *
	 * Whitelisted activities are always counted, bypassing sanity checks
	 *
	 * @param string $id activity ID
	 *
	 * @return void
	 */
	public function whitelistActivity(string $id): void {
		array_push($this->activityWhitelist, $id);
	}

	/**
	 * Return array of clubs and club attributes
	 *
	 * @return array of (int) clubId => (array) club attributes
	 */
	public function getClubs(): array {
		return $this->clubData;
	}

	/**
	 * Return hierarchical data structure of all activities grouped by club and athlete
	 *
	 * @return array of activity data
	 */
	public function getResults(): array {
		return $this->resultsData;
	}

	/**
	 * Get total distance, total or moving time
	 *
	 * Optionally filter by club, person and sport
	 *
	 * @param string $type 'distance' 'total' or 'moving_time'
	 * @param int $clubId (optional) Club ID
	 * @param string $person (optional) person name
	 * @param string $sport (optional) sport ID
	 *
	 * @return mixed (float) distance or total, (int) moving_time
	 */
	public function getTotal(string $type, int $clubId = null, string $person = null, string $sport = null) {
		$totals = $this->getTotals($clubId, $person, $sport);
		return $totals[$type];
	}

	/**
	 * Get total distance, total and moving time
	 *
	 * Optionally filter by club, person and sport
	 *
	 * @param int $clubId (optional) Club ID
	 * @param string $person (optional) person name
	 * @param string $sport (optional) sport ID
	 *
	 * @return array of: distance, total, moving_time totals
	 */
	public function getTotals(int $clubId = null, string $person = null, string $sport = null): array {
		$totals = array('distance' => (float) 0, 'total' => (float) 0, 'moving_time' => 0);
		foreach ($this->resultsData as $id => $club) {
			if (is_null($clubId) || $clubId === $id) {
				foreach ($club['athletes'] as $personName => $data) {
					if (is_null($person) || $person === $personName) {
						foreach ($data['totals'] as $sporttype => $subtotals) {
							if (is_null($sport) || $sport === $sporttype) {
								foreach ($totals as $item => $value) {
									$totals[$item] += $subtotals[$item];
								}
							}
						}
					}
				}
			}
		}

		return $totals;
	}

	/**
	 * Get ranked list of leaders for a sport/activity type
	 *
	 * @param string $sport sport ID
	 *
	 * @return array of: total distance, clubId, person name
	 */
	public function getSportLeaders(string $sport): array {
		$leaders = array();
		foreach ($this->resultsData as $clubId => $data) {
			foreach ($data['athletes'] as $person => $personData) {
				$leaders[] = array($personData['totals'][$sport]['distance'], $clubId, $person);
			}
		}
		rsort($leaders);
		return $leaders;
	}

	/**
	 * Get ranked list of top activities
	 *
	 * Optionally filter by club, person and sport
	 *
	 * @param int $clubId (optional) Club ID
	 * @param string $person (optional) person name
	 * @param string $sport (optional) sport ID
	 *
	 * @return array of activity data: total, distance, clubId, person name, date, activity name, sport
	 */
	public function getTopActivities(int $clubId = null, string $person = null, string $sport = null): array {
		$activities = array();
		foreach ($this->resultsData as $id => $club) {
			if (is_null($clubId) || $clubId === $id) {
				foreach ($club['athletes'] as $personName => $data) {
					if (is_null($person) || $person === $personName) {
						foreach ($data['activities'] as $activity) {
							if ((is_null($sport) || $sport === $activity['type'])) {
								$activities[] = array($activity['total'], $activity['distance'], $id, $personName, $activity['date'], $activity['name'], $activity['type']);
							}
						}
					}
				}
			}
		}
		rsort($activities);
		return $activities;
	}

	/**
	 * Returns HTML table of top performances for a sport/activity type
	 *
	 * Applies template name 'activities'
	 *
	 * @param string $sport sport ID
	 * @param int $limit (optional) number of athletes to include (defaults to top 5)
	 *
	 * @return string HTML
	 */
	public function getTopActivitiesHTML(string $sport, int $limit = 5): string {
		$activities = $this->getTopActivities(null, null, $sport);

		$html[] = sprintf(
			'<div class="activities" id="activities-%s"><h3>%s</h3>',
			$sport, $this->getLabel($sport));
		$html[] = '<table><tbody><tr><th>Athlete</th><th class="numeric">'.key($this->distanceUnit).'</th><th>Date</th><th>Description</th></tr>';
		foreach ($activities as $place => $row) {
			if ($place == $limit) break;
			$html[] = sprintf(
				'<tr><th><img alt="logo" src="%s" style="height:20px"><a href="%s">%s</a></th><td class="numeric">%s</td><td>%s</td><td>%s</td></tr>',
				$this->filterClubImage($this->resultsData[($row[2])]['profile_medium'], $row[2]), $this->getPersonURL($row[2], $row[3]), ucfirst($row[3]), number_format($row[1], 1), $this->formatDate($row[4]), $row[5]);
		}
		$html[] = '</tbody></table></div>';

		return $this->applyTemplate(array('content' => implode("\n", $html)), 'activities');
	}

	/**
	 * Returns HTML table of leaders for a sport
	 *
	 * Applies template name 'leaders'
	 *
	 * @param string $sport sport ID
	 * @param int $limit (optional) number of athletes to include (defaults to top 5)
	 *
	 * @return string HTML
	 */
	public function getSportLeadersHTML(string $sport, int $limit = 5): string {
		$leaders = $this->getSportLeaders($sport);

		$html[] = sprintf(
			'<div class="leaders" id="leaders-%s"><h3>%s</h3>',
			$sport, $this->getLabel($sport));
		$html[] = '<table><tbody><tr><th>Athlete</th><th class="numeric">'.key($this->distanceUnit).'</th></tr>';
		foreach ($leaders as $place => $row) {
			if ($place == $limit) break;
			$html[] = sprintf(
				'<tr><th><img alt="logo" src="%s" style="height:20px"><a href="%s">%s</a></th><td class="numeric">%s</td></tr>',
				$this->filterClubImage($this->resultsData[($row[1])]['profile_medium'], $row[1]), $this->getPersonURL($row[1], $row[2]), ucfirst($row[2]), is_null($row[0]) ? '0' : number_format($row[0], 1));
		}
		$html[] = '</tbody></table></div>';

		return $this->applyTemplate(array('content' => implode("\n", $html)), 'leaders');
	}

	/**
	 * Returns HTML activty log for a single athlete
	 *
	 * Applies template name 'person'
	 *
	 * @param int $clubId Club ID
	 * @param string $person person name
	 *
	 * @return string HTML
	 */
	public function getPersonHTML(int $clubId, string $person): string {
		$html[] = sprintf(
			'<h3 id="%d"><a href="https://www.strava.com/clubs/%s"><img alt="logo" src="%s"></a> %s</h3>',
			$clubId, (empty($this->resultsData[$clubId]['url']) ? (string) $clubId : $this->resultsData[$clubId]['url']), $this->filterClubImage($this->resultsData[$clubId]['profile'], $clubId), ucfirst($person));
		$html[] = '<table class="athlete"><tbody><tr><th>Date</th><th>Event</th><th>Description</th><th class="numeric">Duration</th><th class="numeric">'.key($this->distanceUnit).'</th><th class="numeric">'.key($this->distanceUnit).' (Adjusted)</th></tr>';
		foreach ($this->resultsData[$clubId]['athletes'][$person]['activities'] as $activity) {
			$html[] = sprintf(
				'<tr title="%s"><td>%s</td><td>%s</td><td>%s</td><td class="numeric">%s</td><td class="numeric">%s</td><td class="numeric">%s</td></tr>',
				$activity['id'], $this->formatDate($activity['date']), $this->getLabel($activity['type']), $activity['name'], $this->formatSeconds($activity['moving_time']), number_format($activity['distance'], 1), number_format($activity['total'], 1));
		}
		$totals = $this->getTotals($clubId, $person);
		$html[] = sprintf(
			'<tr><th colspan="3">Total</th><th class="numeric">%s</th><th class="numeric">%s</th><th class="numeric">%s</th></tr>',
			$this->formatSeconds($totals['moving_time']), number_format($totals['distance'], 1), number_format($totals['total'], 1));
		$html[] = '</tbody></table>';

		return $this->applyTemplate(array('content' => implode("\n", $html)), 'person');
	}

	/**
	 * Returns HTML club roster and totals for a club
	 *
	 * Applies template name 'club'
	 *
	 * @param int $clubId Club ID
	 *
	 * @return string HTML
	 */
	public function getClubHTML(int $clubId): string {
		$club = $this->resultsData[$clubId];
		$html[] = sprintf(
			'<h3 id="%d"><a href="https://www.strava.com/clubs/%s"><img alt="logo" src="%s"> %s</a></h3>',
			$clubId, (empty($this->resultsData[$clubId]['url']) ? (string) $clubId : $this->resultsData[$clubId]['url']), $this->filterClubImage($club['profile'], $clubId), $club['name']);
		$html[] = sprintf('<table class="club"><tbody><tr><th>Athlete</th><th>Event</th><th class="numeric">Hours</th><th class="numeric">%s</th><th class="numeric">Total</th><th>Top Effort</th><th class="numeric">Total (Adjusted)</th></tr>', key($this->distanceUnit));
		foreach ($club['athletes'] as $name => $data) {
			$rows = count($data['totals']);
			$html[] = '<tr><th rowspan="'.$rows.'"><a href="'.$this->getPersonURL($clubId, $name).'">'.ucfirst($name).'</a></th>';
			$row = 1;
			foreach ($data['totals'] as $sport => $totals) {
				if ($row > 1)
					$html[] = '<tr>';

				$html[] = sprintf(
					'<td>%s</td><td class="numeric">%s</td><td class="numeric">%s</td>',
					$this->getLabel($sport), $this->formatHours($totals['moving_time']), number_format($totals['distance'], 1));

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
						$rows, number_format($this->getTotal('distance', $clubId, $name), 1), $rows, $top, $rows, number_format($this->getTotal('total', $clubId, $name), 1));
				}

				$html[] = '</tr>';
				$row++;
			}
		}
		$totals = $this->getTotals($clubId);
		$html[] = sprintf(
			'<tr><th>Club Total</th><th></th><th class="numeric">%s</th><th></th><th class="numeric">%s</th><th></th><th class="numeric">%s</th></tr>',
			$this->formatHours($totals['moving_time']), number_format($totals['distance'], 1), number_format($totals['total'], 1));
		$html[] = '</tbody></table>';

		return $this->applyTemplate(array('content' => implode("\n", $html)), 'club');
	}

	/**
	 * Returns main HTML summary tables
	 *
	 * Includes standings, top individual performances, club totals
	 *
	 * Applies template name 'index'
	 *
	 * @return string HTML
	 */
	public function getSummaryHTML(): string {
		// Club totals
		// First, build array of which sports should be included because not all sports will have actual distance logged
		$sports = array();
		foreach (array_keys($this->sports) as $sport) {
			if ($this->getTotal('total', null, null, $sport) > 0)
				$sports[] = $sport;
		}
		$html[] = '<table class="standings"><tbody><tr><th colspan="2">Club</th>';
		foreach ($sports as $sport)
			$html[] = '<th class="numeric">'.$this->getLabel($sport).'</th>';
		$html[] = '<th class="numeric">Total</th><th class="numeric">Total (Adjusted)</th></tr>';
		foreach (array_keys($this->resultsData) as $place => $clubId) {
			$html[] = sprintf(
				'<tr><th>%d.</th><th><img alt="logo" src="%s" style="height:20px"><a href="#%d">%s</a></th>',
				$place + 1, $this->filterClubImage($this->resultsData[$clubId]['profile_medium'], $clubId), $clubId, $this->resultsData[$clubId]['name']);
			foreach ($sports as $sport) {
				$html[] = sprintf(
					'<td class="numeric">%s</td>',
					number_format($this->getTotal('distance', $clubId, null, $sport), 1));
			}
			$html[] = sprintf(
				'<td class="numeric">%s</td><th class="numeric">%s</th></tr>',
				number_format($this->getTotal('distance', $clubId), 1), number_format($this->getTotal('total', $clubId), 1));
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
			number_format($totaltotals['distance'], 1), number_format($totaltotals['total'], 1));
		$html[] = '</tbody></table>';

		// Leaders and top activities for each sport
		$leaders = array();
		$activities = array();
		foreach ($sports as $sport) {
			$leaders[] = $this->getSportLeadersHTML($sport);
			$activities[] = $this->getTopActivitiesHTML($sport);
		}

		// Club totals for each club
		$clubs = array();
		foreach (array_keys($this->resultsData) as $clubId)
			$clubs[] = $this->getClubHTML($clubId);

		return $this->applyTemplate(array(
			'homeurl' => './',
			'distance' => round($totaltotals['distance']),
			'moving_time' => round($totaltotals['moving_time'] / 3600),
			'summary' => implode("\n", $html),
			'leaders' => implode("\n", $leaders),
			'activities' => implode("\n", $activities),
			'clubs' => implode("\n", $clubs),
		), 'index');
	}

	/**
	 * Returns all activity data in CSV format
	 *
	 * Includes header row
	 *
	 * @return string CSV-formatted data export
	 */
	public function getCSV(): string {
		$rows = array();
		foreach ($this->resultsData as $clubId => $club) {
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

	/**
	 * Load activity data from disk (downloaded JSON responses)
	 *
	 * Calculates totals and stores as hierarchical data structure of all activities grouped by club and athlete.
	 *
	 * Sets $this->start and $this->end using activity dates.
	 *
	 * @return void
	 */
	public function loadActivityData(): void {
		// $start and $end timestamps will be determined based on activity data received. Initialize to impossible values.
		$start = strtotime(date('Y-m-d')) + 31536000;
		$end = 0;

		$clubs = $this->getClubs();
		foreach (array_keys($clubs) as $clubId) {
			$this->resultsData[$clubId] = $clubs[$clubId];
			$club = array();
			foreach ($this->data->getDataFilenames($clubId) as $file => $timestamp) {
				$date = date('Y-m-d', $timestamp);
				foreach (json_decode(file_get_contents($file), true) as $activity) {
					// Skip manual activities (unless permitted) and anything less than 90 seconds in duration
					if ($activity['moving_time'] > 90 && (!$this->isManual($activity) || $this->allowManual)) {
						$name = str_replace(' .', '', $activity['athlete']['firstname'].' '.$activity['athlete']['lastname']);

						// Set $sport. If convertTo is set for sport, change sport, e.g. Hike => Walk. Also convert runs slower than 17:00 mile pace to walks.
						$sport = isset($this->sports[($activity['type'])]['convertTo']) ? $this->sports[($activity['type'])]['convertTo'] : $activity['type'];
						if ($sport == 'Run' && $activity['distance']/$activity['moving_time'] < 5700/3600)
							$sport = 'Walk';

						// convert meters to distanceUnit 
						$distance = (float) $activity['distance'] / current($this->distanceUnit);

						// Since there is no "activity ID" construct a key based on mostly immutable values for the activity
						$id = sha1(sprintf('%d%s%s%f%s%d%d%f', $clubId, $name, $date, $distance, $sport, $activity['moving_time'], $activity['elapsed_time'], $activity['total_elevation_gain']));

						// compute $total
						$total = $this->adjustDistance($id, $distance, $sport, $activity['moving_time'], $activity['elapsed_time']);

						// Append activity to activity log for each athlete
						$club[$name]['activities'][] = array_merge($activity, array('type' => $sport, 'distance' => $distance, 'date' => $date, 'total' => $total, 'id' => $id));

						// Add to running totals for each athlete for each sport
						if (!isset($club[$name]['totals']))
							$club[$name]['totals'] = array();

						if (isset($this->sports[$sport])) {
							if (isset($club[$name]['totals'][$sport])) {
								$club[$name]['totals'][$sport]['distance'] += $distance;
								$club[$name]['totals'][$sport]['moving_time'] += $activity['moving_time'];
								$club[$name]['totals'][$sport]['total'] += $total;
							} else {
								$club[$name]['totals'][$sport]['distance'] = $distance;
								$club[$name]['totals'][$sport]['moving_time'] = $activity['moving_time'];
								$club[$name]['totals'][$sport]['total'] = $total;
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

			// Re-sort activities using combination of date and original Strava order (so activities contained in any manually created .json files are interleaved in the right locations)
			foreach (array_keys($club) as $name)
				array_multisort(array_column($club[$name]['activities'], 'date'), array_keys($club[$name]['activities']), $club[$name]['activities']);

			$totals = array();
			// Need to set resultsData temporarily to avoid getTotal from doing endless recursion
			$this->resultsData[$clubId]['athletes'] = $club;
			foreach (array_keys($club) as $name) {
				$totals[$name] = $this->getTotal('total', $clubId, $name);
				// Sort totals by names of sports alphabetically so order of totals is consistent for every athlete
				ksort($club[$name]['totals']);
			}

			// Sort athletes by adjusted totals
			array_multisort($totals, SORT_DESC, $club);

			$clubs[$clubId]['athletes'] = $club;
		}

		// Sort clubs by adjusted totals
		$totals = array();
		foreach (array_keys($clubs) as $clubId)
			$totals[$clubId] = $this->getTotal('total', $clubId);
		arsort($totals);

		unset($this->resultsData);
		foreach (array_keys($totals) as $clubId)
			$this->resultsData[$clubId] = $clubs[$clubId];

		$this->start = $start;
		$this->end = $end;
	}

	// Helper methods

	/**
	 * Load club data from disk
	 *
	 * @return void
	 */
	protected function loadClubData(): void {
		$clubs = array();
		foreach ($this->data->getClubFilenames() as $file) {
			$club = json_decode(file_get_contents($file), true);
			$clubs[((int) $club['id'])] = $club;
		}
		$this->clubData = $clubs;
	}

	/**
	 * Applies array of variables to $template
	 *
	 * @param array $vars (optional) map of variables to apply
	 * @param string $template name or identifier of template
	 *
	 * @return string output
	 */
	protected function applyTemplate(array $vars = array(), string $template): string {
		// Set some default values for $vars
		$vars = array_merge(array(
			'homeurl' => '../',
			'distanceUnit' => key($this->distanceUnit),
			'currentDay' => ($this->end - $this->start)/86400 + 1,
			'currentDate' => date('F j', $this->end),
			'timestamp' => date('D, d M Y H:i T'),
		), $vars);
		return call_user_func($this->templateFunction, $vars, $template);
	}

	/**
	 * Filters image URLs returned by Strava
	 *
	 * Used to replace Strava placeholder .png images with a :( data URI
	 *
	 * @param string $url image URL
	 * @param int $clubId Club ID
	 *
	 * @return string image URL
	 */
	protected function filterClubImage(string $url, int $clubId): string {
		$sadface = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='{size}' height='{size}' viewBox='0 0 500 500'%3E%3Cpath d='M427 73A250 250 0 1073 428 250 250 0 00427 73z'/%3E%3Cpath d='M400 400a212 212 0 11-300-300 212 212 0 01300 300z' fill='%23ff0'/%3E%3Cpath d='M289 182a29 29 0 1159 0 29 29 0 01-59 0zM157 182a29 29 0 1158 0 29 29 0 01-58 0zM363 349a14 14 0 11-26 11 88 88 0 00-82-52c-37 0-70 21-83 52a14 14 0 11-26-11c18-42 60-69 109-69 48 0 90 27 108 69z'/%3E%3C/svg%3E";

		switch ($url) {
		case 'avatar/club/medium.png':
			return str_replace('{size}', '60', $sadface);
			break;
		case 'avatar/club/large.png':
			return str_replace('{size}', '124', $sadface);
			break;
		}
		return $url;
	}

	/**
	 * Format seconds, used for individual activity lists
	 *
	 * Formats as h:mm:ss, or mm:ss when duration is less than one hour
	 *
	 * @param int $seconds activity duration in seconds
	 *
	 * @return string formatted duration
	 */
	protected function formatSeconds(int $seconds): string {
		if ($seconds >= 3600)
			return sprintf('%01d:%02d:%02d', ($seconds/3600),($seconds/60%60), $seconds%60);
		return sprintf('%02d:%02d', ($seconds/60%60), $seconds%60);
	}

	/**
	 * Format hours, used for totals
	 *
	 * Formats as h:mm, or :mm when duration is less than one hour
	 *
	 * @param int $seconds duration in seconds
	 *
	 * @return string formatted duration
	 */
	protected function formatHours(int $seconds): string {
		if ($seconds >= 3600)
			return sprintf('%01d:%02d', ($seconds/3600),($seconds/60%60));
		return sprintf(':%02d', ($seconds/60%60));
	}

	/**
	 * Reformats YYYY-MM-DD date as MM/DD/YYYY
	 *
	 * @param string $date in YYYY-MM-DD
	 *
	 * @return string date as MM/DD/YYYY
	 */
	protected function formatDate(string $date): string {
		return sprintf('%d/%d/%s', intval(substr($date, 5, 2)), intval(substr($date, -2, 2)), substr($date, 0, 4));
	}

	/**
	 * Return label for sport
	 *
	 * @param string $sportId Sport ID
	 *
	 * @return string label
	 */
	protected function getLabel(string $sportId): string {
		return !is_null($this->sports[$sportId]['label']) ? $this->sports[$sportId]['label'] : $sportId;
	}

	/**
	 * Calculate adjusted total for an activity based on distance, sport, moving and elapsed time
	 *
	 * @param string $id activity ID
	 * @param float $distance distance in distance units
	 * @param string $sport sport ID
	 * @param int $moving (optional) moving time in seconds
	 * @param int $elapsed (optional) elapsed time in seconds
	 *
	 * @return float adjusted distance/total for activity
	 */
	protected function adjustDistance(string $id, float $distance, string $sport, int $moving = null, int $elapsed = null): float {
		if (!isset($this->sports[$sport]))
			return (float) 0;

		// Apply adjustments
		if (isset($this->sports[$sport]['distanceLimit']))
		   $distance = (float) min($distance, $this->sports[$sport]['distanceLimit']);
		if (isset($this->sports[$sport]['distanceMultiplier']))
			$distance = (float) $distance * $this->sports[$sport]['distanceMultiplier'];

		// Now some rules that attempt to enforce basic data quality and exclude obvious user errors
		if (!in_array($id, $this->activityWhitelist, true)) {
			// zero if maxSpeed is exceeded for sport
			if (!is_null($moving) && isset($this->sports[$sport]['maxSpeed']) && 3600 * $distance/$moving > $this->sports[$sport]['maxSpeed']) return (float) 0;

			// zero if $elapsed > 18 hours
			if (!is_null($elapsed) && $elapsed > 64800) return (float) 0;
		}

		return $distance;
	}

	/**
	 * Return whether activity was manually added to Strava or is based on actual GPS data
	 *
	 * @param array $activity activity data
	 *
	 * @return bool true if activity was manually added, false if it contains recorded GPS data
	 */
	protected function isManual(array $activity): bool {
		return $activity['total_elevation_gain'] == 0 && $activity['moving_time'] == $activity['elapsed_time'];
	}

	/**
	 * Get URL for HTML page showing person activity details
	 *
	 * @param int $clubId Club ID
	 * @param string $person person name
	 *
	 * @return string relative URL
	 */
	protected function getPersonURL(int $clubId, string $person): string {
		$dir = sprintf('%d', $clubId);
		return sprintf('%s/%s.html', $dir, preg_replace('#[\s.]#', '_', $person));
	}

	/**
	 * Get filesystem path for HTML page showing person activity details
	 *
	 * @param string $baseDir Filesystem base directory
	 * @param int $clubId Club ID
	 * @param string $person person name
	 *
	 * @return string filename
	 */
	public function getPersonHTMLFilename(string $baseDir, int $clubId, string $person): string {
		$dir = sprintf('%s/%d', $baseDir, $clubId);
		if (!file_exists($dir))
			mkdir($dir, 0755);
		return sprintf('%s/%s.html', $dir, preg_replace('#[\s.]#', '_', $person));
	}
}

# vim: tabstop=4:shiftwidth=4:noexpandtab
