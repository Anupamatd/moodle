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
 * Read-only validation of existing data before a JSON field migration.
 *
 * @package core
 * @copyright 2026 Anupama Dharmajan <anupama.dharmajan@catalyst-au.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
define('NO_UPGRADE_CHECK', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/ddllib.php');
require_once($CFG->libdir . '/db/upgradelib.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'table' => null,
    'field' => null,
    'notnull' => false,
    'allowempty' => false,
], ['h' => 'help']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n", $unrecognized)));
}
if ($options['help']) {
    echo "Validate a field before converting it to portable XMLDB JSON. No data is changed.

Options:
--table=NAME  Table name without the database prefix.
--field=NAME  Existing field to validate.
--notnull     Reject SQL NULL for a required target field.
--allowempty  Allow empty strings in nullable fields if the migration will convert them to SQL NULL.
-h, --help    Show this help.

Example:
php admin/cli/validate_json_field.php --table=task_adhoc --field=customdata

Stops at the first invalid record and exits with a non-zero status.
Stop writers and repeat validation during the upgrade before changing the type.
";
    exit(0);
}
foreach (['table', 'field'] as $option) {
    if (!is_string($options[$option]) || !preg_match('/^[a-z][a-z0-9_]*$/', $options[$option])) {
        cli_error("--$option must be a valid XMLDB identifier.");
    }
}
$table = new xmldb_table($options['table']);
$field = new xmldb_field($options['field'], XMLDB_TYPE_JSON, null, null, $options['notnull']);
try {
    upgrade_validate_json_field($table, $field, $options['allowempty']);
} catch (moodle_exception $e) {
    cli_error($e->getMessage());
}
cli_writeln('Validation passed. No data or schema was changed.');
