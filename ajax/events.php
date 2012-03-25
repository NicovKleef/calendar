<?php
/**
 * Copyright (c) 2011 Georg Ehrke <ownclouddev at georgswebsite dot de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

require_once ('../../../lib/base.php');
require_once('when/When.php');

OC_JSON::checkLoggedIn();
OC_JSON::checkAppEnabled('calendar');

if(version_compare(PHP_VERSION, '5.3.0', '>=')){
	$start = DateTime::createFromFormat('U', $_GET['start']);
	$end = DateTime::createFromFormat('U', $_GET['end']);
}else{
	$start = new DateTime('@' . $_GET['start']);
	$end = new DateTime('@' . $_GET['end']);
}

if($_GET['calendar_id'] == 'shared_rw' || $_GET['calendar_id'] == 'shared_r'){
	$calendars = OC_Calendar_Share::allSharedwithuser(OC_USER::getUser(), OC_Calendar_Share::CALENDAR, 1, ($_GET['calendar_id'] == 'shared_rw')?'rw':'r');
	$events = array();
	foreach($calendars as $calendar){
		$calendarevents = OC_Calendar_Object::allInPeriod($calendar['calendarid'], $start, $end);
		$events = array_merge($events, $calendarevents);
	}
}else{
	$calendar_id = $_GET['calendar_id'];
	if (is_numeric($calendar_id)) {
		$calendar = OC_Calendar_App::getCalendar($calendar_id);
		OC_Response::enableCaching(0);
		OC_Response::setETagHeader($calendar['ctag']);
		$events = OC_Calendar_Object::allInPeriod($calendar_id, $start, $end);
	} else {
		$events = array();
		OC_Hook::emit('OC_Calendar', 'getEvents', array('calendar_id' => $calendar_id, 'events' => &$events));
	}
}

$user_timezone = OC_Preferences::getValue(OC_USER::getUser(), 'calendar', 'timezone', date_default_timezone_get());
$return = array();
foreach($events as $event){
	if (isset($event['calendardata'])) {
		$object = OC_VObject::parse($event['calendardata']);
		$vevent = $object->VEVENT;
	} else {
		$vevent = $event['vevent'];
	}

	$return_event = OC_Calendar_App::prepareForOutput($event, $vevent);

	$dtstart = $vevent->DTSTART;
	$start_dt = $dtstart->getDateTime();
	$dtend = OC_Calendar_Object::getDTEndFromVEvent($vevent);
	$end_dt = $dtend->getDateTime();
	if ($dtstart->getDateType() == Sabre_VObject_Element_DateTime::DATE){
		$return_event['allDay'] = true;
	}else{
		$return_event['allDay'] = false;
		$start_dt->setTimezone(new DateTimeZone($user_timezone));
		$end_dt->setTimezone(new DateTimeZone($user_timezone));
	}
	if($event['repeating'] == 1){
		$duration = (double) $end_dt->format('U') - (double) $start_dt->format('U');
		$r = new When();
		$r->recur($start_dt)->rrule((string) $vevent->RRULE);
		while($result = $r->next()){
			if($result < $start){
				continue;
			}
			if($result > $end){
				break;
			}
			if($return_event['allDay'] == true){
				$return_event['start'] = $result->format('Y-m-d');
				$return_event['end'] = date('Y-m-d', $result->format('U') + --$duration);
			}else{
				$return_event['start'] = $result->format('Y-m-d H:i:s');
				$return_event['end'] = date('Y-m-d H:i:s', $result->format('U') + $duration);
			}
			$return[] = $return_event;
		}
	}else{
		if($return_event['allDay'] == true){
			$return_event['start'] = $start_dt->format('Y-m-d');
			$end_dt->modify('-1 sec');
			$return_event['end'] = $end_dt->format('Y-m-d');
		}else{
			$return_event['start'] = $start_dt->format('Y-m-d H:i:s');
			$return_event['end'] = $end_dt->format('Y-m-d H:i:s');
		}
		$return[] = $return_event;
	}
}
OC_JSON::encodedPrint($return);
?>
