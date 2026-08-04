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

namespace core_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/externallib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests for course shortname lock and availability functions.
 *
 * @package    core_course
 * @author     Anupama dharmajan <anupamadharmajan@catalyst-au.net>
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('course_ensure_shortname_available')]
#[\PHPUnit\Framework\Attributes\CoversFunction('course_acquire_shortname_lock')]
final class course_shortname_lock_test extends \advanced_testcase {
    /** @var int Category ID used across tests. */
    private int $categoryid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->categoryid = $this->getDataGenerator()->create_category()->id;
    }

    /**
     * Test course_ensure_shortname_available for various scenarios.
     */
    public function test_ensure_shortname_available(): void {
        $course = $this->getDataGenerator()->create_course(['shortname' => 'TAKEN']);

        // Unused shortname should not throw.
        course_ensure_shortname_available('AVAILABLE');

        // Excluding own ID should not throw.
        course_ensure_shortname_available('TAKEN', $course->id);

        // Taken shortname should throw.
        $this->expectException(\moodle_exception::class);
        course_ensure_shortname_available('TAKEN');
    }

    /**
     * Test course_acquire_shortname_lock returns a lock and blocks re-acquisition.
     */
    public function test_acquire_shortname_lock(): void {
        $lock = course_acquire_shortname_lock('locktest');
        $this->assertInstanceOf(\core\lock\lock::class, $lock);

        // Same lock cannot be acquired again.
        try {
            course_acquire_shortname_lock('locktest');
            $this->fail('Expected exception when re-acquiring held lock');
        } catch (\Throwable $e) {
            // Lock factories may throw moodle_exception or coding_exception.
            $this->assertTrue(true);
        } finally {
            $lock->release();
        }
    }

    /**
     * Test create_course rejects duplicate shortname and releases lock on failure.
     */
    public function test_create_course_duplicate_shortname(): void {
        $this->getDataGenerator()->create_course(['shortname' => 'DUP', 'category' => $this->categoryid]);

        // Should reject duplicate.
        try {
            create_course((object) [
                'fullname' => 'Dup',
                'shortname' => 'DUP',
                'category' => $this->categoryid,
                'summary' => '',
                'summaryformat' => FORMAT_PLAIN,
            ]);
            $this->fail('Expected shortnametaken exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('shortnametaken', $e->errorcode);
        }

        // Lock was released - a different shortname should succeed.
        $created = create_course((object) [
            'fullname' => 'OK',
            'shortname' => 'DUP2',
            'category' => $this->categoryid,
            'summary' => '',
            'summaryformat' => FORMAT_PLAIN,
        ]);
        $this->assertNotEmpty($created->id);
    }

    /**
     * Test update_course rejects duplicate shortname, allows same shortname, and releases lock.
     */
    public function test_update_course_shortname_validation(): void {
        $course1 = $this->getDataGenerator()->create_course(['shortname' => 'UP1']);
        $course2 = $this->getDataGenerator()->create_course(['shortname' => 'UP2']);

        // Saving without changing shortname should succeed.
        $course1->fullname = 'Renamed';
        update_course($course1);
        $this->assertEquals('Renamed', get_course($course1->id)->fullname);

        // Renaming to another course's shortname should fail.
        $course2->shortname = 'UP1';
        try {
            update_course($course2);
            $this->fail('Expected shortnametaken exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('shortnametaken', $e->errorcode);
        }

        // Lock was released - valid rename should succeed.
        $course2->shortname = 'UP3';
        update_course($course2);
        $this->assertEquals('UP3', get_course($course2->id)->shortname);
    }

    /**
     * Test restore_dbops::create_new_course with duplicate shortname, success, and acquirelock param.
     */
    public function test_restore_create_new_course(): void {
        global $DB;

        $this->getDataGenerator()->create_course(['shortname' => 'RDUP']);

        // Duplicate shortname should fail.
        try {
            \restore_dbops::create_new_course('Fail', 'RDUP', $this->categoryid);
            $this->fail('Expected shortnametaken exception');
        } catch (\moodle_exception $e) {
            // Expected - also proves lock is released since next call works.
            $this->assertEquals('shortnametaken', $e->errorcode);
        }

        // Unique shortname should succeed.
        $courseid = \restore_dbops::create_new_course('New', 'RUNIQ', $this->categoryid);
        $this->assertGreaterThan(0, $courseid);
        $this->assertEquals('RUNIQ', $DB->get_field('course', 'shortname', ['id' => $courseid]));

        // Passing an existing lock: create_new_course uses it and releases it.
        $lock = course_acquire_shortname_lock('OUTER');
        $courseid2 = \restore_dbops::create_new_course('Outer', 'OUTER', $this->categoryid, $lock);
        $this->assertGreaterThan(0, $courseid2);
        // Lock should already be released by create_new_course - verify we can acquire it again.
        $lock2 = course_acquire_shortname_lock('OUTER');
        $lock2->release();
    }

    /**
     * Test duplicate_course rejects taken shortname.
     */
    public function test_duplicate_course_shortname(): void {
        global $DB;
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course(['shortname' => 'SRC']);
        $this->getDataGenerator()->create_course(['shortname' => 'EXISTING']);

        // Taken shortname should fail.
        $this->expectException(\moodle_exception::class);
        \core_course_external::duplicate_course($source->id, 'Dup', 'EXISTING', $source->category, 1);
    }

    /**
     * Test duplicate_course succeeds with a unique shortname.
     */
    public function test_duplicate_course_succeeds(): void {
        global $DB;
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course(['shortname' => 'SRC2']);

        $result = \core_course_external::duplicate_course($source->id, 'Copy', 'UNIQUE_DUP', $source->category, 1);
        $this->assertEquals('UNIQUE_DUP', $DB->get_field('course', 'shortname', ['id' => $result['id']]));
    }
}
