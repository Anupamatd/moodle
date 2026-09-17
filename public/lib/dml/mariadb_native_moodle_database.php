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

/**
 * Native MariaDB class representing moodle database interface.
 *
 * @package    core_dml
 * @copyright  2013 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/moodle_database.php');
require_once(__DIR__.'/mysqli_native_moodle_database.php');
require_once(__DIR__.'/mysqli_native_moodle_recordset.php');
require_once(__DIR__.'/mysqli_native_moodle_temptables.php');

/**
 * Native MariaDB class representing moodle database interface.
 *
 * @package    core_dml
 * @copyright  2013 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mariadb_native_moodle_database extends mysqli_native_moodle_database {

    /**
     * Returns localised database type name
     * Note: can be used before connect()
     * @return string
     */
    public function get_name() {
        return get_string('nativemariadb', 'install');
    }

    /**
     * Returns localised database configuration help.
     * Note: can be used before connect()
     * @return string
     */
    public function get_configuration_help() {
        return get_string('nativemariadbhelp', 'install');
    }

    /**
     * Returns the database vendor.
     * Note: can be used before connect()
     * @return string The db vendor name, usually the same as db family name.
     */
    public function get_dbvendor() {
        return 'mariadb';
    }

    /**
     * Returns more specific database driver type
     * Note: can be used before connect()
     * @return string db type mysqli, pgsql, mssql, sqlsrv
     */
    protected function get_dbtype() {
        return 'mariadb';
    }

    /**
     * Recognise MariaDB's JSON alias, which is reported as LONGTEXT by the column metadata.
     *
     * @param string $table table name
     * @return database_column_info[] column information indexed by name
     */
    protected function fetch_columns(string $table): array {
        $columns = parent::fetch_columns($table);
        if (!$columns || !array_filter($columns, fn($column) => $column->type === 'longtext')) {
            return $columns;
        }

        // SHOW CREATE TABLE includes the JSON_VALID constraint, including for temporary tables.
        $definition = $this->get_record_sql('SHOW CREATE TABLE ' . $this->fix_table_name($table));
        $values = array_values((array) $definition);
        $createsql = $values[1];
        foreach ($columns as $name => $column) {
            if ($column->type !== 'longtext' || !$this->has_json_constraint($createsql, $name)) {
                continue;
            }
            $info = new stdClass();
            foreach (['name', 'type', 'max_length', 'scale', 'not_null', 'primary_key', 'auto_increment',
                    'binary', 'has_default', 'default_value', 'unique', 'meta_type'] as $property) {
                $info->$property = $column->$property;
            }
            $info->type = 'json';
            $info->meta_type = 'J';
            $info->max_length = -1;
            $columns[$name] = new database_column_info($info);
        }
        return $columns;
    }

    /**
     * Check for a JSON_VALID constraint outside quoted text and SQL comments.
     *
     * @param string $createsql SQL returned by SHOW CREATE TABLE
     * @param string $name unquoted column name
     * @return bool whether the column has a JSON_VALID check constraint
     */
    protected function has_json_constraint(string $createsql, string $name): bool {
        $quotedname = preg_quote('`' . str_replace('`', '``', $name) . '`', '~');
        // Skip complete quoted tokens so COMMENT/default values and identifiers cannot impersonate a constraint.
        // The constraint branch consumes its own quoted column name before scanning resumes.
        $tokens = <<<'REGEX'
'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|`(?:[^`]|``)*`|/\*.*?\*/|\#[^\r\n]*|--\s[^\r\n]*
REGEX;
        $pattern = '~(?:' . $tokens . ')(*SKIP)(*FAIL)|\bCHECK\s*\(\s*json_valid\s*\(\s*' .
            $quotedname . '\s*\)\s*\)~is';
        return preg_match($pattern, $createsql) === 1;
    }

    protected function has_breaking_change_quoted_defaults() {
        $version = $this->get_server_info()['version'];
        // Breaking change since 10.2.7: MDEV-13132.
        return version_compare($version, '10.2.7', '>=');
    }

    public function has_breaking_change_sqlmode() {
        $version = $this->get_server_info()['version'];
        // Breaking change since 10.2.4: https://mariadb.com/kb/en/the-mariadb-library/sql-mode/#setting-sql_mode.
        return version_compare($version, '10.2.4', '>=');
    }

    /**
     * It is time to require transactions everywhere.
     *
     * MyISAM is NOT supported!
     *
     * @return bool
     */
    protected function transactions_supported() {
        if ($this->external) {
            return parent::transactions_supported();
        }
        return true;
    }

    /**
     * Does this mariadb instance support fulltext indexes?
     *
     * @return bool
     */
    public function is_fulltext_search_supported() {
        $info = $this->get_server_info();

        if (version_compare($info['version'], '10.0.5', '>=')) {
            return true;
        }
        return false;
    }

    /**
     * MariaDB supports the COUNT() window function and provides a performance improvement.
     *
     * @return bool
     */
    public function is_count_window_function_supported(): bool {
        return true;
    }
}
