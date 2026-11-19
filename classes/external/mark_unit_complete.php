<?php
// This file is part of Moodle - http://moodle.org/

namespace format_simple\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Persist acknowledgement of one accessible unit.
 */
class mark_unit_complete extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'section' => new external_value(PARAM_INT, 'Unit section number'),
        ]);
    }

    public static function execute(int $courseid, int $section): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact('courseid', 'section'));
        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_login($course, false);
        require_capability('moodle/course:view', $context);

        $modinfo = get_fast_modinfo($course, $USER->id);
        $sectioninfo = $modinfo->get_section_info($params['section']);
        if (!$sectioninfo || (int) $sectioninfo->section === 0 || !$sectioninfo->uservisible) {
            throw new \moodle_exception('restricted_info', 'format_simple');
        }
        if (isguestuser() || !is_enrolled($context, $USER, '', true)) {
            throw new \moodle_exception('notenrolled', 'core_enrol');
        }

        \format_simple\local\progress::cleanup_course($course->id);
        \format_simple\local\progress::mark_section_complete($course->id, $USER->id, (int) $sectioninfo->id);
        return ['success' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the unit was recorded'),
        ]);
    }
}
