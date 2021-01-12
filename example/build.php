<?php

declare(strict_types=1);

use picasticks\Strava\Club;
use picasticks\Strava\ClubTracker;

require_once 'lib/vendor/autoload.php';

// Set a TZ for date calculations
date_default_timezone_set('America/New_York');

$tracker = new ClubTracker(new Club(__DIR__.'/json'));

// Set league sports and display rules

// Uncomment to use km as distance unit instead of miles
//$tracker->distanceUnit = array('KM' => 1000);

// Define sports (activity types) to include, and total/counting rules
$tracker->setSport('Ride', array('distanceMultiplier' => 0.25));
$tracker->setSport('Run',  array('maxSpeed' => 15.0));
$tracker->setSport('Walk', array('label' => 'Walk/Hike', 'maxSpeed' => 8.0));
$tracker->setSport('Hike', array('convertTo' => 'Walk'));
// Uncomment to add VirtualRun as "Treadmill" with a limit of 5 miles per activity
//$tracker->setSport('VirtualRun', array('label' => 'Treadmill', 'distanceLimit' => 5.0));

// Uncomment to permit manual activities w/o GPS data. Be sure to enable this for VirtualRun, VirtualRide.
//$tracker->allowManual = true;

// Example of whitelisting an activity ID, so that it is counted even if fails speed, duration sanity/data quality checks
//$tracker->whitelistActivity('3f9aec4c0ca125a71347c1fbffc4743b25d48347');

// Specify an HTML template function
// This example one is super basic, it fills in {{variables}} in templates.
$tracker->setTemplateFunction(function (array $vars, string $template): string {
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

// Load downloaded activity from disk and calculate totals for sports as defined above
$tracker->loadActivityData();

// Write index.html with main summary tables
file_put_contents(__DIR__.'/htdocs/index.html', $tracker->getSummaryHTML());

// Write html files for every person in every club
foreach ($tracker->getResults() as $clubId => $results)
	foreach (array_keys($results['athletes']) as $name)
		file_put_contents($tracker->getPersonHTMLFilename(__DIR__.'/htdocs', $clubId, $name), $tracker->getPersonHTML($clubId, $name));

// Uncomment to write activity export.csv
//file_put_contents(__DIR__.'/export.csv', $tracker->getCSV());

# vim: tabstop=4:shiftwidth=4:noexpandtab
