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
 * Validation before converting existing text fields to JSON.
 *
 * @package core
 * @copyright 2026 Anupama Dharmajan <anupama.dharmajan@catalyst-au.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('upgrade_validate_json_field')]
final class upgrade_json_test extends \advanced_testcase {
    /**
     * Validation preserves data, reports failures and allows a corrected migration to be retried.
     *
     * @param string|null $value existing text value
     * @param bool $notnull target nullability
     * @param bool $valid whether validation should succeed
     * @param bool $allowempty whether empty strings may be normalised by the migration
     */
    #[DataProvider('values_provider')]
    public function test_validation(?string $value, bool $notnull, bool $valid, bool $allowempty = false): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/db/upgradelib.php');
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('test_json_upgrade');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('document', XMLDB_TYPE_TEXT);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);
        try {
            $id = $DB->insert_record($table->getName(), (object) ['document' => $value]);
            $field = new \xmldb_field('document', XMLDB_TYPE_JSON, null, null, $notnull);
            try {
                upgrade_validate_json_field($table, $field, $allowempty);
                $this->assertTrue($valid, 'Invalid data must prevent migration.');
            } catch (\moodle_exception $e) {
                $this->assertFalse($valid);
                $this->assertSame('invalidjsonmigration', $e->errorcode);
                $this->assertStringContainsString('test_json_upgrade.document', $e->getMessage());
                $this->assertStringContainsString("record ID $id", $e->getMessage());
            }
            $this->assertSame($value, $DB->get_field($table->getName(), 'document', ['id' => $id]));
            $this->assertSame('X', $DB->get_columns($table->getName())['document']->meta_type);

            if (!$valid) {
                $value = '{"corrected":true}';
                $DB->set_field($table->getName(), 'document', $value, ['id' => $id]);
            }
            upgrade_validate_json_field($table, $field, $allowempty);
            if ($allowempty && $value === '') {
                // Validation is read-only; the caller must explicitly normalise before conversion.
                $value = null;
                $DB->set_field($table->getName(), 'document', null, ['id' => $id]);
            }
            $dbman->change_field_type($table, $field);
            $stored = $DB->get_field($table->getName(), 'document', ['id' => $id]);
            if ($value === null) {
                $this->assertNull($stored);
            } else {
                $this->assertEquals(json_decode($value), json_decode($stored));
            }
        } finally {
            $dbman->drop_table($table);
        }
    }

    /**
     * Provide existing values and expected validation results.
     *
     * @return array
     */
    public static function values_provider(): array {
        return [
            'object' => ['{"name":"日本語","nested":{"enabled":true}}', true, true],
            'array' => ['[1,null,{"enabled":false}]', false, true],
            'nullable SQL null' => [null, false, true],
            'required SQL null' => [null, true, false],
            'malformed JSON' => ['{invalid json', false, false],
            'empty string' => ['', false, false],
            'empty nullable opt-in' => ['', false, true, true],
            'empty required opt-in' => ['', true, false, true],
            'JSON null' => ['null', false, false],
            'scalar string' => ['"Task 1"', false, false],
            'scalar number' => ['42', false, false],
            'scalar boolean' => ['true', false, false],
        ];
    }
}
