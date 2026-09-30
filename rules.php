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
 * Timeline exception rules management page.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$courseid = required_param('id', PARAM_INT);
$calendarid = required_param('calendarid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/courseplanner:manage', $context);

$calendar = local_courseplanner_require_course_calendar($calendarid, $courseid);
$blueprint = local_courseplanner_require_owned_blueprint((int)$calendar->blueprintid, (int)$USER->id);

$pageurl = new moodle_url('/local/courseplanner/rules.php', ['id' => $courseid, 'calendarid' => $calendarid]);
$builderurl = new moodle_url('/local/courseplanner/calendar.php', ['id' => $courseid, 'calendarid' => $calendarid]);

$ruletypes = local_courseplanner_get_rule_types();
$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

if ($action !== '' && data_submitted()) {
    require_sesskey();

    switch ($action) {
        case 'createrule':
            $ruletype = core_text::strtoupper(trim(required_param('ruletype', PARAM_ALPHANUMEXT)));
            $datestr = required_param('ruledate', PARAM_TEXT);
            $ruledate = strtotime($datestr);
            if (!$ruledate) {
                redirect(
                    $pageurl,
                    get_string('errorinvaliddate', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            $label = trim(optional_param('label', '', PARAM_TEXT));
            $description = trim(optional_param('description', '', PARAM_RAW));
            $fromday = trim(optional_param('fromday', '', PARAM_TEXT)) ?: null;
            $today = trim(optional_param('today', '', PARAM_TEXT)) ?: null;

            if (in_array($ruletype, ['SEMESTER_START', 'SEMESTER_END'], true)) {
                $existing = $DB->get_record('local_courseplanner_rules', [
                    'calendarid' => (int)$calendar->id,
                    'ruletype' => $ruletype,
                    'isactive' => 1,
                ], 'id', IGNORE_MISSING);
                if ($existing) {
                    redirect(
                        $pageurl,
                        get_string('errorrulestartendexists', 'local_courseplanner'),
                        null,
                        \core\output\notification::NOTIFY_ERROR
                    );
                }
            }

            local_courseplanner_create_rule(
                (int)$calendar->id,
                $ruletype,
                $ruledate,
                $label,
                $description,
                $fromday,
                $today,
                (int)$USER->id
            );
            redirect($pageurl, get_string('rulecreated', 'local_courseplanner'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'updaterule':
            $ruleid = required_param('ruleid', PARAM_INT);
            $datestr = required_param('ruledate', PARAM_TEXT);
            $ruledate = strtotime($datestr);
            if (!$ruledate) {
                redirect(
                    $pageurl,
                    get_string('errorinvaliddate', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            $label = trim(optional_param('label', '', PARAM_TEXT));
            $description = trim(optional_param('description', '', PARAM_RAW));
            $fromday = trim(optional_param('fromday', '', PARAM_TEXT)) ?: null;
            $today = trim(optional_param('today', '', PARAM_TEXT)) ?: null;

            local_courseplanner_update_rule($ruleid, $ruledate, $label, $description, $fromday, $today, (int)$USER->id);
            redirect($pageurl, get_string('ruleupdated', 'local_courseplanner'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'deleterule':
            $ruleid = required_param('ruleid', PARAM_INT);
            local_courseplanner_delete_rule($ruleid);
            redirect($pageurl, get_string('ruledeleted', 'local_courseplanner'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'togglerule':
            $ruleid = required_param('ruleid', PARAM_INT);
            $nowactive = local_courseplanner_toggle_rule($ruleid, (int)$USER->id);
            $msg = $nowactive
                ? get_string('ruleactivated', 'local_courseplanner')
                : get_string('ruledeactivated', 'local_courseplanner');
            redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'applyrules':
            try {
                $summary = local_courseplanner_apply_rules((int)$calendar->id, (int)$USER->id);
                $msg = get_string('rulesapplied', 'local_courseplanner', $summary['total_weeks']);
                redirect($builderurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
            } catch (moodle_exception $e) {
                redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
            }
            break;
    }
}

$rules = local_courseplanner_get_calendar_rules((int)$calendar->id);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('rulespagetitle', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/courseplanner/styles.css'));

echo $OUTPUT->header();
echo html_writer::start_tag('div', ['class' => 'local-courseplanner-pageheader']);
echo $OUTPUT->heading(get_string('rulespagetitle', 'local_courseplanner'));
echo html_writer::tag('button', get_string('showtourbtn', 'local_courseplanner'), [
    'type' => 'button',
    'id' => 'local-courseplanner-showtour',
    'class' => 'btn btn-sm btn-outline-info local-courseplanner-showtour',
    'data-tour-name' => 'local_courseplanner_rules',
]);
echo html_writer::end_tag('div');
echo html_writer::link($builderurl, get_string('backtobuilder', 'local_courseplanner'), ['class' => 'btn btn-secondary mb-3']);

echo html_writer::div(get_string('intro_rules', 'local_courseplanner'), 'local-courseplanner-intro alert alert-info');

$calendarlabel = s($calendar->semester) . ' ' . (int)$calendar->year;
echo html_writer::div(get_string('buildercontextlabel', 'local_courseplanner', $calendarlabel), 'local-courseplanner-shell mb-3');

echo html_writer::tag(
    'h4',
    get_string('section_rulesapply', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('section_rulesapply', 'local_courseplanner'),
    ['class' => 'local-courseplanner-section-title']
);
echo html_writer::start_tag(
    'form',
    ['method' => 'post', 'class' => 'local-courseplanner-inline-form mb-3', 'id' => 'local-courseplanner-applyrules-form']
);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'calendarid', 'value' => $calendarid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'applyrules']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag(
    'input',
    ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('applyrulesbtn', 'local_courseplanner')]
);
echo html_writer::end_tag('form');

echo html_writer::tag(
    'h4',
    get_string('section_rulesexisting', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('section_rulesexisting', 'local_courseplanner'),
    ['class' => 'local-courseplanner-section-title', 'id' => 'local-courseplanner-existingrules']
);

// Create-new-rule disclosure.
$createrulehtml = '';
ob_start();
echo html_writer::start_tag('details', [
    'class' => 'local-courseplanner-create-blueprint',
    'id' => 'local-courseplanner-createrule',
]);
echo html_writer::tag('summary', get_string('createrulebutton', 'local_courseplanner'), [
    'class' => 'local-courseplanner-disclosure-summary local-courseplanner-disclosure-summary--primary',
]);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'class' => 'local-courseplanner-disclosure-body',
    'id' => 'local-courseplanner-createrule-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'calendarid', 'value' => $calendarid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'createrule']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::tag('label', get_string('ruletypelabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
echo html_writer::start_tag('select', ['name' => 'ruletype', 'class' => 'custom-select mb-2']);
foreach ($ruletypes as $rt) {
    $ruletypekey = 'ruletype_' . $rt;
    $ruletypelabel = get_string_manager()->string_exists($ruletypekey, 'local_courseplanner')
        ? get_string($ruletypekey, 'local_courseplanner')
        : $rt;
    echo html_writer::tag('option', $ruletypelabel, ['value' => $rt]);
}
echo html_writer::end_tag('select');

echo html_writer::tag('label', get_string('ruledatelabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date',
    'name' => 'ruledate',
    'class' => 'form-control mb-2',
    'required' => 'required',
]);

echo html_writer::tag('label', get_string('rulelabellabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'label',
    'class' => 'form-control mb-2',
    'placeholder' => get_string('rulelabelplaceholder', 'local_courseplanner'),
]);

echo html_writer::tag('label', get_string('ruledescriptionlabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
echo html_writer::tag('textarea', '', [
    'name' => 'description',
    'class' => 'form-control mb-2',
    'rows' => 2,
    'placeholder' => get_string('ruledescriptionplaceholder', 'local_courseplanner'),
]);

echo html_writer::tag('div', get_string('dayswapfieldshelp', 'local_courseplanner'), ['class' => 'text-muted small mb-2']);

echo html_writer::tag('label', get_string('fromdaylabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
echo html_writer::start_tag('select', ['name' => 'fromday', 'class' => 'custom-select mb-2']);
echo html_writer::tag('option', '—', ['value' => '']);
foreach ($weekdays as $wd) {
    echo html_writer::tag('option', $wd, ['value' => $wd]);
}
echo html_writer::end_tag('select');

echo html_writer::tag('label', get_string('todaylabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
echo html_writer::start_tag('select', ['name' => 'today', 'class' => 'custom-select mb-2']);
echo html_writer::tag('option', '—', ['value' => '']);
foreach ($weekdays as $wd) {
    echo html_writer::tag('option', $wd, ['value' => $wd]);
}
echo html_writer::end_tag('select');

echo html_writer::empty_tag(
    'input',
    ['type' => 'submit', 'class' => 'btn btn-primary mt-2', 'value' => get_string('createrulesubmit', 'local_courseplanner')]
);
echo html_writer::end_tag('form');
echo html_writer::end_tag('details');
$createrulehtml = ob_get_clean();

if (empty($rules)) {
    echo $OUTPUT->notification(get_string('norules', 'local_courseplanner'), 'notifyinfo');
} else {
    echo html_writer::start_tag('ul', ['class' => 'local-courseplanner-blueprint-list']);
    foreach ($rules as $rule) {
        $isactive = ((int)$rule->isactive === 1);
        $badgekey = $isactive ? 'ruleactive' : 'ruleinactive';
        $badgeclass = $isactive ? 'local-courseplanner-badge--active' : 'local-courseplanner-badge--archived';

        $ruletypekey = 'ruletype_' . $rule->ruletype;
        $ruletypelabel = get_string_manager()->string_exists($ruletypekey, 'local_courseplanner')
            ? get_string($ruletypekey, 'local_courseplanner')
            : s($rule->ruletype);

        $labeltext = trim((string)$rule->label);
        if ($labeltext === '') {
            $labeltext = $ruletypelabel;
        }
        if ($rule->ruletype === 'DAY_SWAP' && (!empty($rule->fromday) || !empty($rule->today))) {
            $labeltext .= ' (' . s($rule->fromday) . ' → ' . s($rule->today) . ')';
        }

        echo html_writer::start_tag('li', ['class' => 'local-courseplanner-blueprint-item']);
        echo html_writer::start_tag('details', ['class' => 'local-courseplanner-blueprint-details']);

        echo html_writer::start_tag('summary', ['class' => 'local-courseplanner-blueprint-summary']);
        echo html_writer::start_div('local-courseplanner-blueprint-summary-main');
        echo html_writer::tag('span', date('Y-m-d', (int)$rule->ruledate), ['class' => 'local-courseplanner-blueprint-shortcode']);
        echo html_writer::tag('span', format_string($labeltext), ['class' => 'local-courseplanner-blueprint-name']);
        echo html_writer::tag(
            'span',
            $ruletypelabel,
            ['class' => 'local-courseplanner-type-badge local-courseplanner-type-' . strtolower($rule->ruletype)]
        );
        echo html_writer::tag(
            'span',
            get_string($badgekey, 'local_courseplanner'),
            ['class' => 'local-courseplanner-badge ' . $badgeclass]
        );
        echo html_writer::end_div();
        echo html_writer::tag('span', get_string('editrulebutton', 'local_courseplanner'), [
            'class' => 'btn btn-outline-secondary btn-sm local-courseplanner-edit-indicator',
            'aria-hidden' => 'true',
        ]);
        echo html_writer::end_tag('summary');

        echo html_writer::start_div('local-courseplanner-disclosure-body');

        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'calendarid', 'value' => $calendarid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'updaterule']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ruleid', 'value' => (int)$rule->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('ruletypelabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
        echo html_writer::div($ruletypelabel, 'form-control-plaintext');
        echo html_writer::end_div();

        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('ruledatelabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
        echo html_writer::empty_tag('input', [
            'type' => 'date',
            'name' => 'ruledate',
            'class' => 'form-control',
            'required' => 'required',
            'value' => date('Y-m-d', (int)$rule->ruledate),
        ]);
        echo html_writer::end_div();

        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('rulelabellabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'label',
            'class' => 'form-control',
            'placeholder' => get_string('rulelabelplaceholder', 'local_courseplanner'),
            'value' => s((string)$rule->label),
        ]);
        echo html_writer::end_div();

        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('ruledescriptionlabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
        echo html_writer::tag('textarea', s((string)$rule->description), [
            'name' => 'description',
            'class' => 'form-control',
            'rows' => 2,
            'placeholder' => get_string('ruledescriptionplaceholder', 'local_courseplanner'),
        ]);
        echo html_writer::end_div();

        if ($rule->ruletype === 'DAY_SWAP') {
            echo html_writer::tag(
                'div',
                get_string('dayswapfieldshelp', 'local_courseplanner'),
                ['class' => 'text-muted small mb-2']
            );

            echo html_writer::start_div('mb-2');
            echo html_writer::tag('label', get_string('fromdaylabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
            echo html_writer::start_tag('select', ['name' => 'fromday', 'class' => 'custom-select']);
            echo html_writer::tag('option', '—', ['value' => '']);
            foreach ($weekdays as $wd) {
                $attrs = ['value' => $wd];
                if ((string)$rule->fromday === $wd) {
                    $attrs['selected'] = 'selected';
                }
                echo html_writer::tag('option', $wd, $attrs);
            }
            echo html_writer::end_tag('select');
            echo html_writer::end_div();

            echo html_writer::start_div('mb-2');
            echo html_writer::tag('label', get_string('todaylabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
            echo html_writer::start_tag('select', ['name' => 'today', 'class' => 'custom-select']);
            echo html_writer::tag('option', '—', ['value' => '']);
            foreach ($weekdays as $wd) {
                $attrs = ['value' => $wd];
                if ((string)$rule->today === $wd) {
                    $attrs['selected'] = 'selected';
                }
                echo html_writer::tag('option', $wd, $attrs);
            }
            echo html_writer::end_tag('select');
            echo html_writer::end_div();
        }

        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-secondary',
            'value' => get_string('saverulesubmit', 'local_courseplanner'),
        ]);
        echo html_writer::end_tag('form');

        echo html_writer::start_div('local-courseplanner-inline-controls');
        foreach (['togglerule' => 'togglerulesubmit', 'deleterule' => 'deleterulesubmit'] as $ruleaction => $labelkey) {
            $ruleformattrs = ['method' => 'post', 'class' => 'local-courseplanner-inline-form'];
            if ($ruleaction === 'deleterule') {
                $ruleformattrs['data-cc-confirm'] = get_string('deleteruleconfirm', 'local_courseplanner');
                $ruleformattrs['data-cc-confirm-title'] = get_string('confirm', 'core');
                $ruleformattrs['data-cc-confirm-action'] = get_string('deleterulesubmit', 'local_courseplanner');
                $ruleformattrs['data-cc-confirm-style'] = 'delete';
            }
            echo html_writer::start_tag('form', $ruleformattrs);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'calendarid', 'value' => $calendarid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $ruleaction]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ruleid', 'value' => (int)$rule->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $buttonclass = ($ruleaction === 'deleterule') ? 'btn btn-outline-danger' : 'btn btn-outline-secondary';
            echo html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => $buttonclass,
                'value' => get_string($labelkey, 'local_courseplanner'),
            ]);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_div();

        echo html_writer::end_div();
        echo html_writer::end_tag('details');
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
}
echo $createrulehtml;

$PAGE->requires->js_call_amd('local_courseplanner/confirmaction', 'init', []);

$tourid = local_courseplanner_get_tour_id_by_name('local_courseplanner_rules');
$PAGE->requires->js_call_amd('local_courseplanner/showtour', 'init', [
    $tourid,
    '#local-courseplanner-showtour',
]);

echo $OUTPUT->footer();
