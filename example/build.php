<?php

declare(strict_types=1);

use picasticks\Strava\Club;
use picasticks\Strava\Scoreboard;

// Set a TZ for date calculations
date_default_timezone_set('America/New_York');

require_once 'lib/vendor/autoload.php';

$scoreboard = new Scoreboard(new Club(__DIR__.'/json'));

// Example template function
// This one is super basic, fills in {{variables}} in template
$scoreboard->setTemplateFunction(function (array $vars, string $template): string {
	// Load template based on value of $template
	switch ($template) {
		case 'club':
		case 'leaders':
		case 'activities':
			$template = '{{content}}';
			break;
		default:
			$template = file_get_contents(__DIR__."/lib/template/$template.html");
	}

	foreach ($vars as $k => $v) {
		$search[] = '{{'.$k.'}}';
		$replace[] = $v;
	}

	return str_replace($search, $replace, $template);
});

// Set league sports and scoring rules

// Example of using km as distance unit
//$scoreboard->distanceUnit = array('KM' => 1000);

// Define sports (activity types) to include and scoring rules
$scoreboard->setSport('Ride', array('multiplyScoreBy' => 0.25));
$scoreboard->setSport('Run',  array('maxSpeed' => 15.0));
$scoreboard->setSport('Walk', array('label' => 'Walk/Hike', 'maxSpeed' => 8.0));
$scoreboard->setSport('Hike', array('convertTo' => 'Walk'));
//$scoreboard->setSport('VirtualRun', array('label' => 'Treadmill', 'distanceLimit' => 4.0));

// Permit manual activities w/o GPS data. Be sure to enable this for VirtualRun, VirtualRide.
//$scoreboard->allowManual = true;

// Example of adding activity IDs to whitelist so that they are scored even if they exceed speed, duration checks
//$scoreboard->whitelistActivity('3f9aec4c0ca125a71347c1fbffc4743b25d48347');

// Load downloaded activity from disk and calculate totals/scores
$scoreboard->loadActivityData();

// Write index.html with main scoreboard
file_put_contents(__DIR__.'/htdocs/index.html', $scoreboard->getScoreboardHTML());

// Write html files for every person in every club
foreach ($scoreboard->getResults() as $clubId => $results)
	foreach (array_keys($results['athletes']) as $name)
		file_put_contents($scoreboard->getPersonHTMLFilename(__DIR__.'/htdocs', $clubId, $name), $scoreboard->getPersonHTML($clubId, $name));

// Example of writing activity export.csv
//file_put_contents(__DIR__.'/export.csv', $scoreboard->getCSV());

# vim: tabstop=4:shiftwidth=4:noexpandtab
