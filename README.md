# Strava Club Tracker

Strava club progress tracker/dashboard generator, uses [StravaPHP](https://github.com/basvandorst/StravaPHP/) to interface with the Strava API and total club members' stats. Generates views including club totals, individual leaders, club rosters and individuals' activity details.

Here's an [example screenshot](example/index.png?raw=true) of a main summary page, and of a club member's [activity detail](example/person_detail.png?raw=true) page.

## Why?

As a charity fundraiser, a group I'm in held a month-long exercise challenge organized with multiple teams, and decided to use Strava Clubs to track each team's progress toward a group mileage goal. While Strava is great for tracking individual efforts, sharing photos etc., and the clubs were a great way to keep everyone motivated and organize group rides and walks, the default club views were too limited and didn't work for us. For example, totals did not include all activity types (they exclude Hike, Walk etc.), and we wanted to count various activity types differently (a 1-km swim is a lot harder than a 1-km ride!), and to see the whole group and all the clubs together as a single dashboard.

## Main features

* Track one club or a group of clubs
* Club totals by activity and across activities
* Highlights top efforts
* Highlights individual leaders
* Club rosters and individual activity details, so participants can confirm at a glance that their activities were included
* Generates HTML output (swappable template callback)
* Configurable rules:
   * Configure distance units (miles, km, meters etc.)
   * Count mileage differently for each activity (e.g. 2x for Swim, 0.25x for Ride)
   * Combine multiple activity types (e.g. combine "Walk" and "Hike", or "Ride" and "VirtualRide")
   * Customize activity labels
   * Data quality/sanity checks for speed and duration, for when people inevitably forget to stop Strava and get in a car or a sofa. Changes 2 mph "runs" to walks, etc.
* Class methods to return totals and structured data
* CSV export

## Quick start

This library includes a simple example implementation. In Strava, you'll need to have at least one club set up, with club members and completed activities (rides, runs, etc.). You'll need Strava API credentials.

Next, to bootstrap the example:

1. Copy the `example` directory and its contents to a new project directory.

2. Change to the `lib` directory and use [Composer](https://getcomposer.org/) to install the library and its dependencies:

```
cd lib
composer install
```

3. Edit [`htdocs/example_update.php`](example/htdocs/example_update.php) and set the list of Strava Club IDs, start and end date, Strava API credentials and https callback URI. It's best to start by testing with a start/end period of just a few days.

4. Load `example_update.php` via https in a browser and click the link. It will obtain OAuth authorization from Strava and use the Strava API to download club data to the `json` directory.

5. Edit [`build.php`](example/build.php) and make any changes you like. By default, this script uses miles as distance unit and includes Ride, Run, Walk and Hike activities.

6. Run `build.php` from the CLI to generate HTML files into `htdocs/`.

```
php build.php
```

Needless to say, this example application isn't production quality and doesn't include access or authorization controls, it's meant for demo use only! It's split into two parts so that you can edit and rerun `build.php` offline many times to play with the generator functionality and syntax.

## A note on Strava permissions and club/person/activity visibility

Strava manages visibility of club details, members and activities according to its security and privacy policies. In order for club members to be included and their activities counted, each member must make their activities visible to your application's user. For example, a member could make activities visible to all users (currently, Strava's default setting), or only to Followers, and then accept your user as a Follower.

If a club member's activities don't show up, they should check their visibility settings in Strava and make sure they've made those activities visible to the club member user running your application.

## Legal

This software library is provided under the terms of the GNU GPL. See [LICENSE](LICENSE?raw=true) for details.

This library is designed to be used by applications that comply with [Strava's Terms of Service](https://www.strava.com/legal/terms) and other terms, including its privacy policy and API agreement. Of note, Strava's [API Agreement](https://www.strava.com/legal/api) states that the API should not be used to enable virtual races or competitions, or to replicate Strava sites, services or products. While I don't think this library would be particularly useful for any of those, if you're thinking of adapting it for a non-permitted use, please don't.

<hr />

# Class documentation

## picasticks\Strava

* [Club](#picasticksstravaclub)
* [ClubTracker](#picasticksstravaclubtracker)

## picasticks\Strava\Club

### Methods

| Name | Description |
|------|-------------|
|[__construct](#club__construct)|Constructor|
|[downloadClub](#clubdownloadclub)|Downloads club details from Strava|
|[downloadClubActivities](#clubdownloadclubactivities)|Downloads club activity data from Strava|
|[getClubFilenames](#clubgetclubfilenames)|Get array of club data files|
|[getDataFilenames](#clubgetdatafilenames)|Get map of club activity data files and timestamps|
|[getRequestCount](#clubgetrequestcount)|Get count of current number of API requests to Strava|
|[log](#clublog)|Log message using $this-logger|
|[setClient](#clubsetclient)|Set Strava API Client instance|

#### Club::__construct

**Description**

```php
public __construct (string $storageDir)
```

Constructor

**Parameters**

* `(string) $storageDir`
: filesystem directory to store downloaded JSON files

**Return Values**

`void`

<hr />

#### Club::downloadClub

**Description**

```php
public downloadClub (int $clubId)
```

Downloads club details from Strava

**Parameters**

* `(int) $clubId`
: Club ID

**Return Values**

`void`

<hr />

#### Club::downloadClubActivities

**Description**

```php
public downloadClubActivities (int $clubId, int $start, int $end)
```

Downloads club activity data from Strava

**Parameters**

* `(int) $clubId`
: Club ID
* `(int) $start`
: Start date (UNIX timestamp)
* `(int) $end`
: End date (UNIX timestamp)

**Return Values**

`void`

<hr />

#### Club::getClubFilenames

**Description**

```php
public getClubFilenames (void)
```

Get array of club data files

**Parameters**

`This function has no parameters.`

**Return Values**

`array`

> of filenames

<hr />

#### Club::getDataFilenames

**Description**

```php
public getDataFilenames (int $clubId)
```

Get map of club activity data files and timestamps

**Parameters**

* `(int) $clubId`
: Club ID

**Return Values**

`array`

> array('filename' => timestamp)

<hr />

#### Club::getRequestCount

**Description**

```php
public getRequestCount (void)
```

Get count of current number of API requests to Strava

**Parameters**

`This function has no parameters.`

**Return Values**

`int`

> request count

<hr />

#### Club::log

**Description**

```php
public log (string $msg, int|null $error_type)
```

Log message using $this-logger

**Parameters**

* `(string) $msg`
: message
* `(int|null) $error_type`
: (optional) PHP error type

**Return Values**

`void`

<hr />

#### Club::setClient

**Description**

```php
public setClient (Client $client)
```

Set Strava API Client instance

**Parameters**

* `(Client) $client`
: instance

**Return Values**

`void`

<hr />

## picasticks\Strava\ClubTracker

### Methods

| Name | Description |
|------|-------------|
|[__construct](#clubtracker__construct)|Constructor|
|[getCSV](#clubtrackergetcsv)|Returns all activity data in CSV format|
|[getClubHTML](#clubtrackergetclubhtml)|Returns HTML club roster and totals for a club|
|[getClubs](#clubtrackergetclubs)|Return array of clubs and club attributes|
|[getPersonHTML](#clubtrackergetpersonhtml)|Returns HTML activty log for a single athlete|
|[getPersonHTMLFilename](#clubtrackergetpersonhtmlfilename)|Get filesystem path for HTML page showing person activity details|
|[getResults](#clubtrackergetresults)|Return hierarchical data structure of all activities grouped by club and athlete|
|[getSportLeaders](#clubtrackergetsportleaders)|Get ranked list of leaders for a sport/activity type|
|[getSportLeadersHTML](#clubtrackergetsportleadershtml)|Returns HTML table of leaders for a sport|
|[getSummaryHTML](#clubtrackergetsummaryhtml)|Returns main HTML summary tables|
|[getTopActivities](#clubtrackergettopactivities)|Get ranked list of top activities|
|[getTopActivitiesHTML](#clubtrackergettopactivitieshtml)|Returns HTML table of top performances for a sport/activity type|
|[getTotal](#clubtrackergettotal)|Get total distance, total or moving time|
|[getTotals](#clubtrackergettotals)|Get total distance, total and moving time|
|[loadActivityData](#clubtrackerloadactivitydata)|Load activity data from disk (downloaded JSON responses)|
|[setSport](#clubtrackersetsport)|Add or set a sport, including label and totaling rules|
|[setTemplateFunction](#clubtrackersettemplatefunction)|Set template function|
|[whitelistActivity](#clubtrackerwhitelistactivity)|Add activity to activity whitelist|

#### ClubTracker::__construct

**Description**

```php
public __construct (Club $data)
```

Constructor

**Parameters**

* `(Club) $data`
: Club instance object

**Return Values**

`void`

<hr />

#### ClubTracker::getCSV

**Description**

```php
public getCSV (void)
```

Returns all activity data in CSV format

Includes header row

**Parameters**

`This function has no parameters.`

**Return Values**

`string`

> CSV-formatted data export

<hr />

#### ClubTracker::getClubHTML

**Description**

```php
public getClubHTML (int $clubId)
```

Returns HTML club roster and totals for a club

Applies template name 'club'

**Parameters**

* `(int) $clubId`
: Club ID

**Return Values**

`string`

> HTML

<hr />

#### ClubTracker::getClubs

**Description**

```php
public getClubs (void)
```

Return array of clubs and club attributes

**Parameters**

`This function has no parameters.`

**Return Values**

`array`

> of (int) clubId => (array) club attributes

<hr />

#### ClubTracker::getPersonHTML

**Description**

```php
public getPersonHTML (int $clubId, string $person)
```

Returns HTML activty log for a single athlete

Applies template name 'person'

**Parameters**

* `(int) $clubId`
: Club ID
* `(string) $person`
: person name

**Return Values**

`string`

> HTML

<hr />

#### ClubTracker::getPersonHTMLFilename

**Description**

```php
public getPersonHTMLFilename (string $baseDir, int $clubId, string $person)
```

Get filesystem path for HTML page showing person activity details

**Parameters**

* `(string) $baseDir`
: Filesystem base directory
* `(int) $clubId`
: Club ID
* `(string) $person`
: person name

**Return Values**

`string`

> filename

<hr />

#### ClubTracker::getResults

**Description**

```php
public getResults (void)
```

Return hierarchical data structure of all activities grouped by club and athlete

**Parameters**

`This function has no parameters.`

**Return Values**

`array`

> of activity data

<hr />

#### ClubTracker::getSportLeaders

**Description**

```php
public getSportLeaders (string $sport)
```

Get ranked list of leaders for a sport/activity type

**Parameters**

* `(string) $sport`
: sport ID

**Return Values**

`array`

> of: total distance, clubId, person name

<hr />

#### ClubTracker::getSportLeadersHTML

**Description**

```php
public getSportLeadersHTML (string $sport, int $limit)
```

Returns HTML table of leaders for a sport

Applies template name 'leaders'

**Parameters**

* `(string) $sport`
: sport ID
* `(int) $limit`
: (optional) number of athletes to include (defaults to top 5)

**Return Values**

`string`

> HTML

<hr />

#### ClubTracker::getSummaryHTML

**Description**

```php
public getSummaryHTML (void)
```

Returns main HTML summary tables

Includes standings, top individual performances, club totals

Applies template name 'index'

**Parameters**

`This function has no parameters.`

**Return Values**

`string`

> HTML

<hr />

#### ClubTracker::getTopActivities

**Description**

```php
public getTopActivities (int $clubId, string $person, string $sport)
```

Get ranked list of top activities

Optionally filter by club, person and sport

**Parameters**

* `(int) $clubId`
: (optional) Club ID
* `(string) $person`
: (optional) person name
* `(string) $sport`
: (optional) sport ID

**Return Values**

`array`

> of activity data: total, distance, clubId, person name, date, activity name, sport

<hr />

#### ClubTracker::getTopActivitiesHTML

**Description**

```php
public getTopActivitiesHTML (string $sport, int $limit)
```

Returns HTML table of top performances for a sport/activity type

Applies template name 'activities'

**Parameters**

* `(string) $sport`
: sport ID
* `(int) $limit`
: (optional) number of athletes to include (defaults to top 5)

**Return Values**

`string`

> HTML

<hr />

#### ClubTracker::getTotal

**Description**

```php
public getTotal (string $type, int $clubId, string $person, string $sport)
```

Get total distance, total or moving time

Optionally filter by club, person and sport

**Parameters**

* `(string) $type`
: 'distance' 'total' or 'moving_time'
* `(int) $clubId`
: (optional) Club ID
* `(string) $person`
: (optional) person name
* `(string) $sport`
: (optional) sport ID

**Return Values**

`mixed`

> (float) distance or total, (int) moving_time

<hr />

#### ClubTracker::getTotals

**Description**

```php
public getTotals (int $clubId, string $person, string $sport)
```

Get total distance, total and moving time

Optionally filter by club, person and sport

**Parameters**

* `(int) $clubId`
: (optional) Club ID
* `(string) $person`
: (optional) person name
* `(string) $sport`
: (optional) sport ID

**Return Values**

`array`

> of: distance, total, moving_time totals

<hr />

#### ClubTracker::loadActivityData

**Description**

```php
public loadActivityData (void)
```

Load activity data from disk (downloaded JSON responses)

Calculates totals and stores as hierarchical data structure of all activities grouped by club and athlete.

Sets $this->start and $this->end using activity dates.

**Parameters**

`This function has no parameters.`

**Return Values**

`void`

<hr />

#### ClubTracker::setSport

**Description**

```php
public setSport (string $sportId, array $attributes)
```

Add or set a sport, including label and totaling rules

Attributes may include:

string $label (optional) to use for sport name in formatted output (if not set, $sportId is used).

string $convertTo (optional) sport ID of another sport to which this sport ID's activities should be converted. Use to combine multiple Strava sports together for simplified reporting, e.g. to merge "Walk" and "Hike".

float $distanceMultiplier (optional) Multiplier to apply to distance to compute adjusted total. e.g. setting Ride to 0.25 and Walk to 1 means each Walk mile is counted the same as 4 Ride miles.

float $maxSpeed (optional) Maximum speed for a single activity for a sport, in distance units per hour. Activities that exceed this limit are counted as 0 (the user should edit them in Strava and either set the correct activity type, or edit the activity to remove distance covered in a vehicle).

float $distanceLimit (optional) Hard distance limit for a single activity for a sport. Activities that exceed this limit are counted up to the distanceLimit.

**Parameters**

* `(string) $sportId`
: sport ID
* `(array) $attributes`
: (optional)

**Return Values**

`void`

<hr />

#### ClubTracker::setTemplateFunction

**Description**

```php
public setTemplateFunction (callable $function)
```

Set template function

**Parameters**

* `(callable) $function`
: callable to apply array of template variables to template

**Return Values**

`void`

<hr />

#### ClubTracker::whitelistActivity

**Description**

```php
public whitelistActivity (string $id)
```

Add activity to activity whitelist

Whitelisted activities are always counted, bypassing sanity checks

**Parameters**

* `(string) $id`
: activity ID

**Return Values**

`void`

<hr />
