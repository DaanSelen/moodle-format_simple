<?php
// This file is part of Moodle - http://moodle.org/

namespace format_simple\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');

/**
 * Validate and submit Moodle course completion.
 */
class finish_course extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $USER, $DB;
        $params = self::validate_parameters(self::execute_parameters(), compact('courseid'));
        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_login($course, false);
        require_capability('moodle/course:view', $context);
        if (isguestuser() || !is_enrolled($context, $USER, '', true)) {
            throw new \moodle_exception('notenrolled', 'core_enrol');
        }

        \format_simple\local\progress::cleanup_course($course->id);
        $completion = new \completion_info($course);
        if (!\completion_info::is_enabled_for_site()) {
            return ['success' => false, 'completed' => false, 'message' => get_string('completionnotenabledforsite', 'completion')];
        }
        if (!$completion->is_enabled()) {
            return ['success' => false, 'completed' => false, 'message' => get_string('completionnotenabledforcourse', 'completion')];
        }
        if (!$completion->is_tracked_user($USER->id)) {
            return ['success' => false, 'completed' => false, 'message' => get_string('nottracked', 'completion')];
        }
        if (!$DB->record_exists('course_completion_criteria', ['course' => $course->id])) {
            return ['success' => false, 'completed' => false, 'message' => get_string('nocriteria', 'format_simple')];
        }

        $incomplete = \format_simple\local\progress::get_incomplete_requirements($course, $USER->id);
        if (!empty($incomplete['units']) || !empty($incomplete['activities'])) {
            $parts = [];
            if (!empty($incomplete['units'])) {
                $parts[] = get_string('incompleteunits', 'format_simple', implode(', ', $incomplete['units']));
            }
            if (!empty($incomplete['activities'])) {
                $parts[] = get_string('incompleteactivities', 'format_simple', implode(', ', $incomplete['activities']));
            }
            return ['success' => false, 'completed' => false, 'message' => implode(' ', $parts)];
        }

        $coursecompletion = $completion->get_completion($USER->id);
        if (!$coursecompletion) {
            return ['success' => false, 'completed' => false, 'message' => get_string('completionrecordmissing', 'format_simple')];
        }

        // Unit and activity acknowledgement are plugin prerequisites. A configured
        // Moodle self-completion criterion is the supported mechanism for submitting
        // this acknowledgement to Moodle's course-completion system.
        $coursecompletion->mark_inprogress();
        $coursecompletion->reaggregate = time();
        $coursecompletion->update();

        $selfcompletion = $completion->get_completion($USER->id, COMPLETION_CRITERIA_TYPE_SELF);
        if ($selfcompletion && !$selfcompletion->is_complete()) {
            $criteria = \completion_criteria_self::fetch([
                'course' => $course->id,
                'criteriatype' => COMPLETION_CRITERIA_TYPE_SELF,
            ]);
            if ($criteria) {
                $criteria->complete($selfcompletion);
            }
        }

        aggregate_completions((int) $coursecompletion->id);

        $completedtime = $DB->get_field('course_completions', 'timecompleted', [
            'userid' => $USER->id,
            'course' => $course->id,
        ]);
        $completed = !empty($completedtime);
        if (!$completed) {
            return [
                'success' => false,
                'completed' => false,
                'message' => get_string('moodlecriteriaunmet', 'format_simple'),
            ];
        }
        return [
            'success' => true,
            'completed' => true,
            'message' => get_string('coursecompletedconfirmed', 'format_simple'),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether Finish was accepted'),
            'completed' => new external_value(PARAM_BOOL, 'Whether Moodle confirms course completion'),
            'message' => new external_value(PARAM_TEXT, 'Human-readable status'),
        ]);
    }
}
