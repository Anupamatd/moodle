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
 * CLI tool to detect and fix duplicate course shortnames.
 *
 * Lists all duplicate course shortnames so administrators can review them.
 * When run with --fix, renames duplicates by appending a numeric suffix
 * (_1, _2, etc.), keeping the most recently accessed course unchanged.
 *
 * @package    core
 * @subpackage cli
 * @author     Anupama Dharmajan <anupama.dharmajan@catalyst-au.net>
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/db/upgradelib.php');

// Get CLI options.
[$options, $unrecognized] = cli_get_params(
    [
        'fix' => false,
        'help' => false,
    ],
    [
        'f' => 'fix',
        'h' => 'help',
    ]
);

if ($options['help']) {
    $help = <<<EOT
Checks and fixes duplicate course shortnames.

Lists all courses that share the same shortname. If --fix is specified,
duplicates are renamed by appending a numeric suffix (_1, _2, etc.),
keeping the most recently accessed course unchanged.

Options:
-f, --fix             Fix duplicates by appending a numeric suffix
-h, --help            Print out this help

Example:
\$sudo -u www-data /usr/bin/php admin/cli/fix_course_shortnames.php
\$sudo -u www-data /usr/bin/php admin/cli/fix_course_shortnames.php --fix

EOT;

    echo $help;
    exit(0);
}

// Find all shortnames that have duplicates.
$duplicates = upgrade_get_courses_with_duplicate_shortnames();

if (empty($duplicates)) {
    cli_writeln('No duplicate course shortnames found.');
    exit(0);
}

if (!empty($options['fix'])) {
    // Fix duplicates.
    $renames = upgrade_fix_duplicate_course_shortnames();
    cli_writeln('Fixed ' . count($duplicates) . ' shortname(s) with duplicates:');
    cli_writeln('');
    foreach ($renames as $rename) {
        cli_writeln("  Course ID {$rename->id}: \"{$rename->shortname}\" renamed to \"{$rename->newshortname}\"");
    }
} else {
    // List-only mode: show details for review.
    cli_writeln('Found ' . count($duplicates) . ' shortname(s) with duplicates:');
    cli_writeln('');

    foreach ($duplicates as $shortname => $courses) {
        $count = count($courses);
        cli_writeln("  Shortname: \"{$shortname}\" ({$count} courses)");

        foreach ($courses as $course) {
            $lastaccess = $course->lastaccess ? userdate($course->lastaccess) : 'never';
            cli_writeln("    - Course ID: {$course->id}, Full name: \"{$course->fullname}\", Last access: {$lastaccess}");
        }

        cli_writeln('');
    }

    cli_writeln('To fix, run:');
    cli_writeln('$sudo -u www-data /usr/bin/php admin/cli/fix_course_shortnames.php --fix');
}
