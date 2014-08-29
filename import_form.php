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
 * Forms used in import process.
 * @package mod
 * @subpackage eln
 * @copyright 2013 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir.'/formslib.php');

class mod_eln_import_wikiselect_form extends moodleform {
    protected function definition() {
        global $CFG;

        $mform =& $this->_form;
        $params = $this->_customdata['params'];
        $wikiinfo = $this->_customdata['wikiinfo'];

        if (!empty($this->_customdata['actlink'])) {
            $actlink = '<div class="eln_import_link">' . $this->_customdata['actlink'] .
                '</div>';
            $mform->addElement('html', $actlink);
        }

        if ($wikiinfo->nocontent) {
            $warn = '<div class="eln_import_nocontent">' .
                    get_string('import_nocontent', 'eln') . '</div>';
            $mform->addElement('html', $warn);
            return;
        }

        if (!empty($wikiinfo->selector) && count($wikiinfo->selector) == 1) {
            // When there is only one choice force selection by setting as default.
            $keys = array_keys($wikiinfo->selector);
            $wikiinfo->selectordefault = array_shift($keys);
            $wikiinfo->selector = array();
        }

        if (!empty($wikiinfo->selector)) {
            // Subwiki selector must have unique id on page, so add cm id.
            $mform->addElement('select', 'subwikiid' . $wikiinfo->cm->id,
                    get_string('import_selectsubwiki', 'eln'), $wikiinfo->selector);
            $mform->addHelpButton('subwikiid' . $wikiinfo->cm->id, 'import_selectsubwiki', 'eln');
            $mform->setDefault('subwikiid' . $wikiinfo->cm->id, $wikiinfo->selectordefault);
        } else {
            // No subwikis to choose from, default should be used to set required.
            $mform->addElement('hidden', 'subwikiid' . $wikiinfo->cm->id, $wikiinfo->selectordefault);
            $mform->setType('subwikiid' . $wikiinfo->cm->id, PARAM_INT);
        }

        foreach ($params as $paramkey => $paramvalue) {
            $mform->addElement('hidden', $paramkey, $paramvalue);
            $mform->setType($paramkey, PARAM_INT);
        }

        $mform->addElement('hidden', 'importid', $wikiinfo->cm->id);
        $mform->setType('importid', PARAM_INT);

        $this->add_action_buttons(false, get_string('import_selectwiki', 'eln',
                $wikiinfo->cm->name));

    }

    protected function get_form_identifier() {
        // Override form name to ensure unique.
        return parent::get_form_identifier() . '_' . $this->_customdata['wikiinfo']->cm->id;
    }

    public function add_action_buttons($cancel = true, $submitlabel=null) {
        // Override submit to ensure name unique.
        $mform =& $this->_form;
        $mform->addElement('submit', 'submitbutton' . '_' . $this->_customdata['wikiinfo']->cm->id, $submitlabel);
        $mform->closeHeaderBefore('submitbutton' . '_' . $this->_customdata['wikiinfo']->cm->id);
    }
}

class mod_eln_import_pageselect_form extends moodleform {
    protected function definition() {
        global $CFG;

        $mform =& $this->_form;
        $params = $this->_customdata['params'];
        $pages = $this->_customdata['pages'];

        $mform->addElement('html', $pages);

        foreach ($params as $paramkey => $paramvalue) {
            $mform->addElement('hidden', $paramkey, $paramvalue);
            $mform->setType($paramkey, PARAM_INT);
        }

        $this->add_action_buttons(true, get_string('import', 'eln'));
    }
}

class mod_eln_import_confirm_form extends moodleform {
    protected function definition() {
        global $CFG, $OUTPUT;

        $mform =& $this->_form;
        $params = $this->_customdata['params'];
        $confirmdata = $this->_customdata['confirmdata'];

        $mform->addElement('header', 'infohead', get_string('import_confirm_infoheader', 'eln'));
        $mform->setExpanded('infohead');

        // Create confirm form layout.
        $mform->addElement('static', 'importfrom', get_string('import_confirm_from', 'eln'),
                $confirmdata['importfrom']);

        if (!empty($confirmdata['pages'])) {
            $mform->addElement('static', 'importpages', get_string('import_confirm_pages', 'eln'),
                html_writer::alist($confirmdata['pages']));
            $mform->addHelpButton('importpages', 'import_confirm_pages', 'eln');
        } else {
            $mform->addElement('html', html_writer::tag('p',
                    get_string('import_confirm_pages_none', 'eln'),
                        array('class' => 'eln_import_warn')));
        }

        $conflicts = $confirmdata['conflicts'];
        if (!empty($conflicts)) {
            $mform->addElement('header', 'conflicthead', get_string('import_confirm_mergeheader', 'eln'));
            $mform->setExpanded('conflicthead');
            $conflictlist = html_writer::start_tag('ul');
            foreach ($conflicts as $pagename => $locked) {
                if ($locked) {
                    $img = $OUTPUT->pix_icon('i/invalid', get_string('import_confirm_conflicts_locked',
                            'eln', $pagename));
                } else {
                    $img = $OUTPUT->pix_icon('i/valid', get_string('import_confirm_conflicts_notlocked',
                            'eln'));
                }
                $pagelink = new moodle_url('/mod/eln/view.php', array('id' => $params['id'], 'page' => $pagename));
                $conflictlist .= html_writer::tag('li', html_writer::link($pagelink, $pagename) . ' ' . $img);
            }
            $conflictlist .= html_writer::end_tag('ul');
            $mform->addElement('html', html_writer::tag('p',
                    get_string('import_confirm_conflicts_instruct', 'eln'), array('class' => 'eln_import_info')));
            $mform->addElement('static', 'conflictpages', get_string('import_confirm_conflicts', 'eln'),
                    $conflictlist);
            if (!$confirmdata['lockedpage']) {
                // Get merge strategy.
                $radioarr = array();
                $radioarr[] = $mform->createElement('radio', 'conflictmerge', '',
                        get_string('import_confirm_conflicts_option1', 'eln'), 0);
                $radioarr[] = $mform->createElement('radio', 'conflictmerge', '',
                        get_string('import_confirm_conflicts_option2', 'eln'), 1);
                $mform->addGroup($radioarr, 'conflictmergegrp',
                        get_string('import_confirm_conflicts_label', 'eln'), array('<br />'), false);
            }
        }

        if ($confirmdata['lockedpage']) {
            // If a page is locked show warning and then no other options and cancel only.
            $mform->addElement('html', html_writer::tag('p',
                    get_string('import_confirm_conflicts_lockedwarn', 'eln'), array('class' => 'eln_import_warn')));
        } else {
            $mform->addElement('header', 'linkhead', get_string('import_confirm_linkheader', 'eln'));
            $mform->setExpanded('linkhead');
            // Where to add links to new pages? Start page or other...
            if ($confirmdata['startselected']) {
                // Start page is in page choice so links will be added here, get merge strategy.
                $radioarr = array();
                $disabled = $confirmdata['startlocked'] ? array('disabled' => 'disabled') : null;
                $radioarr[] = $mform->createElement('radio', 'startpagemerge', '',
                        get_string('import_confirm_linkfrom_startpage1', 'eln'), 0, $disabled);
                $radioarr[] = $mform->createElement('radio', 'startpagemerge', '',
                        get_string('import_confirm_linkfrom_startpage2', 'eln'), 1);
                $mform->addGroup($radioarr, 'startpagemergegrp',
                        get_string('import_confirm_linkfrom_startpage', 'eln'), array('<br />'), false);
                if ($confirmdata['startlocked']) {
                    $mform->setDefault('startpagemerge', 1);
                }
            } else if (!empty($confirmdata['wikipages'])) {
                // User must choose a page to add links to.
                $select = $mform->createElement('select', 'linkfrom', get_string('import_confirm_linkfrom', 'eln'));
                foreach ($confirmdata['wikipages'] as $id => $name) {
                    $disabled = strpos($name, get_string('import_lockedpage', 'eln')) ?
                        array('disabled' => 'disabled') : null;
                    $select->addOption($name, $id, $disabled);
                }
                $mform->addElement($select);
                $mform->addHelpButton('linkfrom', 'import_confirm_linkfrom', 'eln');
                $mform->addRule('linkfrom', get_string('required'), 'required');
                // Set default to page we originally came from, or start page, or just leave at new page.
                if (!empty($params['page']) && in_array($params['page'], $confirmdata['wikipages'])) {
                    $mform->setDefault('linkfrom', array_search($params['page'], $confirmdata['wikipages']));
                } else if (in_array(get_string('startpage', 'eln'), $confirmdata['wikipages'])) {
                    $mform->setDefault('linkfrom', array_search(get_string('startpage', 'eln'), $confirmdata['wikipages']));
                }
            }
        }

        foreach ($params as $paramkey => $paramvalue) {
            $mform->addElement('hidden', $paramkey, $paramvalue);
            $mform->setType($paramkey, PARAM_INT);
        }

        if (!$confirmdata['lockedpage'] && !empty($confirmdata['pages'])) {
            $this->add_action_buttons(true, get_string('import', 'eln'));
        } else {
            // Something is not good for the import - allow cancel only.
            $mform->addElement('cancel');
        }
    }
}
