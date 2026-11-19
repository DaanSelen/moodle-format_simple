<?php
// This file is part of Moodle - http://moodle.org/

namespace format_simple;

use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for persistent unit acknowledgements and final course completion.
 *
 * @package format_simple
 * @copyright 2026 South African Theological Seminary <ict@sats.ac.za>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \format_simple\local\progress
 * @covers \format_simple\external\mark_unit_complete
 * @covers \format_simple\external\finish_course
 */
final class unit_progress_test extends \externallib_advanced_testcase {
    /**
     * Create a completion-enabled course with two units and an enrolled student.
     */
    protected function make_course(bool $selfcompletion = true): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course([
            'format' => 'simple',
            'numsections' => 2,
            'enablecompletion' => 1,
        ], ['createsections' => true]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        if ($selfcompletion) {
            $record = (object) [
                'course' => $course->id,
                'criteriatype' => COMPLETION_CRITERIA_TYPE_SELF,
                'module' => '',
                'moduleinstance' => 0,
                'courseinstance' => 0,
                'enrolperiod' => 0,
                'timeend' => 0,
                'gradepass' => 0,
                'role' => 0,
            ];
            $DB->insert_record('course_completion_criteria', $record);
        }

        return [$course, $student];
    }

    /**
     * Invoke the unit acknowledgement external function in test mode.
     */
    protected function mark_unit(\stdClass $course, int $section): array {
        $_POST['sesskey'] = sesskey();
        try {
            return external_api::call_external_function(
                'format_simple_mark_unit_complete',
                ['courseid' => $course->id, 'section' => $section]
            );
        } finally {
            unset($_POST['sesskey']);
        }
    }

    public function test_unit_acknowledgement_persists_and_reloads(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course();
        $this->setUser($student);

        $this->mark_unit($course, 1);
        $section = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1]);
        $this->assertTrue(\format_simple\local\progress::is_section_complete(
            $course->id,
            $student->id,
            (int) $section
        ));

        $otheruser = $this->getDataGenerator()->create_user();
        $this->assertFalse(\format_simple\local\progress::is_section_complete(
            $course->id,
            $otheruser->id,
            (int) $section
        ));
    }

    public function test_finish_rejects_incomplete_units(): void {
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course();
        $this->setUser($student);

        $_POST['sesskey'] = sesskey();
        try {
            $result = external_api::call_external_function(
                'format_simple_finish_course',
                ['courseid' => $course->id]
            );
        } finally {
            unset($_POST['sesskey']);
        }

        $this->assertFalse($result['success']);
        $this->assertFalse($result['completed']);
        $this->assertStringContainsString('Complete these units', $result['message']);
    }

    public function test_finish_rejects_incomplete_tracked_activity(): void {
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course();
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $this->setUser($student);

        $this->mark_unit($course, 1);
        $this->mark_unit($course, 2);
        $_POST['sesskey'] = sesskey();
        try {
            $result = external_api::call_external_function(
                'format_simple_finish_course',
                ['courseid' => $course->id]
            );
        } finally {
            unset($_POST['sesskey']);
        }

        $this->assertFalse($result['completed']);
        $this->assertStringContainsString('Complete these activities', $result['message']);
    }

    public function test_finish_requires_moodle_completion_configuration(): void {
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course(false);
        $this->setUser($student);

        $_POST['sesskey'] = sesskey();
        try {
            $result = external_api::call_external_function(
                'format_simple_finish_course',
                ['courseid' => $course->id]
            );
        } finally {
            unset($_POST['sesskey']);
        }

        $this->assertFalse($result['completed']);
        $this->assertStringContainsString('not configured', $result['message']);
    }

    public function test_finish_confirms_actual_moodle_course_completion(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course();
        $this->setUser($student);

        $this->mark_unit($course, 1);
        $this->mark_unit($course, 2);
        $_POST['sesskey'] = sesskey();
        try {
            $result = external_api::call_external_function(
                'format_simple_finish_course',
                ['courseid' => $course->id]
            );
        } finally {
            unset($_POST['sesskey']);
        }

        $this->assertTrue($result['success']);
        $this->assertTrue($result['completed']);
        $timecompleted = $DB->get_field('course_completions', 'timecompleted', [
            'userid' => $student->id,
            'course' => $course->id,
        ]);
        $this->assertNotEmpty($timecompleted);
    }

    public function test_restricted_unit_cannot_be_acknowledged(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course();
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1]);
        $restriction = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + 86400]], 'showc' => [true]]);
        $DB->set_field('course_sections', 'availability', $restriction, ['id' => $section->id]);
        rebuild_course_cache($course->id, true);
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->mark_unit($course, 1);
    }
}
