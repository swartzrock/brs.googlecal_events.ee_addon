<?php

$plugin_info = array(
	'pi_name'			=> 'Google Calendar Events',
	'pi_version'		=> '1.0',
	'pi_author'			=> 'Jason Swartz',
	'pi_author_url'		=> 'https://github.com/swartzrock/brs.googlecal_events.ee_addon/',
	'pi_description'	=> 'Displays feed of events from Google Calendars',
	'pi_usage'			=> Googlecal_events::usage()
);

					
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
	const DEFAULT_MAX_EVENTS	 		= 30; // default: cache events for 15 minutes
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
	var $max_events;
	
	
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
		
		// Load the Zend Google Data API
		include_once 'Zend/Loader.php';
		if( ! class_exists("Zend_Loader") ) {
			$this->log( "Error: Zend Gdata API not found, unable to load events (pi.googlecal_events).");
			return;
		}
		
		Zend_Loader::loadClass('Zend_Gdata');
		Zend_Loader::loadClass('Zend_Gdata_AuthSub');
		Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
		Zend_Loader::loadClass('Zend_Gdata_HttpClient');
		Zend_Loader::loadClass('Zend_Gdata_Calendar');
		
		
		$this->cacheDir = PATH_CACHE . Googlecal_events::CACHE_DIR_NAME . "/";
		
		$success = $this->parseInputParams();
		if (! $success) {
			return;
		}
		
		$this->loadEvents();
		if ( $this->events == null ) {
			return;
		}
		
		// Trim the events as requested
		$this->events = array_slice( $this->events, 0, $this->max_events );
		
		$this->renderEvents();
	}
	
	
	
	// Parse the input parameters into class fields
	function parseInputParams() {
		
		global $TMPL;
		
		
		// Get the required params
		$google_calendars_param = $this->getParam( "google_calendars" );
		$this->user = $this->getParam( "user" );
		$this->password = $this->getParam( "password" );
		$num_days = $this->getParam( "num_days" );
		if ( $google_calendars_param == '' || $this->user == '' ||
		     $this->password == '' || $num_days == '' ) 
		{
			$this->log( "Error: Not enough params specified, exiting (pi.googlecal_events).");
			$this->return_data = '';
			return FALSE;
		}
		
		// Convert the comma-
		$this->google_calendars = explode( ',', $google_calendars_param );
		
		
		// Calc the start and end times
		$this->calendar_start_date = date('Y-m-d');
		$secPerDay = 86400;
		$this->calendar_end_date = date('Y-m-d', (mktime() + $num_days * $secPerDay) );
		
		
		// Get the optional params
		$this->max_cache_age = $this->getParamOrDefault( "max_cache_age", 
			Googlecal_events::DEFAULT_MAX_CACHE_AGE );
		$this->max_events = $this->getParamOrDefault( "max_events", 
			Googlecal_events::DEFAULT_MAX_EVENTS );
		
		
		return TRUE;
	}
	
	
	// Get a {googlecal_events} param, or return the defaultValue if not present
	function getParamOrDefault($paramName, $defaultValue) {
		global $TMPL;
		
		if ( ! isset($TMPL->tagparams[$paramName]) ) {
			return $defaultValue;
		}
		
		return $TMPL->tagparams[$paramName];
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
		
		// Try to read the events from a cached file
		$this->readCachedEvents();
		if ( $this->events ) {
			return;
		}
		
		$this->loadEventsFromFeed();
		if ( $this->events ) {
			$this->writeEventsToCache();
		}
		
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
		} 
		catch (Exception $ex) {
			$this->log( "Error: Caught this creating a Gdata client (pi.googlecal_events) : " . $ex->getMessage() );
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
			} 
			catch (Exception $ex) {
				$this->log( "Error: Caught this retrieving the Google Calendar feed (pi.googlecal_events) : " . $ex->getMessage() );
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
		
		$fp = fopen($cacheFileName, "rb");
		if ( ! $fp ) {
			$this->log( "Error: Unable to open cache file for reading (pi.googlecal_events).");
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
				$this->log( "Error: Unable to create cache dir " . $this->cacheDir . "  (pi.googlecal_events).");
				return;
			}
		}
		
		$cacheFileName = $this->cacheDir . $this->createCacheKey();
		
		$fp = @fopen($cacheFileName, "wb");
		if ( ! $fp ) {
			$this->log( "Error: Unable to open cache file for writing (pi.googlecal_events).");
			return;
		}

		$serializedEvents = serialize( $this->events );
		if (flock($fp, LOCK_EX)) {
		    ftruncate($fp, 0);
		    fwrite($fp, $serializedEvents);
		    flock($fp, LOCK_UN);
		}
		else {
			$this->log( "Error: unable to get a lock for writing to the cache file (pi.googlecal_events).");
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

# Description
The {exp:googlecal_events} tag displays a list of upcoming events in chronological order from one or more Google Calendars. Requres the free Zend Gdata package (http://framework.zend.com/download/gdata) and a working Google account to use.


# Parameters
google_calendars
    The Google Calendar id (eg "12345@group.calendar.google.com") or a comma-delimited list of id's.
user
    The username of your Google account (your Gmail address should work here).
password
    The password of your Google account.
num_days
    The number of days to display
max_cache_age [optional]
    The maximum age in seconds of the local file cache for the events. If not specified, the default value of 900 (15 minutes) will be used.
max_events [optional]
    Limit the number of items to next n entries. If not specified, the default maximum value of 30 events will be used.


# Single Variables
{start_time format="%Y %m %d"}
    The start time of the event
{end_time format="%Y %m %d"}
    The end time of the event
{what}
    The title of the event (from the "what" field in Google Calendar)
{where}
    The location of the event (from the "where" field in Google Calendar)
{description}
    The description of the event (from the "description" field in Google Calendar)


# Variable Pairs
{start_day} {start_day_format format="%Y %m %d"} {/start_day}
    The start time of the event, only displayed for the first event each day


# Example 1 - Simple List Of Events
{exp:googlecal_events google_calendars="<cal>" user="<user>" password="<password>" num_days="14" }
    {start_time format="%g:%i %a"}: {what} ({where})<br>
{/exp:googlecal_events}


# Example 2 - TV Shows This Week
{exp:googlecal_events google_calendars="3t2brro1crmt94uuqomrgipqiulm7fs7@import.calendar.google.com" 
    user="<user>" password="<password>" num_days="7" }
    {start_day}<div style="font: bold 14px Verdana;">{start_day_format format="%l, %M %j %Y"}</div>{/start_day}
    {start_time format="%g:%i %a"} - {what} <br>
    <div style="margin: 0 4em 1em 4em;">{description}</div>
{/exp:googlecal_events}


		<?php
		$buffer = ob_get_contents();

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



