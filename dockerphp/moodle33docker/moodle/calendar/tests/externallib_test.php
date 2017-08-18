<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External calendar functions unit tests
 *
 * @package    core_calendar
 * @category   external
 * @copyright  2012 Ankit Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * External course functions unit tests
 *
 * @package    core_calendar
 * @category   external
 * @copyright  2012 Ankit Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.5
 */
class core_calendar_externallib_testcase extends externallib_advanced_testcase {

    /**
     * Tests set up
     */
    protected function setUp() {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/externallib.php');
    }

    /** Create calendar events or update them
     * Set $prop->id, if you want to do an update instead of creating an new event
     *
     * @param string $name        Event title
     * @param int    $userid      User id
     * @param string $type        Event type
     * @param int    $repeats     Number of repeated events to create
     * @param int    $timestart   Time stamp of the event start
     * @param mixed  $prop        List of event properties as array or object
     * @return mixed              Event object or false;
     * @since Moodle 2.5
     */

    public static function create_calendar_event($name, $userid = 0, $type = 'user', $repeats = 0, $timestart  = null, $prop = null) {
        global $CFG, $DB, $SITE;

        require_once("$CFG->dirroot/calendar/lib.php");
        if (!empty($prop)) {
            if (is_array($prop)) {
                $prop = (object)$prop;
            }
        } else {
            $prop = new stdClass();
        }
        $prop->name = $name;
        if (empty($prop->eventtype)) {
            $prop->eventtype = $type;
        }
        if (empty($prop->repeats)) {
            $prop->repeats = $repeats;
        }
        if (empty($prop->timestart)) {
            $prop->timestart = time();
        }
        if (empty($prop->timeduration)) {
            $prop->timeduration = 0;
        }
        if (empty($prop->timesort)) {
            $prop->timesort = 0;
        }
        if (empty($prop->type)) {
            $prop->type = CALENDAR_EVENT_TYPE_STANDARD;
        }
        if (empty($prop->repeats)) {
            $prop->repeat = 0;
        } else {
            $prop->repeat = 1;
        }
        if (empty($prop->userid)) {
            if (!empty($userid)) {
                $prop->userid = $userid;
            } else {
                return false;
            }
        }
        if (!isset($prop->courseid)) {
            $prop->courseid = $SITE->id;
        }

        // Determine event priority.
        if ($prop->courseid == 0 && isset($prop->groupid) && $prop->groupid == 0 && !empty($prop->userid)) {
            // User override event.
            $prop->priority = CALENDAR_EVENT_USER_OVERRIDE_PRIORITY;
        } else if ($prop->courseid != $SITE->id && !empty($prop->groupid)) {
            // Group override event.
            $priorityparams = ['courseid' => $prop->courseid, 'groupid' => $prop->groupid];
            // Group override event with the highest priority.
            $groupevents = $DB->get_records('event', $priorityparams, 'priority DESC', 'id, priority', 0, 1);
            $priority = 1;
            if (!empty($groupevents)) {
                $event = reset($groupevents);
                if (!empty($event->priority)) {
                    $priority = $event->priority + 1;
                }
            }
            $prop->priority = $priority;
        }

        $event = new calendar_event($prop);
        return $event->create($prop);
    }

    public function test_create_calendar_events () {
        global $DB, $USER;

        $this->setAdminUser();
        $this->resetAfterTest();
        $prevcount = count($DB->get_records("event"));

        // Create a few events and do asserts.
        $this->create_calendar_event('test', $USER->id);
        $where = $DB->sql_compare_text('name') ." = ?";
        $count = count($DB->get_records_select("event", $where, array('test')));
        $this->assertEquals(1, $count);
        $aftercount = count($DB->get_records("event"));
        $this->assertEquals($prevcount + 1, $aftercount);

        $this->create_calendar_event('user', $USER->id, 'user', 3);
        $where = $DB->sql_compare_text('name') ." = ?";
        $count = count($DB->get_records_select("event", $where, array('user')));

        $this->assertEquals(3, $count);
        $aftercount = count($DB->get_records("event"));
        $this->assertEquals($prevcount + 4, $aftercount);

    }

    /**
     * Test delete_calendar_events
     *
     * @expectedException moodle_exception
     */
    public function test_delete_calendar_events() {
        global $DB, $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create a few stuff to test with.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $record = new stdClass();
        $record->courseid = $course->id;
        $group = $this->getDataGenerator()->create_group($record);

        $notdeletedcount = $DB->count_records('event');

        // Let's create a few events.
        $siteevent = $this->create_calendar_event('site', $USER->id, 'site');
        $record = new stdClass();
        $record->courseid = $course->id;
        $courseevent = $this->create_calendar_event('course', $USER->id, 'course', 2, time(), $record);
        $userevent = $this->create_calendar_event('user', $USER->id);
        $record = new stdClass();
        $record->courseid = $course->id;
        $record->groupid = $group->id;
        $groupevent = $this->create_calendar_event('group', $USER->id, 'group', 0, time(), $record);

        // Now lets try to delete stuff with proper rights.
        $events = array(
                array('eventid' => $siteevent->id, 'repeat' => 0),
                array('eventid' => $courseevent->id, 'repeat' => 1),
                array('eventid' => $userevent->id, 'repeat' => 0),
                array('eventid' => $groupevent->id, 'repeat' => 0)
                );
        core_calendar_external::delete_calendar_events($events);

        // Check to see if things were deleted properly.
        $deletedcount = $DB->count_records('event');
        $this->assertEquals($notdeletedcount, $deletedcount);

        // Let's create a few events.
        $siteevent = $this->create_calendar_event('site', $USER->id, 'site');
        $record = new stdClass();
        $record->courseid = $course->id;
        $courseevent = $this->create_calendar_event('course', $USER->id, 'course', 3, time(), $record);
        $userevent = $this->create_calendar_event('user', $USER->id);
        $record = new stdClass();
        $record->courseid = $course->id;
        $record->groupid = $group->id;
        $groupevent = $this->create_calendar_event('group', $USER->id, 'group', 0, time(), $record);

        $this->setuser($user);
        $sitecontext = context_system::instance();
        $coursecontext = context_course::instance($course->id);
        $usercontext = context_user::instance($user->id);
        $role = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role->id);

        // Remove all caps.
        $this->unassignUserCapability('moodle/calendar:manageentries', $sitecontext->id, $role->id);
        $this->unassignUserCapability('moodle/calendar:manageentries', $coursecontext->id, $role->id);
        $this->unassignUserCapability('moodle/calendar:managegroupentries', $coursecontext->id, $role->id);
        $this->unassignUserCapability('moodle/calendar:manageownentries', $usercontext->id, $role->id);

        // Assign proper caps and attempt delete.
         $this->assignUserCapability('moodle/calendar:manageentries', $sitecontext->id, $role->id);
         $events = array(
                array('eventid' => $siteevent->id, 'repeat' => 0),
                );
        core_calendar_external::delete_calendar_events($events);
        $deletedcount = $DB->count_records('event');
        $count = $notdeletedcount+5;
        $this->assertEquals($count, $deletedcount);

         $this->assignUserCapability('moodle/calendar:manageentries', $sitecontext->id, $role->id);
         $events = array(
                array('eventid' => $courseevent->id, 'repeat' => 0),
                );
        core_calendar_external::delete_calendar_events($events);
        $deletedcount = $DB->count_records('event');
        $count = $notdeletedcount+4;
        $this->assertEquals($count, $deletedcount);

         $this->assignUserCapability('moodle/calendar:manageownentries', $usercontext->id, $role->id);
         $events = array(
                array('eventid' => $userevent->id, 'repeat' => 0),
                );
        core_calendar_external::delete_calendar_events($events);
        $deletedcount = $DB->count_records('event');
        $count = $notdeletedcount+3;
        $this->assertEquals($count, $deletedcount);

         $this->assignUserCapability('moodle/calendar:managegroupentries', $coursecontext->id, $role->id);
         $events = array(
                array('eventid' => $groupevent->id, 'repeat' => 0),
                );
        core_calendar_external::delete_calendar_events($events);
        $deletedcount = $DB->count_records('event');
        $count = $notdeletedcount+2;
        $this->assertEquals($count, $deletedcount);

        $notdeletedcount = $deletedcount;

        // Let us try deleting without caps.

        $siteevent = $this->create_calendar_event('site', $USER->id, 'site');
        $record = new stdClass();
        $record->courseid = $course->id;
        $courseevent = $this->create_calendar_event('course', $USER->id, 'course', 3, time(), $record);
        $userevent = $this->create_calendar_event('user', $USER->id);
        $record = new stdClass();
        $record->courseid = $course->id;
        $record->groupid = $group->id;
        $groupevent = $this->create_calendar_event('group', $USER->id, 'group', 0, time(), $record);

        $this->setGuestUser();

        $events = array(
            array('eventid' => $siteevent->id, 'repeat' => 0),
            array('eventid' => $courseevent->id, 'repeat' => 0),
            array('eventid' => $userevent->id, 'repeat' => 0),
            array('eventid' => $groupevent->id, 'repeat' => 0)
        );
        core_calendar_external::delete_calendar_events($events);
    }

    /**
     * Test get_calendar_events
     */
    public function test_get_calendar_events() {
        global $DB, $USER;

        $this->resetAfterTest(true);
        set_config('calendar_adminseesall', 1);
        $this->setAdminUser();

        // Create a few stuff to test with.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $record = new stdClass();
        $record->courseid = $course->id;
        $group = $this->getDataGenerator()->create_group($record);

        $beforecount = $DB->count_records('event');

        // Let's create a few events.
        $siteevent = $this->create_calendar_event('site', $USER->id, 'site');

        // This event will have description with an inline fake image.
        $draftidfile = file_get_unused_draft_itemid();
        $usercontext = context_course::instance($course->id);
        $filerecord = array(
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftidfile,
            'filepath'  => '/',
            'filename'  => 'fakeimage.png',
        );
        $fs = get_file_storage();
        $fs->create_file_from_string($filerecord, 'img contents');

        $record = new stdClass();
        $record->courseid = $course->id;
        $record->groupid = 0;
        $record->description = array(
            'format' => FORMAT_HTML,
            'text' => 'Text with img <img src="@@PLUGINFILE@@/fakeimage.png">',
            'itemid' => $draftidfile
        );
        $courseevent = $this->create_calendar_event('course', $USER->id, 'course', 2, time(), $record);

        $record = new stdClass();
        $record->courseid = 0;
        $record->groupid = 0;
        $userevent = $this->create_calendar_event('user', $USER->id, 'user', 0, time(), $record);

        $record = new stdClass();
        $record->courseid = $course->id;
        $record->groupid = $group->id;
        $groupevent = $this->create_calendar_event('group', $USER->id, 'group', 0, time(), $record);

        $paramevents = array ('eventids' => array($siteevent->id), 'courseids' => array($course->id), 'groupids' => array($group->id));
        $options = array ('siteevents' => true, 'userevents' => true);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);

        // Check to see if we got all events.
        $this->assertEquals(5, count($events['events']));
        $this->assertEquals(0, count($events['warnings']));
        $options = array ('siteevents' => true, 'userevents' => true, 'timeend' => time() + 7*WEEKSECS);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(5, count($events['events']));
        $this->assertEquals(0, count($events['warnings']));

        // Expect the same URL in the description of two different events (because they are repeated).
        $coursecontext = context_course::instance($course->id);
        $expectedurl = "webservice/pluginfile.php/$coursecontext->id/calendar/event_description/$courseevent->id/fakeimage.png";
        $withdescription = 0;
        foreach ($events['events'] as $event) {
            if (!empty($event['description'])) {
                $withdescription++;
                $this->assertContains($expectedurl, $event['description']);
            }
        }
        $this->assertEquals(2, $withdescription);

        // Let's play around with caps.

        // Create user event for the user $user.
        $record = new stdClass();
        $record->courseid = 0;
        $record->groupid = 0;
        $this->create_calendar_event('user', $user->id, 'user', 0, time(), $record);

        $this->setUser($user);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(2, count($events['events'])); // site, user.
        $this->assertEquals(2, count($events['warnings'])); // course, group.

        $role = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role->id);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(4, count($events['events'])); // site, user, both course events.
        $this->assertEquals(1, count($events['warnings'])); // group.

        $options = array ('siteevents' => true, 'userevents' => true, 'timeend' => time() + HOURSECS);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(3, count($events['events'])); // site, user, one course event.
        $this->assertEquals(1, count($events['warnings'])); // group.

        groups_add_member($group, $user);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(4, count($events['events'])); // site, user, group, one course event.
        $this->assertEquals(0, count($events['warnings']));

        $paramevents = array ('courseids' => array($course->id), 'groupids' => array($group->id));
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(4, count($events['events'])); // site, user, group, one course event.
        $this->assertEquals(0, count($events['warnings']));

        $paramevents = array ('groupids' => array($group->id, 23));
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(3, count($events['events'])); // site, user, group.
        $this->assertEquals(1, count($events['warnings']));

        $paramevents = array ('courseids' => array(23));
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(2, count($events['events'])); // site, user.
        $this->assertEquals(1, count($events['warnings']));

        $paramevents = array ();
        $options = array ('siteevents' => false, 'userevents' => false, 'timeend' => time() + 7*WEEKSECS);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(0, count($events['events'])); // nothing returned.
        $this->assertEquals(0, count($events['warnings']));

        $paramevents = array ('eventids' => array($siteevent->id, $groupevent->id));
        $options = array ('siteevents' => false, 'userevents' => false, 'timeend' => time() + 7*WEEKSECS);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(2, count($events['events'])); // site, group.
        $this->assertEquals(0, count($events['warnings']));

        $paramevents = array ('eventids' => array($siteevent->id));
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(1, count($events['events'])); // site.
        $this->assertEquals(0, count($events['warnings']));

        // Try getting a course event by its id.
        $paramevents = array ('eventids' => array($courseevent->id));
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        $this->assertEquals(1, count($events['events']));
        $this->assertEquals(0, count($events['warnings']));

        // Now, create an activity event.
        $this->setAdminUser();
        $nexttime = time() + DAYSECS;
        $assign = $this->getDataGenerator()->create_module('assign', array('course' => $course->id, 'duedate' => $nexttime));

        $this->setUser($user);
        $paramevents = array ('courseids' => array($course->id));
        $options = array ('siteevents' => true, 'userevents' => true, 'timeend' => time() + WEEKSECS);
        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);

        $this->assertCount(5, $events['events']);

        // Hide the assignment.
        set_coursemodule_visible($assign->cmid, 0);
        // Empty all the caches that may be affected  by this change.
        accesslib_clear_all_caches_for_unit_testing();
        course_modinfo::clear_instance_cache();

        $events = core_calendar_external::get_calendar_events($paramevents, $options);
        $events = external_api::clean_returnvalue(core_calendar_external::get_calendar_events_returns(), $events);
        // Expect one less.
        $this->assertCount(4, $events['events']);
    }

    /**
     * Test core_calendar_external::create_calendar_events
     */
    public function test_core_create_calendar_events() {
        global $DB, $USER, $SITE;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create a few stuff to test with.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $record = new stdClass();
        $record->courseid = $course->id;
        $group = $this->getDataGenerator()->create_group($record);

        $prevcount = $DB->count_records('event');

        // Let's create a few events.
        $events = array (
                array('name' => 'site', 'courseid' => $SITE->id, 'eventtype' => 'site'),
                array('name' => 'course', 'courseid' => $course->id, 'eventtype' => 'course', 'repeats' => 2),
                array('name' => 'group', 'courseid' => $course->id, 'groupid' => $group->id, 'eventtype' => 'group'),
                array('name' => 'user')
                );
        $eventsret = core_calendar_external::create_calendar_events($events);
        $eventsret = external_api::clean_returnvalue(core_calendar_external::create_calendar_events_returns(), $eventsret);

        // Check to see if things were created properly.
        $aftercount = $DB->count_records('event');
        $this->assertEquals($prevcount + 5, $aftercount);
        $this->assertEquals(5, count($eventsret['events']));
        $this->assertEquals(0, count($eventsret['warnings']));

        $sitecontext = context_system::instance();
        $coursecontext = context_course::instance($course->id);

        $this->setUser($user);
        $prevcount = $aftercount;
        $events = array (
                array('name' => 'course', 'courseid' => $course->id, 'eventtype' => 'course', 'repeats' => 2),
                array('name' => 'group', 'courseid' => $course->id, 'groupid' => $group->id, 'eventtype' => 'group'),
                array('name' => 'user')
        );
        $role = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role->id);
        groups_add_member($group, $user);
        $this->assignUserCapability('moodle/calendar:manageentries', $coursecontext->id, $role->id);
        $this->assignUserCapability('moodle/calendar:managegroupentries', $coursecontext->id, $role->id);
        $eventsret = core_calendar_external::create_calendar_events($events);
        $eventsret = external_api::clean_returnvalue(core_calendar_external::create_calendar_events_returns(), $eventsret);
        // Check to see if things were created properly.
        $aftercount = $DB->count_records('event');
        $this->assertEquals($prevcount + 4, $aftercount);
        $this->assertEquals(4, count($eventsret['events']));
        $this->assertEquals(0, count($eventsret['warnings']));

        // Check to see nothing was created without proper permission.
        $this->setGuestUser();
        $prevcount = $DB->count_records('event');
        $eventsret = core_calendar_external::create_calendar_events($events);
        $eventsret = external_api::clean_returnvalue(core_calendar_external::create_calendar_events_returns(), $eventsret);
        $aftercount = $DB->count_records('event');
        $this->assertEquals($prevcount, $aftercount);
        $this->assertEquals(0, count($eventsret['events']));
        $this->assertEquals(3, count($eventsret['warnings']));

        $this->setUser($user);
        $this->unassignUserCapability('moodle/calendar:manageentries', $coursecontext->id, $role->id);
        $this->unassignUserCapability('moodle/calendar:managegroupentries', $coursecontext->id, $role->id);
        $prevcount = $DB->count_records('event');
        $eventsret = core_calendar_external::create_calendar_events($events);
        $eventsret = external_api::clean_returnvalue(core_calendar_external::create_calendar_events_returns(), $eventsret);
        $aftercount = $DB->count_records('event');
        $this->assertEquals($prevcount + 1, $aftercount); // User event.
        $this->assertEquals(1, count($eventsret['events']));
        $this->assertEquals(2, count($eventsret['warnings']));
    }

    /**
     * Requesting calendar events from a given time should return all events with a sort
     * time at or after the requested time. All events prior to that time should not
     * be return.
     *
     * If there are no events on or after the given time then an empty result set should
     * be returned.
     */
    public function test_get_calendar_action_events_by_timesort_after_time() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $moduleinstance = $generator->create_instance(['course' => $course->id]);

        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        $params = [
            'type' => CALENDAR_EVENT_TYPE_ACTION,
            'modulename' => 'assign',
            'instance' => $moduleinstance->id,
            'courseid' => $course->id,
        ];

        $event1 = $this->create_calendar_event('Event 1', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 1]));
        $event2 = $this->create_calendar_event('Event 2', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 2]));
        $event3 = $this->create_calendar_event('Event 3', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 3]));
        $event4 = $this->create_calendar_event('Event 4', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 4]));
        $event5 = $this->create_calendar_event('Event 5', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 5]));
        $event6 = $this->create_calendar_event('Event 6', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 6]));
        $event7 = $this->create_calendar_event('Event 7', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 7]));
        $event8 = $this->create_calendar_event('Event 8', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 8]));

        $result = core_calendar_external::get_calendar_action_events_by_timesort(5);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );
        $events = $result['events'];

        $this->assertCount(4, $events);
        $this->assertEquals('Event 5', $events[0]['name']);
        $this->assertEquals('Event 6', $events[1]['name']);
        $this->assertEquals('Event 7', $events[2]['name']);
        $this->assertEquals('Event 8', $events[3]['name']);
        $this->assertEquals($event5->id, $result['firstid']);
        $this->assertEquals($event8->id, $result['lastid']);

        $result = core_calendar_external::get_calendar_action_events_by_timesort(9);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );

        $this->assertEmpty($result['events']);
        $this->assertNull($result['firstid']);
        $this->assertNull($result['lastid']);
    }

    /**
     * Requesting calendar events before a given time should return all events with a sort
     * time at or before the requested time (inclusive). All events after that time
     * should not be returned.
     *
     * If there are no events before the given time then an empty result set should be
     * returned.
     */
    public function test_get_calendar_action_events_by_timesort_before_time() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $moduleinstance = $generator->create_instance(['course' => $course->id]);

        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        $params = [
            'type' => CALENDAR_EVENT_TYPE_ACTION,
            'modulename' => 'assign',
            'instance' => $moduleinstance->id,
            'courseid' => $course->id,
        ];

        $event1 = $this->create_calendar_event('Event 1', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 2]));
        $event2 = $this->create_calendar_event('Event 2', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 3]));
        $event3 = $this->create_calendar_event('Event 3', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 4]));
        $event4 = $this->create_calendar_event('Event 4', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 5]));
        $event5 = $this->create_calendar_event('Event 5', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 6]));
        $event6 = $this->create_calendar_event('Event 6', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 7]));
        $event7 = $this->create_calendar_event('Event 7', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 8]));
        $event8 = $this->create_calendar_event('Event 8', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 9]));

        $result = core_calendar_external::get_calendar_action_events_by_timesort(null, 5);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );
        $events = $result['events'];

        $this->assertCount(4, $events);
        $this->assertEquals('Event 1', $events[0]['name']);
        $this->assertEquals('Event 2', $events[1]['name']);
        $this->assertEquals('Event 3', $events[2]['name']);
        $this->assertEquals('Event 4', $events[3]['name']);
        $this->assertEquals($event1->id, $result['firstid']);
        $this->assertEquals($event4->id, $result['lastid']);

        $result = core_calendar_external::get_calendar_action_events_by_timesort(null, 1);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );

        $this->assertEmpty($result['events']);
        $this->assertNull($result['firstid']);
        $this->assertNull($result['lastid']);
    }

    /**
     * Requesting calendar events within a given time range should return all events with
     * a sort time between the lower and upper time bound (inclusive).
     *
     * If there are no events in the given time range then an empty result set should be
     * returned.
     */
    public function test_get_calendar_action_events_by_timesort_time_range() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $moduleinstance = $generator->create_instance(['course' => $course->id]);

        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        $params = [
            'type' => CALENDAR_EVENT_TYPE_ACTION,
            'modulename' => 'assign',
            'instance' => $moduleinstance->id,
            'courseid' => $course->id,
        ];

        $event1 = $this->create_calendar_event('Event 1', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 1]));
        $event2 = $this->create_calendar_event('Event 2', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 2]));
        $event3 = $this->create_calendar_event('Event 3', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 3]));
        $event4 = $this->create_calendar_event('Event 4', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 4]));
        $event5 = $this->create_calendar_event('Event 5', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 5]));
        $event6 = $this->create_calendar_event('Event 6', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 6]));
        $event7 = $this->create_calendar_event('Event 7', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 7]));
        $event8 = $this->create_calendar_event('Event 8', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 8]));

        $result = core_calendar_external::get_calendar_action_events_by_timesort(3, 6);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );
        $events = $result['events'];

        $this->assertCount(4, $events);
        $this->assertEquals('Event 3', $events[0]['name']);
        $this->assertEquals('Event 4', $events[1]['name']);
        $this->assertEquals('Event 5', $events[2]['name']);
        $this->assertEquals('Event 6', $events[3]['name']);
        $this->assertEquals($event3->id, $result['firstid']);
        $this->assertEquals($event6->id, $result['lastid']);

        $result = core_calendar_external::get_calendar_action_events_by_timesort(10, 15);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );

        $this->assertEmpty($result['events']);
        $this->assertNull($result['firstid']);
        $this->assertNull($result['lastid']);
    }

    /**
     * Requesting calendar events within a given time range and a limit and offset should return
     * the number of events up to the given limit value that have a sort time between the lower
     * and uppper time bound (inclusive) where the result set is shifted by the offset value.
     *
     * If there are no events in the given time range then an empty result set should be
     * returned.
     */
    public function test_get_calendar_action_events_by_timesort_time_limit_offset() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $moduleinstance = $generator->create_instance(['course' => $course->id]);

        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        $params = [
            'type' => CALENDAR_EVENT_TYPE_ACTION,
            'modulename' => 'assign',
            'instance' => $moduleinstance->id,
            'courseid' => $course->id,
        ];

        $event1 = $this->create_calendar_event('Event 1', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 1]));
        $event2 = $this->create_calendar_event('Event 2', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 2]));
        $event3 = $this->create_calendar_event('Event 3', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 3]));
        $event4 = $this->create_calendar_event('Event 4', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 4]));
        $event5 = $this->create_calendar_event('Event 5', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 5]));
        $event6 = $this->create_calendar_event('Event 6', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 6]));
        $event7 = $this->create_calendar_event('Event 7', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 7]));
        $event8 = $this->create_calendar_event('Event 8', $user->id, 'user', 0, 1, array_merge($params, ['timesort' => 8]));

        $result = core_calendar_external::get_calendar_action_events_by_timesort(2, 7, $event3->id, 2);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );
        $events = $result['events'];

        $this->assertCount(2, $events);
        $this->assertEquals('Event 4', $events[0]['name']);
        $this->assertEquals('Event 5', $events[1]['name']);
        $this->assertEquals($event4->id, $result['firstid']);
        $this->assertEquals($event5->id, $result['lastid']);

        $result = core_calendar_external::get_calendar_action_events_by_timesort(2, 7, $event5->id, 2);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );
        $events = $result['events'];

        $this->assertCount(2, $events);
        $this->assertEquals('Event 6', $events[0]['name']);
        $this->assertEquals('Event 7', $events[1]['name']);
        $this->assertEquals($event6->id, $result['firstid']);
        $this->assertEquals($event7->id, $result['lastid']);

        $result = core_calendar_external::get_calendar_action_events_by_timesort(2, 7, $event7->id, 2);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_timesort_returns(),
            $result
        );

        $this->assertEmpty($result['events']);
        $this->assertNull($result['firstid']);
        $this->assertNull($result['lastid']);
    }

    /**
     * Requesting calendar events from a given course and time should return all
     * events with a sort time at or after the requested time. All events prior
     * to that time should not be return.
     *
     * If there are no events on or after the given time then an empty result set should
     * be returned.
     */
    public function test_get_calendar_action_events_by_course_after_time() {
        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $instance1 = $generator->create_instance(['course' => $course1->id]);
        $instance2 = $generator->create_instance(['course' => $course2->id]);
        $records = [];

        $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        $this->getDataGenerator()->enrol_user($user->id, $course2->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        for ($i = 1; $i < 19; $i++) {
            $courseid = ($i < 9) ? $course1->id : $course2->id;
            $instance = ($i < 9) ? $instance1->id : $instance2->id;
            $records[] = $this->create_calendar_event(
                sprintf('Event %d', $i),
                $user->id,
                'user',
                0,
                1,
                [
                    'type' => CALENDAR_EVENT_TYPE_ACTION,
                    'courseid' => $courseid,
                    'timesort' => $i,
                    'modulename' => 'assign',
                    'instance' => $instance,
                ]
            );
        }

        $result = core_calendar_external::get_calendar_action_events_by_course($course1->id, 5);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertCount(4, $result);
        $this->assertEquals('Event 5', $result[0]['name']);
        $this->assertEquals('Event 6', $result[1]['name']);
        $this->assertEquals('Event 7', $result[2]['name']);
        $this->assertEquals('Event 8', $result[3]['name']);

        $result = core_calendar_external::get_calendar_action_events_by_course($course1->id, 9);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertEmpty($result);
    }

    /**
     * Requesting calendar events for a course and before a given time should return
     * all events with a sort time at or before the requested time (inclusive). All
     * events after that time should not be returned.
     *
     * If there are no events before the given time then an empty result set should be
     * returned.
     */
    public function test_get_calendar_action_events_by_course_before_time() {
        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $instance1 = $generator->create_instance(['course' => $course1->id]);
        $instance2 = $generator->create_instance(['course' => $course2->id]);
        $records = [];

        $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        $this->getDataGenerator()->enrol_user($user->id, $course2->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        for ($i = 1; $i < 19; $i++) {
            $courseid = ($i < 9) ? $course1->id : $course2->id;
            $instance = ($i < 9) ? $instance1->id : $instance2->id;
            $records[] = $this->create_calendar_event(
                sprintf('Event %d', $i),
                $user->id,
                'user',
                0,
                1,
                [
                    'type' => CALENDAR_EVENT_TYPE_ACTION,
                    'courseid' => $courseid,
                    'timesort' => $i + 1,
                    'modulename' => 'assign',
                    'instance' => $instance,
                ]
            );
        }

        $result = core_calendar_external::get_calendar_action_events_by_course($course1->id, null, 5);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertCount(4, $result);
        $this->assertEquals('Event 1', $result[0]['name']);
        $this->assertEquals('Event 2', $result[1]['name']);
        $this->assertEquals('Event 3', $result[2]['name']);
        $this->assertEquals('Event 4', $result[3]['name']);

        $result = core_calendar_external::get_calendar_action_events_by_course($course1->id, null, 1);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertEmpty($result);
    }

    /**
     * Requesting calendar events for a course and within a given time range should
     * return all events with a sort time between the lower and upper time bound
     * (inclusive).
     *
     * If there are no events in the given time range then an empty result set should be
     * returned.
     */
    public function test_get_calendar_action_events_by_course_time_range() {
        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $instance1 = $generator->create_instance(['course' => $course1->id]);
        $instance2 = $generator->create_instance(['course' => $course2->id]);
        $records = [];

        $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        $this->getDataGenerator()->enrol_user($user->id, $course2->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        for ($i = 1; $i < 19; $i++) {
            $courseid = ($i < 9) ? $course1->id : $course2->id;
            $instance = ($i < 9) ? $instance1->id : $instance2->id;
            $records[] = $this->create_calendar_event(
                sprintf('Event %d', $i),
                $user->id,
                'user',
                0,
                1,
                [
                    'type' => CALENDAR_EVENT_TYPE_ACTION,
                    'courseid' => $courseid,
                    'timesort' => $i,
                    'modulename' => 'assign',
                    'instance' => $instance,
                ]
            );
        }

        $result = core_calendar_external::get_calendar_action_events_by_course($course1->id, 3, 6);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertCount(4, $result);
        $this->assertEquals('Event 3', $result[0]['name']);
        $this->assertEquals('Event 4', $result[1]['name']);
        $this->assertEquals('Event 5', $result[2]['name']);
        $this->assertEquals('Event 6', $result[3]['name']);

        $result = core_calendar_external::get_calendar_action_events_by_course($course1->id, 10, 15);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertEmpty($result);
    }

    /**
     * Requesting calendar events for a course and within a given time range and a limit
     * and offset should return the number of events up to the given limit value that have
     * a sort time between the lower and uppper time bound (inclusive) where the result
     * set is shifted by the offset value.
     *
     * If there are no events in the given time range then an empty result set should be
     * returned.
     */
    public function test_get_calendar_action_events_by_course_time_limit_offset() {
        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $instance1 = $generator->create_instance(['course' => $course1->id]);
        $instance2 = $generator->create_instance(['course' => $course2->id]);
        $records = [];

        $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        $this->getDataGenerator()->enrol_user($user->id, $course2->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        for ($i = 1; $i < 19; $i++) {
            $courseid = ($i < 9) ? $course1->id : $course2->id;
            $instance = ($i < 9) ? $instance1->id : $instance2->id;
            $records[] = $this->create_calendar_event(
                sprintf('Event %d', $i),
                $user->id,
                'user',
                0,
                1,
                [
                    'type' => CALENDAR_EVENT_TYPE_ACTION,
                    'courseid' => $courseid,
                    'timesort' => $i,
                    'modulename' => 'assign',
                    'instance' => $instance,
                ]
            );
        }

        $result = core_calendar_external::get_calendar_action_events_by_course(
            $course1->id, 2, 7, $records[2]->id, 2);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertCount(2, $result);
        $this->assertEquals('Event 4', $result[0]['name']);
        $this->assertEquals('Event 5', $result[1]['name']);

        $result = core_calendar_external::get_calendar_action_events_by_course(
            $course1->id, 2, 7, $records[4]->id, 2);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertCount(2, $result);
        $this->assertEquals('Event 6', $result[0]['name']);
        $this->assertEquals('Event 7', $result[1]['name']);

        $result = core_calendar_external::get_calendar_action_events_by_course(
            $course1->id, 2, 7, $records[6]->id, 2);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_course_returns(),
            $result
        );
        $result = $result['events'];

        $this->assertEmpty($result);
    }

    /**
     * Test that get_action_events_by_courses will return a list of events for each
     * course you provided as long as the user is enrolled in the course.
     */
    public function test_get_action_events_by_courses() {
        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $course3 = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $instance1 = $generator->create_instance(['course' => $course1->id]);
        $instance2 = $generator->create_instance(['course' => $course2->id]);
        $instance3 = $generator->create_instance(['course' => $course3->id]);
        $records = [];
        $mapresult = function($result) {
            $groupedbycourse = [];
            foreach ($result['groupedbycourse'] as $group) {
                $events = $group['events'];
                $courseid = $group['courseid'];
                $groupedbycourse[$courseid] = $events;
            }

            return $groupedbycourse;
        };

        $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        $this->getDataGenerator()->enrol_user($user->id, $course2->id);
        $this->resetAfterTest(true);
        $this->setUser($user);

        for ($i = 1; $i < 10; $i++) {
            if ($i < 3) {
                $courseid = $course1->id;
                $instance = $instance1->id;
            } else if ($i < 6) {
                $courseid = $course2->id;
                $instance = $instance2->id;
            } else {
                $courseid = $course3->id;
                $instance = $instance3->id;
            }

            $records[] = $this->create_calendar_event(
                sprintf('Event %d', $i),
                $user->id,
                'user',
                0,
                1,
                [
                    'type' => CALENDAR_EVENT_TYPE_ACTION,
                    'courseid' => $courseid,
                    'timesort' => $i,
                    'modulename' => 'assign',
                    'instance' => $instance,
                ]
            );
        }

        $result = core_calendar_external::get_calendar_action_events_by_courses([], 1);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_courses_returns(),
            $result
        );
        $result = $result['groupedbycourse'];

        $this->assertEmpty($result);

        $result = core_calendar_external::get_calendar_action_events_by_courses([$course1->id], 3);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_courses_returns(),
            $result
        );

        $groupedbycourse = $mapresult($result);

        $this->assertEmpty($groupedbycourse[$course1->id]);

        $result = core_calendar_external::get_calendar_action_events_by_courses([$course1->id], 1);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_courses_returns(),
            $result
        );
        $groupedbycourse = $mapresult($result);

        $this->assertCount(2, $groupedbycourse[$course1->id]);
        $this->assertEquals('Event 1', $groupedbycourse[$course1->id][0]['name']);
        $this->assertEquals('Event 2', $groupedbycourse[$course1->id][1]['name']);

        $result = core_calendar_external::get_calendar_action_events_by_courses(
            [$course1->id, $course2->id], 1);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_courses_returns(),
            $result
        );
        $groupedbycourse = $mapresult($result);

        $this->assertCount(2, $groupedbycourse[$course1->id]);
        $this->assertEquals('Event 1', $groupedbycourse[$course1->id][0]['name']);
        $this->assertEquals('Event 2', $groupedbycourse[$course1->id][1]['name']);
        $this->assertCount(3, $groupedbycourse[$course2->id]);
        $this->assertEquals('Event 3', $groupedbycourse[$course2->id][0]['name']);
        $this->assertEquals('Event 4', $groupedbycourse[$course2->id][1]['name']);
        $this->assertEquals('Event 5', $groupedbycourse[$course2->id][2]['name']);

        $result = core_calendar_external::get_calendar_action_events_by_courses(
            [$course1->id, $course2->id], 2, 4);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_courses_returns(),
            $result
        );
        $groupedbycourse = $mapresult($result);

        $this->assertCount(2, $groupedbycourse);
        $this->assertCount(1, $groupedbycourse[$course1->id]);
        $this->assertEquals('Event 2', $groupedbycourse[$course1->id][0]['name']);
        $this->assertCount(2, $groupedbycourse[$course2->id]);
        $this->assertEquals('Event 3', $groupedbycourse[$course2->id][0]['name']);
        $this->assertEquals('Event 4', $groupedbycourse[$course2->id][1]['name']);

        $result = core_calendar_external::get_calendar_action_events_by_courses(
            [$course1->id, $course2->id], 1, null, 1);
        $result = external_api::clean_returnvalue(
            core_calendar_external::get_calendar_action_events_by_courses_returns(),
            $result
        );
        $groupedbycourse = $mapresult($result);

        $this->assertCount(2, $groupedbycourse);
        $this->assertCount(1, $groupedbycourse[$course1->id]);
        $this->assertEquals('Event 1', $groupedbycourse[$course1->id][0]['name']);
        $this->assertCount(1, $groupedbycourse[$course2->id]);
        $this->assertEquals('Event 3', $groupedbycourse[$course2->id][0]['name']);
    }
}