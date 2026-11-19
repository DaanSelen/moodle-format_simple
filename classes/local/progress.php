<?php
// This file is part of Moodle - http://moodle.org/
//
// @package    format_simple
// @copyright  2026 South African Theological Seminary <ict@sats.ac.za>
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

namespace format_simple\local;

/**
 * Persistent per-unit progress and final course-completion validation.
 */
class progress {
    /**
     * Whether a learner has acknowledged a unit.
     */
    public static function is_section_complete(int $courseid, int $userid, int $sectionid): bool {
        global $DB;
        return $DB->record_exists('format_simple_progress', [
            'courseid' => $courseid,
            'userid' => $userid,
            'sectionid' => $sectionid,
        ]);
    }

    /**
     * Record a unit acknowledgement exactly once.
     */
    public static function mark_section_complete(int $courseid, int $userid, int $sectionid): void {
        global $DB;
        if (self::is_section_complete($courseid, $userid, $sectionid)) {
            return;
        }
        $DB->insert_record('format_simple_progress', (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'sectionid' => $sectionid,
            'timecompleted' => time(),
        ]);
    }

    /**
     * Remove acknowledgements for units that no longer belong to the course.
     */
    public static function cleanup_course(int $courseid): void {
        global $DB;
        $validids = $DB->get_fieldset_select('course_sections', 'id', 'course = :course', ['course' => $courseid]);
        if (empty($validids)) {
            $DB->delete_records('format_simple_progress', ['courseid' => $courseid]);
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($validids, SQL_PARAMS_NAMED, 'section');
        $params['courseid'] = $courseid;
        $DB->execute(
            "DELETE FROM {format_simple_progress}
              WHERE courseid = :courseid AND sectionid NOT IN ($insql)",
            $params
        );
    }

    /**
     * Return incomplete accessible units and incomplete tracked activities.
     *
     * @return array{units:string[], activities:string[]}
     */
    public static function get_incomplete_requirements(\stdClass $course, int $userid): array {
        global $USER;
        $modinfo = get_fast_modinfo($course, $userid);
        $completion = new \completion_info($course);
        $units = [];
        $activities = [];

        foreach ($modinfo->get_section_info_all() as $section) {
            if ((int) $section->section === 0 || !$section->uservisible) {
                continue;
            }
            if (!self::is_section_complete($course->id, $userid, (int) $section->id)) {
                $units[] = format_string($section->name ?: get_section_name($course, $section));
            }
        }

        if ($completion->is_enabled()) {
            foreach ($modinfo->get_cms() as $cm) {
                if (!$cm->uservisible || $cm->completion === COMPLETION_TRACKING_NONE) {
                    continue;
                }
                $data = $completion->get_data($cm, true, $userid);
                $state = (int) $data->completionstate;
                if (!in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                    $activities[] = $cm->get_formatted_name();
                }
            }
        }

        return ['units' => $units, 'activities' => $activities];
    }
}
