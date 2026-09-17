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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * JSON field definitions and SQL generation.
 *
 * @package core
 * @copyright 2026 Anupama Dharmajan (anupamadharmajan@catalyst-au.net)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\xmldb_field::class)]
#[CoversClass(\sql_generator::class)]
final class json_test extends \basic_testcase {
    /**
     * Load the database-independent XMLDB and SQL generator classes.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/ddllib.php');
        require_once($CFG->libdir . '/dml/mariadb_native_moodle_database.php');
        foreach (['mysql', 'postgres', 'mssql'] as $family) {
            require_once($CFG->libdir . '/ddl/' . $family . '_sql_generator.php');
        }
    }

    /**
     * JSON survives the XMLDB editor's XML and PHP representations.
     */
    public function test_definition(): void {
        $field = new \xmldb_field('document', XMLDB_TYPE_JSON);
        $this->assertSame('json', $field->getXMLDBTypeName(XMLDB_TYPE_JSON));
        $this->assertSame(XMLDB_TYPE_JSON, $field->getXMLDBFieldType('json'));
        $this->assertStringContainsString('TYPE="json"', $field->xmlOutput());
        $this->assertStringContainsString('XMLDB_TYPE_JSON', $field->getPHP());
        $this->assertStringNotContainsString('LENGTH=', $field->xmlOutput());
        $this->assertNull($field->validateDefinition(new \xmldb_table('example')));

        $loaded = new \xmldb_field('document');
        $this->assertTrue($loaded->arr2xmldb_field(['@' => [
            'NAME' => 'document', 'TYPE' => 'json', 'NOTNULL' => 'false', 'SEQUENCE' => 'false',
        ]]));
        $this->assertSame($field->xmlOutput(), $loaded->xmlOutput());

        $field->setSequence(true);
        $this->assertNotNull($field->validateDefinition(new \xmldb_table('example')));
    }

    /**
     * JSON fields without length validate against both XML schema formats.
     */
    public function test_xml_schemas(): void {
        global $CFG;
        $field = new \xmldb_field('document', XMLDB_TYPE_JSON);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($field->xmlOutput()));
        $this->assertTrue($document->schemaValidate($CFG->libdir . '/xmldb/xmldb.xsd'));
        $doctype = '<!DOCTYPE FIELD SYSTEM "' . $CFG->libdir . '/xmldb/xmldb.dtd">';
        $this->assertTrue($document->loadXML($doctype . $field->xmlOutput()));
        $this->assertTrue($document->validate());
    }

    /**
     * Native PostgreSQL JSON types are both imported as XMLDB JSON.
     */
    public function test_import(): void {
        foreach (['json', 'jsonb'] as $type) {
            $field = new \xmldb_field('document');
            $field->setFromADOField(new \database_column_info((object) [
                'name' => 'document', 'type' => $type, 'meta_type' => 'J', 'max_length' => -1,
                'not_null' => false, 'has_default' => false, 'auto_increment' => false,
            ]));
            $this->assertSame(XMLDB_TYPE_JSON, $field->getType());
            $this->assertNull($field->getLength());
        }
    }

    /**
     * Ordinary indexes on JSON are not portable.
     */
    public function test_index(): void {
        $table = new \xmldb_table('example');
        $table->add_field('document', XMLDB_TYPE_JSON);
        $index = new \xmldb_index('document', XMLDB_INDEX_NOTUNIQUE, ['document']);
        $this->assertNotNull($index->validateDefinition($table));
    }

    /**
     * Native SQL types and SQL Server fallback are generated without defaults or precision.
     *
     * @param string $class generator class
     * @param bool $nativejson whether SQL Server has native JSON
     * @param string $expected expected native type
     */
    #[DataProvider('sql_types_provider')]
    public function test_sql_types(string $class, bool $nativejson, string $expected): void {
        $db = $this->createStub(\moodle_database::class);
        $db->method('get_prefix')->willReturn('mdl_');
        $db->method('get_field_sql')->willReturn($nativejson ? 244 : null);
        $generator = new $class($db);
        $field = new \xmldb_field('document', XMLDB_TYPE_JSON);
        $this->assertSame($expected, $generator->getTypeSQL(XMLDB_TYPE_JSON));
        $this->assertNull($generator->getDefaultValue($field));
    }

    /**
     * PostgreSQL needs an explicit cast when converting existing text documents.
     */
    public function test_postgres_conversion(): void {
        $db = $this->createStub(\moodle_database::class);
        $db->method('get_prefix')->willReturn('mdl_');
        $db->method('get_columns')->willReturn([
            'document' => new \database_column_info((object) [
                'name' => 'document', 'type' => 'text', 'meta_type' => 'X', 'max_length' => -1,
                'not_null' => false, 'has_default' => false,
            ]),
        ]);
        $generator = new \postgres_sql_generator($db);
        $sql = $generator->getAlterFieldSQL(new \xmldb_table('example'), new \xmldb_field('document', XMLDB_TYPE_JSON));
        $this->assertStringContainsString('USING CAST(document AS JSONB)', implode(';', $sql));
    }

    /**
     * MariaDB must distinguish actual JSON constraints from text in the table definition.
     *
     * @param string $definition table definition
     * @param string $column column being inspected
     * @param bool $expected whether a JSON constraint should be detected
     */
    #[DataProvider('mariadb_constraints_provider')]
    public function test_mariadb_json_constraint(string $definition, string $column, bool $expected): void {
        $class = new \ReflectionClass(\mariadb_native_moodle_database::class);
        $driver = $class->newInstanceWithoutConstructor();
        $method = $class->getMethod('has_json_constraint');
        $this->assertSame($expected, $method->invoke($driver, $definition, $column));
    }

    /**
     * Provide JSON constraint detection cases.
     *
     * @return array JSON constraint detection cases
     */
    public static function mariadb_constraints_provider(): array {
        // MariaDB SHOW CREATE TABLE output contains backtick-quoted identifiers.
        // phpcs:disable moodle.Strings.ForbiddenStrings.Found
        return [
            'real constraint' => ['CREATE TABLE `t` (`doc` longtext CHECK (json_valid(`doc`)))', 'doc', true],
            'column comment' => ["CREATE TABLE `t` (`doc` longtext COMMENT 'CHECK (json_valid(`doc`))')", 'doc', false],
            'table comment' => ["CREATE TABLE `t` (`doc` longtext) COMMENT='CHECK (json_valid(`doc`))'", 'doc', false],
            'default string' => ["CREATE TABLE `t` (`doc` longtext DEFAULT 'CHECK (json_valid(`doc`))')", 'doc', false],
            'doubled quote' => ["CREATE TABLE `t` (`doc` longtext COMMENT 'It''s CHECK (json_valid(`doc`))')", 'doc', false],
            'backslash quote' => ["CREATE TABLE `t` (`doc` longtext COMMENT 'It\\'s CHECK (json_valid(`doc`))')", 'doc', false],
            'double quoted string' => ['CREATE TABLE `t` (`doc` longtext COMMENT "CHECK (json_valid(`doc`))")', 'doc', false],
            'block comment' => ['CREATE TABLE `t` (`doc` longtext /* CHECK (json_valid(`doc`)) */)', 'doc', false],
            'line comment' => ["CREATE TABLE `t` (`doc` longtext -- CHECK (json_valid(`doc`))\n)", 'doc', false],
            'hash comment' => ["CREATE TABLE `t` (`doc` longtext # CHECK (json_valid(`doc`))\n)", 'doc', false],
            'quoted identifier' => ['CREATE TABLE `CHECK (json_valid(``doc``))` (`doc` longtext)', 'doc', false],
            'other column' => ['CREATE TABLE `t` (`doc` longtext, `other` longtext CHECK (json_valid(`other`)))', 'doc', false],
            'comment before real constraint' => [
                "CREATE TABLE `t` (`doc` longtext COMMENT 'CHECK (json_valid(`other`))' CHECK (json_valid(`doc`)))",
                'doc', true,
            ],
            'case and whitespace' => ["CREATE TABLE `t` (`doc` longtext check ( JSON_VALID (\n`doc` ) ))", 'doc', true],
            'escaped identifier' => ['CREATE TABLE `t` (`a``b` longtext CHECK (json_valid(`a``b`)))', 'a`b', true],
        ];
        // phpcs:enable moodle.Strings.ForbiddenStrings.Found
    }

    /**
     * Provide SQL generator cases.
     *
     * @return array SQL generator cases
     */
    public static function sql_types_provider(): array {
        return [
            'mysql and mariadb' => [\mysql_sql_generator::class, true, 'JSON'],
            'postgres' => [\postgres_sql_generator::class, true, 'JSONB'],
            'sql server native' => [\mssql_sql_generator::class, true, 'JSON'],
            'sql server fallback' => [\mssql_sql_generator::class, false, 'NVARCHAR(MAX) COLLATE database_default'],
        ];
    }
}
