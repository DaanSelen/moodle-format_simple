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
    protected function call_service(string $method, array $params): array {
        $_POST['sesskey'] = sesskey();
        try {
            return external_api::call_external_function($method, $params);
        } finally {
            unset($_POST['sesskey']);
        }
    }

    /**
     * Invoke the unit acknowledgement service and return its result.
     */
    protected function mark_unit(\stdClass $course, int $section): array {
        $response = $this->call_service(
            'format_simple_mark_unit_complete',
            ['courseid' => $course->id, 'section' => $section]
        );
        $this->assertFalse($response['error'], $response['exception']->message ?? '');
        return $response['data'];
    }

    /**
     * Invoke the course completion service and return its result.
     */
    protected function finish_course(\stdClass $course): array {
        $response = $this->call_service('format_simple_finish_course', ['courseid' => $course->id]);
        $this->assertFalse($response['error'], $response['exception']->message ?? '');
        return $response['data'];
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

        $result = $this->finish_course($course);

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
        $result = $this->finish_course($course);

        $this->assertFalse($result['completed']);
        $this->assertStringContainsString('Complete these activities', $result['message']);
    }

    public function test_finish_requires_moodle_completion_configuration(): void {
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course(false);
        $this->setUser($student);

        $result = $this->finish_course($course);

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
        $result = $this->finish_course($course);

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

        $response = $this->call_service(
            'format_simple_mark_unit_complete',
            ['courseid' => $course->id, 'section' => 1]
        );
        $this->assertTrue($response['error']);
        $this->assertSame('restricted_info', $response['exception']->errorcode);
    }

    public function test_cleanup_removes_only_stale_sections_in_the_course(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course();
        [$othercourse, $otherstudent] = $this->make_course();
        $sectionid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1]);
        $othersectionid = $DB->get_field('course_sections', 'id', ['course' => $othercourse->id, 'section' => 1]);
        \format_simple\local\progress::mark_section_complete($course->id, $student->id, (int) $sectionid);
        \format_simple\local\progress::mark_section_complete($course->id, $student->id, (int) $othersectionid);
        \format_simple\local\progress::mark_section_complete($othercourse->id, $otherstudent->id, (int) $othersectionid);

        \format_simple\local\progress::cleanup_course($course->id);

        $this->assertTrue(\format_simple\local\progress::is_section_complete($course->id, $student->id, (int) $sectionid));
        $this->assertFalse(\format_simple\local\progress::is_section_complete($course->id, $student->id, (int) $othersectionid));
        $this->assertTrue(\format_simple\local\progress::is_section_complete(
            $othercourse->id,
            $otherstudent->id,
            (int) $othersectionid
        ));
    }

    public function test_privacy_export_includes_all_acknowledged_units(): void {
        $this->resetAfterTest(true);
        [$course, $student] = $this->make_course();
        $this->setUser($student);
        $this->mark_unit($course, 1);
        $this->mark_unit($course, 2);

        $context = \context_course::instance($course->id);
        $contextlist = new \core_privacy\local\request\approved_contextlist($student, 'format_simple', [$context->id]);
        \format_simple\privacy\provider::export_user_data($contextlist);
        $writer = \core_privacy\local\request\writer::with_context($context);
        $data = $writer->get_data([
            get_string('pluginname', 'format_simple'),
            get_string('progress_complete', 'format_simple'),
        ]);
        $this->assertCount(2, $data->units);
        $this->assertNotEmpty($data->units[0]->timecompleted);
        $this->assertNotEquals($data->units[0]->unit, $data->units[1]->unit);
    }
}
