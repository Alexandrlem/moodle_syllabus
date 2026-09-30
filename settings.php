<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.


/**
 * Admin settings for local_syllabus.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_syllabus', get_string('pluginname', 'local_syllabus'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading('local_syllabus/institution',
            get_string('settings_institution', 'local_syllabus'), ''));
        $settings->add(new admin_setting_configtext('local_syllabus/ministry',
            get_string('settings_ministry', 'local_syllabus'), '',
            get_string('default_ministry', 'local_syllabus')));
        $settings->add(new admin_setting_configtext('local_syllabus/university',
            get_string('settings_university', 'local_syllabus'), '',
            get_string('default_university', 'local_syllabus')));
        $settings->add(new admin_setting_configtext('local_syllabus/city',
            get_string('settings_city', 'local_syllabus'), '',
            get_string('default_city', 'local_syllabus')));

        $settings->add(new admin_setting_heading('local_syllabus/generation',
            get_string('settings_generation', 'local_syllabus'),
            get_string('settings_generation_desc', 'local_syllabus')));
        $settings->add(new admin_setting_configtext('local_syllabus/defaultcredits',
            get_string('settings_defaultcredits', 'local_syllabus'), '', '3', PARAM_FLOAT));
        $settings->add(new admin_setting_configtext('local_syllabus/hourspercredit',
            get_string('settings_hourspercredit', 'local_syllabus'), '', '30', PARAM_INT));
        $settings->add(new admin_setting_configtextarea('local_syllabus/defaultpolicy',
            get_string('settings_defaultpolicy', 'local_syllabus'), '',
            get_string('default_policy', 'local_syllabus')));
        $settings->add(new admin_setting_configtextarea('local_syllabus/defaulttech',
            get_string('settings_defaulttech', 'local_syllabus'), '',
            get_string('default_tech', 'local_syllabus')));
        $settings->add(new admin_setting_configtextarea('local_syllabus/defaultfinalevaluation',
            get_string('settings_defaultfinalevaluation', 'local_syllabus'), '',
            get_string('default_finalevaluation', 'local_syllabus')));
        $settings->add(new admin_setting_configcheckbox('local_syllabus/enableai',
            get_string('settings_enableai', 'local_syllabus'),
            get_string('settings_enableai_desc', 'local_syllabus'), 1));
    }
}
