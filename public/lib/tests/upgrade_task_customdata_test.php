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

namespace core;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Migration of existing task payloads to direct JSON storage.
 *
 * @package core
 * @copyright 2026 Anupama Dharmajan <anupama.dharmajan@catalyst-au.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('upgrade_task_adhoc_customdata')]
final class upgrade_task_customdata_test extends \advanced_testcase {
    /**
     * Invalid source data stops migration before normalisation or conversion; correction permits a retry.
     *
     * @param string $invalid invalid source value
     */
    #[DataProvider('invalid_values_provider')]
    public function test_failure_correction_and_retry(string $invalid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/db/upgradelib.php');
        $this->resetAfterTest();
        $table = new \xmldb_table('test_task_json_upgrade');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('customdata', XMLDB_TYPE_TEXT);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman = $DB->get_manager();
        $dbman->create_table($table);
        try {
            $values = ['', null, '{}', '[]', '{"id":42,"reset_events":1}', '{"data":{"name":"日本語"}}', '[1,null,false]'];
            $ids = [];
            foreach ($values as $value) {
                $ids[] = $DB->insert_record($table->getName(), (object) ['customdata' => $value]);
            }
            $invalidid = $DB->insert_record($table->getName(), (object) ['customdata' => $invalid]);
            try {
                upgrade_task_adhoc_customdata($table);
                $this->fail('Invalid or scalar JSON must stop the upgrade.');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidjsonmigration', $e->errorcode);
                $this->assertStringContainsString('test_task_json_upgrade.customdata', $e->getMessage());
                $this->assertStringContainsString("record ID $invalidid", $e->getMessage());
            }
            $this->assertSame('X', $DB->get_columns($table->getName())['customdata']->meta_type);
            foreach ($ids as $key => $id) {
                $this->assertSame($values[$key], $DB->get_field($table->getName(), 'customdata', ['id' => $id]));
            }
            $this->assertSame($invalid, $DB->get_field($table->getName(), 'customdata', ['id' => $invalidid]));

            $DB->set_field($table->getName(), 'customdata', '{"corrected":true}', ['id' => $invalidid]);
            upgrade_task_adhoc_customdata($table);
            $first = $DB->get_records($table->getName(), null, 'id');
            // Simulate a retry after conversion completed but before the upgrade savepoint.
            upgrade_task_adhoc_customdata($table);
            $this->assertEquals($first, $DB->get_records($table->getName(), null, 'id'));
            foreach ($ids as $key => $id) {
                $stored = $DB->get_field($table->getName(), 'customdata', ['id' => $id]);
                if ($values[$key] === '' || $values[$key] === null) {
                    $this->assertNull($stored);
                } else {
                    $this->assertEquals(json_decode($values[$key]), json_decode($stored));
                }
            }
            $this->assertFalse($dbman->field_exists($table, 'customdataformat'));
            $table->getField('customdata')->setType(XMLDB_TYPE_JSON);
            $schema = new \xmldb_structure('task_json');
            $schema->addTable($table);
            $this->assertSame([], $dbman->check_database_schema($schema, ['extratables' => false]));
        } finally {
            $dbman->drop_table($table);
        }
    }

    /**
     * A partially normalised table can be retried, including across the migration batch boundary.
     */
    public function test_resume_partial_normalisation(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/db/upgradelib.php');
        $this->resetAfterTest();
        $table = new \xmldb_table('test_task_json_resume');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('customdata', XMLDB_TYPE_TEXT);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman = $DB->get_manager();
        $dbman->create_table($table);
        try {
            $normalisedid = $DB->insert_record($table->getName(), (object) ['customdata' => null]);
            $emptyid = $DB->insert_record($table->getName(), (object) ['customdata' => '']);
            $batch = [];
            for ($i = 0; $i < 501; $i++) {
                $batch[] = (object) ['customdata' => '{"data":42}'];
            }
            $DB->insert_records($table->getName(), $batch);
            $lastemptyid = $DB->insert_record($table->getName(), (object) ['customdata' => '']);
            upgrade_task_adhoc_customdata($table);
            $this->assertSame(504, $DB->count_records($table->getName()));
            foreach ([$normalisedid, $emptyid, $lastemptyid] as $id) {
                $this->assertNull($DB->get_field($table->getName(), 'customdata', ['id' => $id]));
            }
            $records = $DB->get_records($table->getName());
            foreach ($records as $record) {
                if ($record->customdata !== null) {
                    $this->assertEquals((object) ['data' => 42], json_decode($record->customdata));
                }
            }
        } finally {
            $dbman->drop_table($table);
        }
    }

    /**
     * Malformed JSON and scalars are incompatible with portable task JSON storage.
     *
     * @return array
     */
    public static function invalid_values_provider(): array {
        return [
            'malformed' => ['{invalid json'],
            'extra member' => ['1,"other":2'],
            'scalar string' => ['"Task 1"'],
            'scalar number' => ['42'],
            'scalar boolean' => ['true'],
            'JSON null' => ['null'],
            'whitespace only' => ['   '],
        ];
    }
}
