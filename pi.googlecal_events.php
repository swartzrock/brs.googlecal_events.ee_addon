<?php

$plugin_info = array(
	'pi_name'			=> 'Google Calendar Events',
	'pi_version'		=> '1.0',
	'pi_author'			=> 'Jason Swartz',
	'pi_author_url'		=> 'http://blackrocksoftware.com/',
	'pi_description'	=> 'Displays feed of events from Google Calendars',
	'pi_usage'			=> Googlecal_events::usage()
);

// Add the Zend Google Data API
require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Gdata');
Zend_Loader::loadClass('Zend_Gdata_AuthSub');
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
Zend_Loader::loadClass('Zend_Gdata_HttpClient');
Zend_Loader::loadClass('Zend_Gdata_Calendar');

					
/**
 * Googlecal_events Class
 *
 * @package			ExpressionEngine
 * @category		Plugin
 * @author			Jason Swartz
 * @link			http://blackrocksoftware.com/
 */
class Googlecal_events {
	
	const DEFAULT_MAX_CACHE_AGE 		= 900; // default: cache events for 15 minutes
	const CACHE_DIR_NAME				= "googlecal_events_cache";
	
	const START_DAY_VAR 				= "start_day";
	const START_DAY_FORMAT_VAR			= "start_day_format";
	const START_TIME_VAR 				= "start_time";
	const END_TIME_VAR 					= "end_time";
	const WHAT_VAR 						= "what";
	const WHERE_VAR 					= "where";
	const DESCRIPTION_VAR 				= "description";
	
	
	// Input params
	var $google_calendars;
	var $user;
	var $password;
	var $max_cache_age;
	
	
	// Other things
	var $cacheDir;
	var $calendar_start_date;
	var $calendar_end_date;
	var $events;
	
	
	// Output data
	var $return_data;
	
	
	
	// Constructor for Googlecal_events class
	function Googlecal_events()
	{
		global $TMPL;
		ob_start(); 
		
		$this->cacheDir = PATH_CACHE . Googlecal_events::CACHE_DIR_NAME . "/";
		
		$this->parseInputParams();
		$this->loadEvents();
		
		$this->renderEvents();
	}
	
	
	// Parse the input parameters into class fields
	function parseInputParams() {
		
		global $TMPL;
		
		
		$param = $TMPL->fetch_param( "paramName" );
		if ( $param == FALSE ) { $this->log("param is false!"); }
		
		
		
		
		// Get the required params
		$google_calendars_param = $this->getParam( "google_calendars" );
		$this->user = $this->getParam( "user" );
		$this->password = $this->getParam( "password" );
		$num_days = $this->getParam( "num_days" );
		if ( !$google_calendars_param || !$this->user || !$this->password || !$num_days ) {
			$this->log("pi.googlecal_events error - insufficient input given.");
			$this->return_data = '';
			return;
		}
		
		$this->google_calendars = explode( ',', $google_calendars_param );
		
		
		// Calc the start and end times
		$this->calendar_start_date = date('Y-m-d');
		$secPerDay = 86400;
		$this->calendar_end_date = date('Y-m-d', (mktime() + $num_days * $secPerDay) );
		
		
		// Get the optional params
		$this->max_cache_age = $this->getParamOrDefault( "max_cache_age", Googlecal_events::DEFAULT_MAX_CACHE_AGE );
		$this->log( "max_cache_age param = " . $this->getParamOrDefault( "max_cache_age", "nada") );
	}
	
	
	// Get a {googlecal_events} param, or return the defaultValue if not present
	function getParamOrDefault($paramName, $defaultValue) {
		global $TMPL;
		$param = $TMPL->fetch_param( $paramName );
		// $this->log( "getParam(" . $paramName . ") = " . $param );
		if ($param == FALSE) { $param = $defaultValue; }
		return $param;
	}
	
	
	// Get a {googlecal_events} param or '' if the param wasn't found
	function getParam($paramName) {
		return $this->getParamOrDefault( $paramName, '' );
	}
	
	
	// Log a message to EE and to the PHP error log
	function log($msg) {
		global $TMPL;
		$TMPL->log_item( $msg );
		error_log( $msg );
	}
	
	
	// Load the events from the google calendars
	function loadEvents() {

		$this->readCachedEvents();
		if ( $this->events ) {
			return;
		}
		
		$this->loadEventsFromFeed();
		$this->writeEventsToCache();
	}

	
	// Parse the event feed from the Gdata API
	function parseEventFeed($eventFeed) {
		
		$events = array();
		
		foreach ($eventFeed as $event) {
			
			// Ignore cancelled events
			if ( $event->eventStatus == "http://schemas.google.com/g/2005#event.canceled" ) {
				continue;
			}
			
			$name = $event->title;
			$where = $event->where[0];
			$description = $event->content;
			foreach( $event->when as $when ) {
				$startTime = strtotime( $when->startTime );
				$endTime = strtotime( $when->endTime );
				$events[] = new Event( $name, $startTime, $endTime, $where, $description );
			}
		}
		
		return $events;	
	}
	
	
	// Read the events from the feed
	function loadEventsFromFeed() {
		
		// Create the Calendar client
		$service = Zend_Gdata_Calendar::AUTH_SERVICE_NAME;
		try {
			$client = Zend_Gdata_ClientLogin::getHttpClient($this->user, $this->password, $service);
		} catch (Exception $ex) {
			$this->log( "pi.googlecal_events, caught this trying to create a google data api client: " . $ex->getMessage() );
			return;
		}
		
		$calendar = new Zend_Gdata_Calendar($client);
		$query = $calendar->newEventQuery();
		$query->setVisibility('private');
		$query->setProjection('full');
		$query->setStartMin($this->calendar_start_date);
		$query->setStartMax($this->calendar_end_date);
		
		
		$this->events = array();
		foreach ($this->google_calendars as $google_calendar) {
			
			$query->setUser( $google_calendar );
			
			try {
				$eventFeed = $calendar->getCalendarEventFeed( $query );
			} catch (Exception $ex) {
				$this->log( "pi.googlecal_events, caught this trying to get the calendar feed: " . $ex->getMessage() );
				return;
			}
			
			$newEvents = $this->parseEventFeed( $eventFeed );
			$this->events = array_merge( $this->events, $newEvents );
		}
		
		
		// Sort the list of events
		usort( $this->events, "eventCmp" );
	}
	
	
	// Read the events from a file cache
	function readCachedEvents() {
		
		if ( ! is_dir($this->cacheDir) ) {
			return;
		}

		$cacheFileName = $this->cacheDir . $this->createCacheKey();
		if( ! file_exists($cacheFileName) ) {
			return;
		}

		$cacheAge = time() - filemtime( $cacheFileName );
		if ( $cacheAge > $this->max_cache_age ) {
			return;
		}
		
		$this->log("Cache age (" . $cacheAge . ") is not bigger than " . $this->max_cache_age);
		

		$fp = fopen($cacheFileName, "rb");
		if ( ! $fp ) {
			$this->log( "pi.googlecal_events, unable to open cache file $cacheFileName for some reason" );
			return;
		}

		$contents = fread( $fp, filesize($cacheFileName) );
		fclose( $fp );

		$this->events = unserialize( $contents );
	}

	
	// Write the events to a file cache
	function writeEventsToCache() {
		
		// Create the directory if it doesn't exist
		if ( ! is_dir($this->cacheDir) ) {
			mkdir( $this->cacheDir );
			if ( ! is_dir($this->cacheDir) ) {
				$this->log( "pi.googlecal_events, unable to create cache dir " . $this->cacheDir );
				return;
			}
		}
		
		$cacheFileName = $this->cacheDir . $this->createCacheKey();
		
		$fp = @fopen($cacheFileName, "wb");
		if ( ! $fp ) {
			$this->log( "pi.googlecal_events, unable to open cache file $cacheFileName for writing." );
			return;
		}

		$serializedEvents = serialize( $this->events );
		if (flock($fp, LOCK_EX)) {
		    ftruncate($fp, 0);
		    fwrite($fp, $serializedEvents);
		    flock($fp, LOCK_UN);
		}
		else {
			$this->log( "pi.googlecal_events, unable to get a file lock writing $cacheFileName." );
		}

	}
	
	
	// Create the unique key for this cache
	function createCacheKey() {
		$calendars = implode( ",", $this->google_calendars );
		return md5( "{$this->calendar_start_date}__{$this->calendar_end_date}__{$calendars}" );
	}
	
	
	// Create a map from the specifc date format tag to a list of the date format tags it contains
	function buildDateFormatTagToDateParamMap($tagName) {
		
		global $TMPL, $LOC;
		
		$result = array();
		
		if (preg_match_all("/".LD."(" . $tagName . ")\s+format=(\042|\047)([^\\2]*?)\\2".RD."/s", $TMPL->tagdata, $matches))
		{
		    for ($i = 0; $i < count($matches['0']); $i++)
		    {
		        $key = str_replace(array(LD,RD), '', $matches['0'][$i]);
				$dateParams = $matches['3'][$i];
				$dateParamsList = $LOC->fetch_date_params($dateParams);
				
				// Create a map from the tag to the list of individual date format tags therein
				$result[$key] = $dateParamsList;
		    }	
		}
		
		return $result;
	}
	
	
	// Render the events in this template
	function renderEvents() {
		
		global $TMPL, $LOC, $SESS;

		
		if ( $this->events == null ) {
			return;
		}
		
		$startDayToDateFormatsMap = $this->buildDateFormatTagToDateParamMap( Googlecal_events::START_DAY_FORMAT_VAR );
		$startTimeToDateFormatsMap = $this->buildDateFormatTagToDateParamMap( Googlecal_events::START_TIME_VAR );
		$endTimeToDateFormatsMap = $this->buildDateFormatTagToDateParamMap( Googlecal_events::END_TIME_VAR );
		
		$startDayCache = array();
		
		$output = "";
		
		foreach( $this->events as $event ) {
			
			$isAllDayEvent = ($LOC->decode_date('%H%i', $event->startTime, false) == '0000');
			
			$startDayCacheKey = $LOC->decode_date('%z%Y', $event->startTime, false);
			
			// Get the text inside the tag fresh for each iteration
			$tagdata = $TMPL->tagdata;
			
			foreach($TMPL->var_single as $key => $val) {
				
				
				if ( isset($startTimeToDateFormatsMap[$key]) ) {
					
					// Render each date type in the format string (conveniently placed in $val)
					foreach($startTimeToDateFormatsMap[$key] as $dateFormat) {
						$dateOutput = $LOC->decode_date($dateFormat, $event->startTime, false);
						$val = str_replace($dateFormat, $dateOutput, $val);
					}
					
					// If this is an all-day event, don't render the time field!
					if ( $isAllDayEvent ) { $val = ""; }
					
					// Replace this entire tag with the rendered date format in $val
					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);
				}
				
				else if ( isset($endTimeToDateFormatsMap[$key]) ) {
					
					// Render each date type in the format string (conveniently placed in $val)
					foreach($endTimeToDateFormatsMap[$key] as $dateFormat) {
						$dateOutput = $LOC->decode_date($dateFormat, $event->endTime, false);
						$val = str_replace($dateFormat, $dateOutput, $val);
					}
					
					// If this is an all-day event, don't render the time field!
					if ( $isAllDayEvent ) { $val = ""; }
					
					// Replace this entire tag with the rendered date format in $val
					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);
				}
				
				
				else if( $key == Googlecal_events::WHAT_VAR ) {
					$tagdata = $TMPL->swap_var_single($key, $event->name, $tagdata);
				}
				
				else if( $key == Googlecal_events::WHERE_VAR ) {
					$tagdata = $TMPL->swap_var_single($key, $event->where, $tagdata);
				}
				
				else if( $key == Googlecal_events::DESCRIPTION_VAR ) {
					$tagdata = $TMPL->swap_var_single($key, $event->description, $tagdata);
				}
				
			}
			
			
			// Handle the variable pairs (currently only {start_day}..{/start_day}
			foreach($TMPL->var_pair as $key => $val) {
				
				if ( $key == "start_day" ) {
	    			
					// Make sure there's a matched pair
					$matchedPair = preg_match("/".LD."$key".RD."(.*?)".LD.SLASH."$key".RD."/s", $TMPL->tagdata, $matches);
					if ($matchedPair) {
						
						// If we've handled this day already delete everything between the pair, otherwise just remove the pair tags
						$toss = isset($startDayCache[$startDayCacheKey]);
						if ( $toss ) {
							$tagdata = $TMPL->delete_var_pairs($key, $key, $tagdata);
						}
						else {
							$tagdata = $TMPL->swap_var_pairs($key, $key, $tagdata);
						}
					}
				}
				
				
				// Handle the start_day_format formatting
				if ( isset($startDayToDateFormatsMap[$key]) ) {
					
					if ( isset($startDayCache[$startDayCacheKey]) ) {
						$tagdata = $TMPL->swap_var_single($key, "", $tagdata);
					}
					
					else {
						// Save this date in the cache so this tag is ignored if called for the same date
						$startDayCache[$startDayCacheKey] = $startDayCacheKey;
						
						$formatString = $val["format"];
						
						// Render each date type in the format string (conveniently placed in $formatString)
						foreach($startDayToDateFormatsMap[$key] as $dateFormat) {
							$dateOutput = $LOC->decode_date($dateFormat, $event->startTime, false);
							$formatString = str_replace($dateFormat, $dateOutput, $formatString);
						}
						// Replace this entire tag with the rendered date format in $formatString
						$tagdata = $TMPL->swap_var_single($key, $formatString, $tagdata);
					}
					
				}
				
			}
			
			$output .= $tagdata;
		}
		$this->return_data = $output;
	}
	

	
	

	function usage()
	{
		ob_start(); 
		?>
		------------------
		EXAMPLE USAGE:
		------------------
		{exp:googlecal_events google_calendars="..." user="..." password="..." num_days="..." }
		{start_time format="%g:%i %a"}: {what} ({where})<br>
		{/exp:googlecal_events}
		
		{exp:googlecal_events google_calendars="..." user="..." password="..." num_days="..." }
		{start_day}<div class="day">{start_day_format format="%l, %F %j %Y"}</div>{/start_day}
		{start_time format="%g:%i %a"}: {what} ({where})<br>
		{description}<br>
		{/exp:googlecal_events}
		
		------------------
		PARAMETERS:
		------------------
		google_calendars=""
		- The google calendars to display, formatted as a comma-delimited list of calendar id's.
		- Example: google_calendars="db6v89hrjqi72ht6mjfcacij80@group.calendar.google.com"
		- Example: google_calendars="db6v89hrjqi72ht6mjfcacij80@group.calendar.google.com,5aa42b61ffhjubloa703tkes48@group.calendar.google.com"
		
		
		user = ""
		- The user id of the Google API-Enabled account to use
		- Example: gdataApiUser@gmail.com
		
		password = ""
		- The password of the Google API-Enabled account to use
		
		num_days = ""
		- The number of days to display, starting from the current date.
		
		max_cache_age = ""  [OPTIONAL]
		- The maximum time in seconds to cache the calendar feed locally
		- If not specified, the default time of 15 minutes will be used
		
		------------------
		VARIABLES:
		------------------
		{start_day format="<date format tags>"}
		- Displays the start time of the event formatted using EE date tags
		- Only appears once a day for the first event each day
		- Makes it possible to show a single Day header for multiple events (see second example above)
		
		{start_time format="<date format tags>"}
		- Displays the start time of the event formatted using EE date tags
		- Unlike start_day, appears for every event
		- Example: use start_day to show the date and start_time to show the start time
		
		{end_time format="<date format tags>"}
		- Displays the end time of the event formatted using EE date tags
		
		{what}
		- Displays the "what" field of the event, aka the event title
		
		{where}
		- Displays the "where" field of the event
		
		{description}
		- Displays the full description of the event

		<?php
		$buffer = ob_get_flush();

		ob_end_clean(); 

		return $buffer;
	}

}



/**
 * Represents a single event instance on a Google calendar
 */
class Event {
	var $name;
	var $startTime;
	var $endTime;
	var $where;
	var $description;
	
	public function Event($name, $startTime, $endTime, $where, $description) {
		$this->name = $name;
		$this->startTime = $startTime;
		$this->endTime = $endTime;
		$this->where = $where;
		$this->description = $description;
	}
}


// Compare events, sorting by time and then event name
function eventCmp($event1, $event2) {
	if ( $event1->startTime == $event2->startTime ) {
		return strcmp( $event1->name, $event2->name );
	}
	return $event1->startTime - $event2->startTime;
}







