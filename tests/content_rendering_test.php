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

namespace format_simple;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for the course-level content template.
 *
 * @package    format_simple
 * @copyright  2026 South African Theological Seminary <ict@sats.ac.za>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \format_simple\output\courseformat\content
 */
final class content_rendering_test extends \advanced_testcase {
    /**
     * Export the course content for the requested section.
     *
     * @param \stdClass $course The course.
     * @param int $section The active section number.
     * @return \stdClass Template data.
     */
    protected function export_content(\stdClass $course, int $section): \stdClass {
        global $PAGE;

        $hadsection = array_key_exists('section', $_GET);
        $previoussection = $_GET['section'] ?? null;
        $_GET['section'] = $section;

        try {
            $PAGE->set_url('/course/view.php', ['id' => $course->id, 'section' => $section]);
            $format = course_get_format($course->id);
            $renderer = $format->get_renderer($PAGE);
            $outputclass = $format->get_output_classname('content');
            $widget = new $outputclass($format);

            return $widget->export_for_template($renderer);
        } finally {
            if ($hadsection) {
                $_GET['section'] = $previoussection;
            } else {
                unset($_GET['section']);
            }
        }
    }

    /**
     * Keep the course name visible on unit pages without duplicating the Course Info banner.
     */
    public function test_course_title_is_shown_on_units_and_not_duplicated_in_banner(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course([
            'format' => 'simple',
            'fullname' => 'Welcome @ Inther Group!',
            'numsections' => 2,
        ], ['createsections' => true]);

        $data = $this->export_content($course, 1);
        $this->assertSame('Welcome @ Inther Group!', $data->coursefullname);

        $renderer = course_get_format($course->id)->get_renderer($PAGE);
        $html = $renderer->render_from_template('format_simple/local/content', $data);
        $this->assertStringContainsString(
            '<h1 class="simple-course-title">Welcome @ Inther Group!</h1>',
            $html
        );

        $data = $this->export_content($course, 0);
        $html = $renderer->render_from_template('format_simple/local/content', $data);
        $this->assertStringContainsString(
            '<div class="simple-banner',
            $html,
            'The existing banner remains available alongside the global title, with CSS avoiding duplication.'
        );
    }
}
