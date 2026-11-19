<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for format_simple.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_format_simple_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026111901) {
        $table = new xmldb_table('format_simple_progress');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('course', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('section', XMLDB_KEY_FOREIGN, ['sectionid'], 'course_sections', ['id']);
            $table->add_index('course-user-section', XMLDB_INDEX_UNIQUE, ['courseid', 'userid', 'sectionid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026111901, 'format', 'simple');
    }

    return true;
}
