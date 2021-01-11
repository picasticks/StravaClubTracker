# Strava Club Tracker

Strava club progress tracker/dashboard generator, uses [StravaPHP](https://github.com/basvandorst/StravaPHP/) to interface with the Strava API and total club members' stats. Generates views including club totals, individual leaders, club rosters and individuals' activity details.

Here's an [example screenshot](example/index.png?raw=true) of a main summary page, and of a club member's [activity detail](example/person_detail.png?raw=true) page.

## Why?

As a charity fundraiser, a group I'm in held a month-long exercise challenge organized with multiple teams, and decided to use Strava Clubs to track each team's progress toward a group mileage goal. While Strava is great for tracking individual efforts, sharing photos etc., and the clubs were a great way to keep everyone motivated and organize group rides and walks, the default club views were too limited and didn't work for us. For example, totals did not include all activity types (they exclude Hike, Walk etc.), and we wanted to count various activity types differently (a 1-km swim is a lot harder than a 1-km ride!), and to see the whole group and all the clubs together as a single dashboard.

## Main features

* Club totals by activity and across activities
* Highlight top efforts
* Highlight individual leaders
* Club rosters and individual activity details so participants can confirm at a glance that their activities were included
* Configurable rules:
   * Configure distance units (miles, km, meters etc.)
   * Combine multiple activity types (e.g. combine "Walk" and "Hike", or "Ride" and "VirtualRide")
   * Customize activity labels
   * Count mileage differently for each activity (e.g. 2x for swim, 0.25x for ride)
   * Data quality/sanity checks for speed and duration when people inevitably forget to stop Strava and get in a car or a sofa, change 2 mph "Runs" to Walks etc.
* Generates HTML output with swappable template callback
* Class methods to return totals and structured data, which can be easily json_encoded for alternate visualization
* CSV export

## Quick start

This library includes a simple example implementation. In Strava, you'll need to have at least one club set up, with club members and completed activities (rides, runs, etc.). You'll need Strava API credentials.

To bootstrap the example:

1. Copy the `example` directory to a new project directory.

2. Change to the `lib` directory and use [composer](https://getcomposer.org/) to install the library and dependencies:

```
cd lib
composer install
```

3. Edit `htdocs/example_update.php` and set the list of Strava Club IDs, start and end date, Strava API credentials and https callback URI. It's best to start with a start/end period of just a few days.

4. Load `example_update.php` via https in a browser and click the link. It will obtain OAuth authorization from Strava and use the Strava API to download club data to the `json` directory.

5. Edit `build.php` and make any changes you like. By default, this script uses miles as its distance unit and includes Ride, Run, Walk and Hike activities.

6. Run `build.php` from the CLI to generate HTML files into `htdocs/`.

```
php build.php
```

Needless to say, this example application isn't production quality and doesn't include access or authorization controls, it's meant for demo use only. It's split into two parts so that you can edit and rerun `build.php` offline many times to explore the library's functionality.

## Strava API permissions and club/person/activity visibility

Strava manages visibility of club details, members and activities according to its security and privacy policies. In order for club members to be included and their activities counted, each member must make their activities visible to your application's user. For example, a member could make activities visible to all users (currently, Strava's default setting), or only to Followers, and then accept your user as a Follower. If a club member's activities don't show up, they should check their visibility settings in Strava and make sure they've made those activities visible to the club member user running your application.

## Legal

This software library is provided under the terms of the GNU GPL. See [LICENSE](LICENSE?raw=true) for details.

This library is intended to be used only for applications that fully comply with [Strava's Terms of Service](https://www.strava.com/legal/terms) and other terms, including its privacy policy and API agreement. Of note, Strava's [API Agreement](https://www.strava.com/legal/api) states that the API should not be used to enable virtual races or competitions, or to replicate Strava sites, services or products. While I don't think this library would be particularly useful for any of those, if you're thinking of adapting it for a non-permitted use, please don't.

This isn't legal advice, but please consider carefully both Strava's policies and your club members' privacy and their preferences for the visibility of their information. For example, if a club member is only sharing activities with followers, their information must not be displayed to anyone else.
