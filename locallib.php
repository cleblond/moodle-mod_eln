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
 * Local library file for eln.  These are non-standard functions that are used
 * only by eln.
 *
 * @package    mod
 * @subpackage eln
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or late
 **/

/* Make sure this isn't being directly accessed */
defined('MOODLE_INTERNAL') || die();

/* Include the files that are required by this module */
require_once($CFG->dirroot . '/mod/eln/lib.php');
require_once($CFG->dirroot . '/mod/eln/difflib.php');
require_once($CFG->libdir . '/portfolio/caller.php');

// subwikis
define('OUWIKI_SUBWIKIS_SINGLE', 0);
define('OUWIKI_SUBWIKIS_GROUPS', 1);
define('OUWIKI_SUBWIKIS_INDIVIDUAL', 2);

// locks
define('OUWIKI_LOCK_PERSISTENCE', 120);
define('OUWIKI_LOCK_RECONFIRM', 60);
define('OUWIKI_LOCK_NOJS', 15*60);
define('OUWIKI_LOCK_TIMEOUT', 30*60);
define('OUWIKI_SESSION_LOCKS', 'elnlocks'); // Session variable used to store wiki locks

// format params
define('OUWIKI_PARAMS_LINK', 0);
define('OUWIKI_PARAMS_FORM', 1);
define('OUWIKI_PARAMS_URL', 2);
define('OUWIKI_PARAMS_ARRAY', 3);

define('OUWIKI_FORMAT_HTML', 'html');
define('OUWIKI_FORMAT_RTF', 'rtf');
define('OUWIKI_FORMAT_TEMPLATE', 'template');

// pages
define('OUWIKI_GETPAGE_REQUIREVERSION', 0);
define('OUWIKI_GETPAGE_ACCEPTNOVERSION', 1);
define('OUWIKI_GETPAGE_CREATE', 2);
define('OUWIKI_PAGESIZE', 50);
define('OUWIKI_MAXRESULTS', 50);
define('OUWIKI_RESULTSPERPAGE', 10);

// general
define('OUWIKI_LINKS_SQUAREBRACKETS', '/\[\[(.*?)\]\]/');
define('OUWIKI_SYSTEMUSER', -1);
define('OUWIKI_TIMEOUT_EXTRA', 60);
define('OUWIKI_FEEDSIZE', 50);

// participation
define('OUWIKI_NO_PARTICIPATION', 0);
define('OUWIKI_MY_PARTICIPATION', 1);
define('OUWIKI_USER_PARTICIPATION', 2);
define('OUWIKI_PARTICIPATION_PERPAGE', 100);

// User preference
define('OUWIKI_PREF_HIDEANNOTATIONS', 'eln_hide_annotations');

function eln_dberror($error, $source = null) {
    if (!$source) {
        $backtrace = debug_backtrace();
        $source = preg_replace('@^.*/(.*)(\.php)?$@', '\1',
                $backtrace[0]['file']).'/'.$backtrace[0]['line'];
    }
    print_error('Database problem: '.$error.' (code ELN-'.$source.')');
}

function eln_error($text, $source = null) {
    if (!$source) {
        $backtrace = debug_backtrace();
        $source = preg_replace('^.*/(.*)(\.php)?$^', '$1',
                $backtrace[0]['file']).'/'.$backtrace[0]['line'];
    }
    print_error("ELN error: $text (code ELN-$source)");
}






















/**
 * Gets cm, eln and subwiki based on selected activity id and subwiki id
 * Populates vars sent, overriding initial values
 * @param int $selectedact cm id
 * @param int $selectedsubwiki subwiki id
 * @param null $selectedeln empty
 * @param object $course
 * @param bool $ignorechecks Set to true to ignore activity + subwiki access checks
 */
function eln_get_wikiinfo(&$selectedact, &$selectedsubwiki, &$selectedeln, $course,
        $ignorechecks = false) {
    global $DB;
    $modinfo = get_fast_modinfo($course);
    $selectedact = $modinfo->get_cm($selectedact);
    $selectedcontext = context_module::instance($selectedact->id);
    // Basic checks that it is OK to continue using activity.
    if (!$ignorechecks && (!$selectedact->uservisible ||
            !has_capability('mod/eln:view', $selectedcontext))) {
        eln_error('You are not able to access the selected ELN.');
    }
    // Get sub wiki selected - joining to wiki activity and cm to verify all correct.
    $sql = 'SELECT eln.*, sw.userid, sw.groupid FROM {eln_subwikis} sw
    JOIN {eln} eln on eln.id = sw.wikiid
    JOIN {course_modules} cm on cm.instance = eln.id
    and cm.module = (SELECT id FROM {modules} where name = ?)
    WHERE sw.id = ?';
    $selectedeln = $DB->get_record_sql($sql, array('eln', $selectedsubwiki), MUST_EXIST);

    // Get our subwiki using locallib function to check access.
    if (!$ignorechecks) {
        $selectedsubwiki = eln_get_subwiki($course, $selectedeln, $selectedact, $selectedcontext,
                $selectedeln->groupid, $selectedeln->userid, false);
    } else {
        $selectedsubwiki = $DB->get_record('eln_subwikis', array('id' => $selectedsubwiki));
    }
}

/**
 * Obtains the appropriate subwiki object for a request. If one cannot
 * be obtained, either creates one or calls error() and stops.
 *
 * @param object $eln Wiki object
 * @param object $cm Course-module object
 * @param object $context Context to use for checking permissions
 * @param int $groupid Group ID or 0 to use any appropriate group
 * @param int $userid User ID or 0 to use current user
 * @param bool $create If true, creates a wiki if it doesn't exist
 * @return mixed Object with the data from the subwiki table. Also has extra 'canedit' field
 *   set to true if that's allowed.
 */
function eln_get_subwiki($course, $eln, $cm, $context, $groupid, $userid, $create = null) {
    global $USER, $DB;

    switch($eln->subwikis) {

        case OUWIKI_SUBWIKIS_SINGLE:
            $subwiki = $DB->get_record_select('eln_subwikis', 'wikiid = ? AND groupid IS NULL
            AND userid IS NULL', array($eln->id));
            if ($subwiki) {
                eln_set_extra_subwiki_fields($subwiki, $eln, $context);
                return $subwiki;
            }
            if ($create) {
                $subwiki = eln_create_subwiki($eln, $cm, $course);
                eln_set_extra_subwiki_fields($subwiki, $eln, $context);
                eln_init_pages($course, $cm, $eln, $subwiki, $eln);
                return $subwiki;
            }
            eln_error('ELN does not exist. View ELNs before attempting other actions.');
            break;

        case OUWIKI_SUBWIKIS_GROUPS:
            if (empty($groupid)) {
                $groupid = groups_get_activity_group($cm, true);
            }
            if (!$groupid) {
                // Active group not known - get first group available.
                $groups = groups_get_activity_allowed_groups($cm);
                if (!$groups) {
                    if (!groups_get_all_groups($cm->course, 0, $cm->groupingid)) {
                        eln_error('This ELN cannot be displayed because it is a group ELN,
                                but no groups have been set up for the course (or grouping, if selected).');
                    } else {
                        eln_error('You do not have access to any of the groups in this ELN.');
                    }
                }
                $groupid = reset($groups)->id;
            }
            $othergroup = !groups_is_member($groupid);
            $subwiki = $DB->get_record_select('eln_subwikis', 'wikiid = ? AND groupid = ?
                    AND userid IS NULL', array($eln->id, $groupid));
            if ($subwiki) {
                eln_set_extra_subwiki_fields($subwiki, $eln, $context, $othergroup);
                return $subwiki;
            }
            if ($create) {
                $subwiki =  eln_create_subwiki($eln, $cm, $course, null, $groupid);
                eln_set_extra_subwiki_fields($subwiki, $eln, $context, $othergroup);
                eln_init_pages($course, $cm, $eln, $subwiki, $eln);
                return $subwiki;
            }
            eln_error('ELN does not exist. View ELNs before attempting other actions.');
            break;

        case OUWIKI_SUBWIKIS_INDIVIDUAL:
            if ($userid == 0) {
                $userid = $USER->id;
            }
            $otheruser = false;
            if ($userid != $USER->id) {
                $otheruser = true;
                // Is user allowed to view everybody?
                if (!has_capability('mod/eln:viewallindividuals', $context)) {
                    // Nope. Are they allowed to view people in same group?
                    if (!has_capability('mod/eln:viewgroupindividuals', $context)) {
                        eln_error('You do not have access to view somebody else\'s ELN.');
                    }
                    // Check user is in same group. Note this isn't now restricted to the
                    // module grouping
                    $ourgroups = groups_get_all_groups($cm->course, $USER->id);
                    $theirgroups = groups_get_all_groups($cm->course, $userid);
                    $found = false;
                    foreach ($ourgroups as $ourgroup) {
                        foreach ($theirgroups as $theirgroup) {
                            if ($ourgroup->id == $theirgroup->id) {
                                $found = true;
                                break;
                            }
                        }
                        if ($found) {
                            break;
                        }
                    }
                    if (!$found) {
                        eln_error('You do not have access to view this user\'s ELN.');
                    }
                }
            }
            // OK now find wiki
            $subwiki = $DB->get_record_select('eln_subwikis', 'wikiid = ? AND groupid IS NULL
                    AND userid = ?', array($eln->id, $userid));
            if ($subwiki) {
                eln_set_extra_subwiki_fields($subwiki, $eln, $context, $otheruser, !$otheruser);
                return $subwiki;
            }
            // Create one
            if ($create) {
                $subwiki =  eln_create_subwiki($eln, $cm, $course, $userid);
                eln_set_extra_subwiki_fields($subwiki, $eln, $context, $otheruser, !$otheruser);
                eln_init_pages($course, $cm, $eln, $subwiki, $eln);
                return $subwiki;
            }
            eln_error('ELN does not exist. View ELNs before attempting other actions.');
            break;

        default:
            eln_error("Unexpected ELN value: {$eln->subwikis}");
    }
}

// Create a new subwiki instance
function eln_create_subwiki($eln, $cm, $course, $userid = null, $groupid = null) {
    global $DB;

    $subwiki = new StdClass;
    $subwiki->wikiid = $eln->id;
    $subwiki->userid = $userid;
    $subwiki->groupid = $groupid;
    $subwiki->magic = eln_generate_magic_number();
    try {
        $subwiki->id = $DB->insert_record('eln_subwikis', $subwiki);
    } catch (Exception $e) {
        eln_dberror($e);
    }

    return $subwiki;
}

/**
 * Initialises wiki pages. Does nothing unless there's a template.
 *
 * @param object $cm Course-module object
 * @param object $subwiki Subwiki object
 * @param object $eln OU wiki object
 */
function eln_init_pages($course, $cm, $eln, $subwiki, $eln) {
    global $CFG;

    if (is_null($eln->template)) {
        return;
    }
    
    $fs = get_file_storage();
    $zip = get_file_packer();
    $context = context_module::instance($cm->id);
    $filepath = '/'.$context->id.'/mod_eln/template/'.$eln->id.$eln->template;
    if ($file = $fs->get_file_by_hash(sha1($filepath)) AND !$file->is_directory()) {
        if (strpos($eln->template, '.xml') !== false) {
            // XML template expected.
            $xmlfile = $file;
        } else {
            // Zip format expected.
            $xmlfilename = strtolower(get_string('template', 'mod_eln')) . '.xml';
            if (!$xmlfile = $fs->get_file($context->id, 'mod_eln', 'template', $eln->id, '/',
                    $xmlfilename)) {
                // XML (and other files) not extracted yet. Do once only.
                $zip->extract_to_storage($file, $context->id, 'mod_eln', 'template', $eln->id, '/');
                $xmlfile = $fs->get_file($context->id, 'mod_eln', 'template', $eln->id, '/',
                    $xmlfilename);
            }
        }
       

        $content = $xmlfile->get_content();
        ///added by crl to fix image import
        //$content = file_rewrite_pluginfile_urls($content, 'pluginfile.php',
        //        $context->id, 'mod_eln', 'content', $eln->id);

        $xml =  new DOMDocument();
        $xml->loadXML($content);
        if (!$xml) {
            eln_error('Failed to load ELN template - not valid XML.
                    Check file in XML viewer and correct.');
        }
        if ($xml->documentElement->tagName != 'wiki') {
            eln_error('Failed to load ELN template - must begin with &lt;wiki> tag.');
        }
        for ($page = $xml->documentElement->firstChild; $page; $page = $page->nextSibling) {
            if ($page->nodeType != XML_ELEMENT_NODE) {
                continue;
            }
            if ($page->tagName != 'page') {
                eln_error('Failed to load ELN template - expected &lt;page>.');
            }
            $title = null;
            $xhtml = null;
            $oldcontextid = null;
            $oldpagever = null;
            $oldversionid = null;
            $attachments = array();
            for ($child = $page->firstChild; $child; $child = $child->nextSibling) {
                if ($child->nodeType != XML_ELEMENT_NODE) {
                    continue;
                }
                if (!$child->firstChild) {
                    $text = '';
                } else {
                    if ($child->firstChild->nodeType != XML_TEXT_NODE &&
                       $child->firstChild->nodeType != XML_CDATA_SECTION_NODE) {
                        eln_error('Failed to load ELN template - expected text node.');
                    }
                    if ($child->firstChild->nextSibling) {
                        eln_error('Failed to load ELN template - expected single text node.');
                    }
                    $text = $child->firstChild->nodeValue;
                }
                switch ($child->tagName) {
                    case 'title':
                        // Replace non-breaking spaces with normal spaces in title
                        $title = str_replace(html_entity_decode('&nbsp;', ENT_QUOTES, 'UTF-8'), ' ', $text);
                        break;
                    case 'xhtml':
                        $xhtml = $text;
                        break;
                    case 'versionid':
                        $oldversionid = (int) $text;
                        break;
                    case 'attachments':
                        $attachments = explode('|', $text);
                        break;
                    default:
                        eln_error('Failed to load ELN template - unexpected element &lt;'.
                                $child->tagName.'>.');
                }
            }
            if ($xhtml === null) {
                eln_error('Failed to load ELN template - required &lt;xhtml>.');
            }

            $newverid = eln_save_new_version($course, $cm, $eln, $subwiki, $title, $xhtml,
                     -1, -1, -1, true);

            // Copy any images , spectra or attachments associated with old version id.
            if ($oldfiles = $fs->get_directory_files($context->id, 'mod_eln', 'template',
                    $eln->id, "/$oldversionid/")) {
                foreach ($oldfiles as $oldfile) {
                    
                    if (in_array($oldfile->get_filename(), $attachments)) {
                        // Copy this file to the version attachment record.
                        $fs->create_file_from_storedfile(array(
                                'contextid' => $context->id,
                                'filearea' => 'attachment',
                                'itemid' => $newverid,
                                'filepath' => '/'), $oldfile);
                    }
                    if (mimeinfo('string', $oldfile->get_filename()) == 'image') {
                        // Copy this image file to the version record.
                        $fs->create_file_from_storedfile(array(
                                'contextid' => $context->id,
                                'filearea' => 'content',
                                'itemid' => $newverid,
                                'filepath' => '/'), $oldfile);
                    }
                    ///crl add support for jdx files
                    $ext = pathinfo($oldfile->get_filename(), PATHINFO_EXTENSION);
                    if ($ext == 'jdx') {
                        // Copy this spectrum file to the version record.
                        $fs->create_file_from_storedfile(array(
                                'contextid' => $context->id,
                                'filearea' => 'content',
                                'itemid' => $newverid,
                                'filepath' => '/'), $oldfile);
                    }
                }
                //break;   //crl
            }
        }
    } else {
        eln_error('Failed to load ELN template - file missing.');
    }
}

/**
 * Checks whether a user can edit a wiki, assuming that they can view it. This
 * adds $subwiki->canedit, set to either true or false.
 *
 * @param object &$subwiki The subwiki object to which we are going to add a canedit variable
 * @param object $eln Wiki object
 * @param object $context Context for permissions
 * @param bool $othergroup If true, user is attempting to access a group that's not theirs
 * @param bool $defaultwiki If true, user is accessing the wiki that they see by default
 */
function eln_set_extra_subwiki_fields(&$subwiki, $eln, $context, $othergroup = null,
        $defaultwiki = null) {
    // They must have the edit capability
    $subwiki->canedit = has_capability('mod/eln:edit', $context);
    $subwiki->canannotate = has_capability('mod/eln:annotate', $context);
    $subwiki->annotation = $eln->annotation;
    // If wiki is not one of theirs, they need edit/annotate others or (historical) accesallgroups.
    if ($othergroup) {
        $subwiki->canedit = $subwiki->canedit &&
                (has_capability('moodle/site:accessallgroups', $context) ||
                        has_capability('mod/eln:editothers', $context));
        $subwiki->canannotate = $subwiki->canannotate &&
                (has_capability('moodle/site:accessallgroups', $context) ||
                        has_capability('mod/eln:annotateothers', $context));
    }
    // Editing might be turned off for the wiki at the moment
    $subwiki->canedit = $subwiki->canedit &&
            (is_null($eln->editbegin) || time() >= $eln->editbegin);
    $subwiki->canedit = $subwiki->canedit &&
            (is_null($eln->editend) || time() < $eln->editend);
    $subwiki->defaultwiki = $defaultwiki;
}

/**
 * Checks whether the wiki is locked due to specific dates being set. (This is only used for
 * informational display as the dates are already taken into account in the general checking
 * for edit permission.)
 *
 * @param object $subwiki The subwiki object
 * @param object $eln Wiki object
 * @param object $context Context for permissions
 * @return False if not locked or a string of information if locked
 */
function eln_timelocked($subwiki, $eln, $context) {
    // If they don't have edit permission anyhow then they won't be able to edit later
    // so don't show this
    if (!has_capability('mod/eln:edit', $context)) {
        return false;
    }
    if (!empty($eln->editbegin) && time() < $eln->editbegin) {
        return get_string('timelocked_before', 'eln',
                userdate($eln->editbegin, get_string('strftimedate')));
    }
    if (!empty($eln->editend) && time() >= $eln->editend) {
        return get_string('timelocked_after', 'eln');
    }
    return false;
}


/**
 * Return the shared params needed to create a moodle_url
 *
 * @param string $page Name of page (null for startpage)
 * @param object $subwiki Current subwiki object
 * @param object $cm Course-module object
 * @return Array
 */
function eln_shared_url_params($pagename, $subwiki, $cm) {
    $params = array('id' => $cm->id);
    if (!$subwiki->defaultwiki) {
        if ($subwiki->groupid) {
            $params['group'] = $subwiki->groupid;
        }
        if ($subwiki->userid) {
            $params['user'] = $subwiki->userid;
        }
    }
    if (strtolower(trim($pagename)) !== strtolower(get_string('startpage', 'eln')) &&
            $pagename !== '') {
        $params['page'] = $pagename;
    }
    return $params;
}

/**
 * Prints the parameters that identify a particular wiki and could be used in view.php etc.
 *
 * @param string $page Name of page (empty string for startpage)
 * @param object $subwiki Current subwiki object
 * @param object $cm Course-module object
 * @param int $type OUWIKI_PARAMS_xx constant
 * @return mixed Either array or string depending on type
 */
function eln_display_wiki_parameters($page, $subwiki, $cm, $type = OUWIKI_PARAMS_LINK) {
    if ($type == OUWIKI_PARAMS_ARRAY) {
        $output = array();
        $output['id'] = $cm->id;
    } else {
        $output = eln_get_parameter('id', $cm->id, $type);
    }
    if (!$subwiki->defaultwiki) {
        if ($subwiki->groupid) {
            if ($type == OUWIKI_PARAMS_ARRAY) {
                $output['group'] = $subwiki->groupid;
            } else {
                $output .= eln_get_parameter('group', $subwiki->groupid, $type);
            }
        }
        if ($subwiki->userid) {
            if ($type == OUWIKI_PARAMS_ARRAY) {
                $output['user'] = $subwiki->userid;
            } else {
                $output .= eln_get_parameter('user', $subwiki->userid, $type);
            }
        }
    }
    if ($page !== '') {
        if ($type == OUWIKI_PARAMS_ARRAY) {
            $output['page'] = $page;
        } else {
            $output .= eln_get_parameter('page', $page, $type);
        }
    }
    return $output;
}

// Internal function used by the above
function eln_get_parameter($name, $value, $type) {
    switch ($type) {
        case OUWIKI_PARAMS_FORM:
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $output = "<input type='hidden' name='$name' value='$value' />";
            break;
        case OUWIKI_PARAMS_LINK:
            $value = htmlspecialchars(urlencode($value), ENT_QUOTES, 'UTF-8');
            $output = '';
            if ($name != 'id') {
                $output .= '&amp;';
            }
            $output .= "$name=$value";
            break;
        case OUWIKI_PARAMS_URL:
            $value = urlencode($value);
            $output = '';
            if ($name != 'id') {
                $output .= '&';
            }
            $output .= "$name=$value";
            break;
    }
    return $output;
}

/**
 * Prints the subwiki selector if user has access to more than one subwiki.
 * Also displays the currently-viewing subwiki.
 *
 * @param object $subwiki Current subwiki object
 * @param object $eln Wiki object
 * @param object $cm Course-module object
 * @param object $context Context for permissions
 * @param object $course Course object
 * @param string $actionurl
 * @param string $querytext for use when changing groups against search criteria
 */
function eln_display_subwiki_selector($subwiki, $eln, $cm, $context, $course, $actionurl = 'view.php', $querytext = '') {
    global $USER, $DB, $OUTPUT;

    if ($eln->subwikis == OUWIKI_SUBWIKIS_SINGLE) {
        return '';
    }

    $choicefield = '';

    switch($eln->subwikis) {
        case OUWIKI_SUBWIKIS_GROUPS:
            $groups = groups_get_activity_allowed_groups($cm);
            uasort($groups, create_function('$a,$b', 'return strcasecmp($a->name,$b->name);'));
            $wikifor = htmlspecialchars($groups[$subwiki->groupid]->name);

            // Do they have more than one?
            if (count($groups) > 1) {
                $choicefield = 'group';
                $choices = $groups;
            }
            break;

        case OUWIKI_SUBWIKIS_INDIVIDUAL:
            $user = $DB->get_record('user', array('id' => $subwiki->userid),
                    'id, username, ' . user_picture::fields());
            $wikifor = eln_display_user($user, $cm->course);
            $usernamefields = user_picture::fields('u');
            if (has_capability('mod/eln:viewallindividuals', $context)) {
                // Get list of everybody...
                $choicefield = 'user';
                try {
                    $choices = $DB->get_records_sql('SELECT u.id, ' . $usernamefields .
                            ' FROM {eln_subwikis} sw
                            INNER JOIN {user} u ON sw.userid = u.id
                            WHERE sw.wikiid = ?
                            ORDER BY u.lastname, u.firstname', array($eln->id));
                } catch (Exception $e) {
                    eln_dberror($e);
                }

                foreach ($choices as $choice) {
                    $choice->name = fullname($choice);
                }

            } else if (has_capability('mod/eln:viewgroupindividuals', $context)) {
                $choicefield = 'user';
                $choices = array();
                // User allowed to view people in same group
                $theirgroups = groups_get_all_groups($cm->course, $USER->id,
                        $course->defaultgroupingid);
                if (!$theirgroups) {
                    $theirgroups = array();
                }
                foreach ($theirgroups as $group) {
                    $members = groups_get_members($group->id, 'u.id, ' . $usernamefields);
                    foreach ($members as $member) {
                        $member->name = fullname($member);
                        $choices[$member->id] = $member;
                    }
                }
            }
            break;

        default:
            eln_error("Unexpected subwikis value: {$eln->subwikis}");
    }

    $out = '<div class="ouw_subwiki">';
    if ($choicefield && count($choices) > 1) {
        $actionquery = '';
        if (!empty($querytext)) {
            $actionquery = '&amp;query=' . rawurlencode($querytext);
        }
        $actionurl = '/mod/eln/'. $actionurl .'?id=' . $cm->id . $actionquery;
        $urlroot = new moodle_url($actionurl);
        if ($choicefield == 'user') {
            // Individuals.
            $individualsmenu = array();
            foreach ($choices as $choice) {
                $individualsmenu[$choice->id] = $choice->name;
            }
            $select = new single_select($urlroot, 'user', $individualsmenu, $subwiki->userid, null, 'selectuser');
            $select->label = get_string('wikifor', 'eln');
            $output = $OUTPUT->render($select);
            $out .= '<div class="individualselector">'.$output.'</div>';
        } else if ($choicefield == 'group') {
            // Group mode.
            $out .= groups_print_activity_menu($cm, $urlroot, true, true);
        }
    } else {
        $out .= get_string('wikifor', 'eln') . $wikifor;
    }
    $out .= '</div>';

    return $out;
}

/**
 * Returns an object containing the details from 'pages' and 'versions'
 * tables for the current version of the specified (named) page, or false
 * if page does not exist. Note that if the page exists but there are no
 * versions, then the version fields will not be set.
 *
 * @param object $subwiki Current subwiki object
 * @param string $pagename Name of desired page or null for start
 * @param int $option OUWIKI_GETPAGE_xx value. Can use _ACCEPTNOVERSION
 *   if it's OK when a version doesn't exist, or _CREATE which creates
 *   pages when they don't exist.
 * @return object Page-version object
 */
function eln_get_current_page($subwiki, $pagename, $option = OUWIKI_GETPAGE_REQUIREVERSION) {
    global $DB;

    $params = array($subwiki->id);
    $pagename_s = 'UPPER(p.title) = ?';
    $params[] = textlib::strtoupper($pagename);

    $jointype = $option == OUWIKI_GETPAGE_REQUIREVERSION ? 'JOIN' : 'LEFT JOIN';

    $userfields = user_picture::fields('u', null, 'userid');

    $sql = "SELECT p.id AS pageid, p.subwikiid, p.title, p.currentversionid, p.firstversionid,
                p.locked, v.id AS versionid, v.xhtml, v.timecreated, v.userid, v.xhtmlformat,
                v.wordcount, v.previousversionid, $userfields
            FROM {eln_pages} p
            $jointype {eln_versions} v ON p.currentversionid = v.id
            LEFT JOIN {user} u ON v.userid = u.id
            WHERE p.subwikiid = ? AND $pagename_s";

    $pageversion = $DB->get_record_sql($sql, $params);
    if (!$pageversion) {
        if ($option != OUWIKI_GETPAGE_CREATE) {
            return false;
        }

        // Create page
        $pageversion = new StdClass;
        $pageversion->subwikiid = $subwiki->id;
        $pageversion->title = $pagename ? $pagename : '';
        $pageversion->locked = 0;
        $pageversion->firstversionid = null; // new page
        try {
            $pageversion->pageid = $DB->insert_record('eln_pages', $pageversion);
        } catch (Exception $e) {
            eln_dberror($e);
        }

        // Update any missing link records that might exist
        $uppertitle = textlib::strtoupper($pagename);
        try {
            $DB->execute("UPDATE {eln_links}
                SET tomissingpage = NULL, topageid = ?
                WHERE tomissingpage = ?
                AND ? = (
                    SELECT p.subwikiid
                    FROM {eln_versions} v
                    INNER JOIN {eln_pages} p ON v.pageid = p.id
                    WHERE v.id = fromversionid)",
                array($pageversion->pageid, $uppertitle, $subwiki->id));
        } catch (Exception $e) {
            eln_dberror($e);
        }

        $pageversion->currentversionid = null;
        $pageversion->versionid = null;
        $pageversion->xhtml = null;
        $pageversion->xhtmlformat = null;
        $pageversion->timecreated = null;
        $pageversion->userid = null;
        $pageversion->previousversionid = null; // first version for page

        return $pageversion;
    }

    // Ensure valid value for comparing time created
    $timecreated = empty($pageversion->timecreated) ? 0 : $pageversion->timecreated;

    $sql = "SELECT v.id, v.timecreated, v.userid, $userfields
                FROM {eln_versions} v
            LEFT JOIN {user} u ON v.userid = u.id
            WHERE v.pageid = ?
                AND v.timecreated <= ?
                AND v.deletedat IS NULL
            ORDER BY v.id DESC";

    $pageversion->recentversions = $DB->get_records_sql($sql,
            array($pageversion->pageid, $timecreated), 0, 3);

    return $pageversion;
}

/**
 * Obtains all the pages from a subwiki as pageversion objects. As a special
 * bonus feature, this query also returns the firstname and lastname of current
 * author (person in userid field of version).
 * @return array Array of pageversion objects (note: the 'recentversions'
 *   member is not available, but otherwise these are the same as from
 *   eln_get_current_page) in same order as index page
 */
function eln_get_subwiki_allpages($subwiki) {
    global $DB;

    $userfields = user_picture::fields('u', null, 'userid');

    $sql = "SELECT p.id AS pageid, p.subwikiid, p.title, p.currentversionid, p.firstversionid,
                p.locked, v.id AS versionid, v.xhtml, v.timecreated, v.userid, v.xhtmlformat,
                v.wordcount, v.previousversionid, $userfields
            FROM {eln_pages} p
            JOIN {eln_versions} v ON p.currentversionid = v.id
            LEFT JOIN {user} u ON u.id = v.userid
            WHERE p.subwikiid = ? AND v.deletedat IS NULL
            ORDER BY CASE WHEN p.title IS NULL THEN '' ELSE UPPER(p.title) END";

    return $DB->get_records_sql($sql, array($subwiki->id));
}

/**
 * Returns an object containing the details from 'pages' and 'versions'
 * tables for the specified version of the specified (named) page, or false
 * if page/version does not exist.
 *
 * @param object $subwiki Current subwiki object
 * @param string $pagename Name of desired page or null for start
 * @return object $pageversion Version object
 */
function eln_get_page_version($subwiki, $pagename, $versionid) {
    global $DB;

    $userfields = user_picture::fields('u', null, 'userid');

    $sql = "SELECT p.id AS pageid, p.subwikiid, p.title, p.currentversionid,
                v.id AS versionid, v.xhtml, v.timecreated, v.userid, v.xhtmlformat,
                v.deletedat, $userfields,
                v.wordcount
            FROM {eln_pages} p, {eln_versions} v
            LEFT JOIN {user} u ON v.userid = u.id
            WHERE p.subwikiid = ? AND v.id = ? AND UPPER(p.title) = ?";

    $pagename = textlib::strtoupper($pagename);
    $pageversion = $DB->get_record_sql($sql, array($subwiki->id, $versionid, $pagename));

    $pageversion->recentversions = false;

    return $pageversion;
}

/**
 * Obtains details (versionid,timecreated plus user id,username,firstname,lastname)
 * for the previous and next version after the specified one.
 *
 * @param object $pageversion Page/version object
 * @return object Object with ->prev and ->next fields, either of which may be false
 *   to indicate (respectively) that this is the first or last version. If not false,
 *   these objects contain the fields mentioned above.
 */
function eln_get_prevnext_version_details($pageversion) {
    global $DB;

    $userfields = user_picture::fields('u');

    $prevnext = new StdClass;

    $prevsql = "SELECT v.id AS versionid, v.timecreated, $userfields
                FROM {eln_versions} v
            LEFT JOIN {user} u ON u.id = v.userid
            WHERE v.pageid = ?
                AND v.timecreated < ?
                AND v.deletedat IS NULL
            ORDER BY v.id DESC";

    $prev = $DB->get_records_sql($prevsql,
            array($pageversion->pageid, $pageversion->timecreated), 0, 1);
    $prevnext->prev = $prev ? current($prev) : false;

    $nextsql = "SELECT v.id AS versionid, v.timecreated, u.id, $userfields
                FROM {eln_versions} v
                LEFT JOIN {user} u ON u.id = v.userid
                WHERE v.pageid = ?
                AND v.timecreated > ?
                AND v.deletedat IS NULL
                ORDER BY v.id";

    $next = $DB->get_records_sql($nextsql,
            array($pageversion->pageid, $pageversion->timecreated), 0, 1);
    $prevnext->next = $next ? current($next) : false;

    return $prevnext;
}

/**
 * Returns an HTML span with appropriate class to indicate how recent something
 * is by colour.
 */
function eln_recent_span($time) {
    $now = time();
    if ($now-$time < 5*60) {
        $category = 'ouw_recenter';
    } else if ($now - $time < 4*60*60) {
        $category = 'ouw_recent';
    } else {
        $category = 'ouw_recentnot';
    }
    return '<span class="'.$category.'">';
}

function eln_internal_re_heading($matches) {
    global $PAGE;

    $elnoutput = $PAGE->get_renderer('mod_eln');
    return $elnoutput->eln_internal_re_heading_bits($matches);
}

function eln_internal_re_plain_heading_bits($matches) {
    return '<div class="ouw_heading"><h'.$matches[1].' id="ouw_s'.$matches[2].'">'.$matches[3].
            '</h'.$matches[1].'></div>';
}

function eln_internal_re_internallinks($matches) {
    // Used to replace links when displaying wiki all one one page
    global $eln_internallinks;

    $details = eln_get_wiki_link_details($matches[1]);

    // See if it matches a known page
    foreach ($eln_internallinks as $indexpage) {
        if (($details->page === '' && $indexpage->title === '') ||
            (isset($indexpage->title) && strtoupper($indexpage->title) === strtoupper($details->page)) ) {
            // Page matches, return link
            return '<a class="ouw_wikilink" href="#' . $indexpage->pageid .
                '">' . $details->title . '</a>';
        }
    }
    // Page did not match, return title in brackets
    return '(' . $details->title . ')';
}

function eln_internal_re_wikilinks($matches) {
    global $eln_wikilinks;

    $details = eln_get_wiki_link_details($matches[1]);
    return '<a class="ouw_wikilink" href="view.php?' .
        eln_display_wiki_parameters('', $eln_wikilinks->subwiki,
            $eln_wikilinks->cm) .
        ($details->page !== ''
            ? '&amp;page=' . htmlspecialchars(urlencode($details->page)) : '') .
        '">' . $details->title . '</a>';
}

function eln_convert_content($content, $subwiki, $cm, $internallinks = null,
        $xhtmlformat = FORMAT_HTML) {
    // Detect links. Note that changes to this code ought to be reflected
    // in the code in eln_save_new_version which analyses to search for
    // links.

    // When displayed on one page
    global $eln_internallinks, $eln_wikilinks;

    // Ordinary [[links]]
    if ($internallinks) {
        $eln_internallinks = $internallinks;
        $function = 'eln_internal_re_internallinks';
    } else {
        $eln_wikilinks = (object) array('subwiki' => $subwiki, 'cm' => $cm);
        $function = 'eln_internal_re_wikilinks';
    }
    $content = preg_replace_callback(OUWIKI_LINKS_SQUAREBRACKETS, $function, $content);

    // We do not use FORMAT_MOODLE (which adds linebreaks etc) because that was
    // already handled manually.
    $options = eln_format_text_options();
    $options->para = false;
    $addwrapperdivs = true;
    if (strpos($content, '<div class="eln_content">') !== false) {
        // Stop adding text wrapper divs when already in data.
        $addwrapperdivs = false;
    }
    $toreturn = format_text($content, $xhtmlformat, $options);
    if ($addwrapperdivs) {
        $toreturn = html_writer::tag('div', $toreturn, array('class' => 'eln_content'));
    }
    return $toreturn;
}

/**
 * Return default common options for {@link format_text()} when preparing
 * a content to be displayed on an eln page
 *
 * We set the option in format_text to allow ids through because otherwise
 * annotations break. (This requires Moodle 2.0.3.)
 *
 * @return stdClass
 */
function eln_format_text_options() {

    $options                = new stdClass();
    $options->trusted       = true;
    $options->allowid       = true;

    return $options;
}

/**
 * Displays a user's name and link to profile etc.
 * @param object $user User object (must have at least id, firstname and lastname)
 * @param int $courseid ID of course
 * @param bool $link If true, makes it a link
 */
function eln_display_user($user, $courseid, $link = true) {
    // Wiki pages can be created by the system which obviously doesn't
    // need a profile link.
    if (!$user->id) {
        return get_string('system', 'eln');
    }

    $fullname = fullname($user);
    $extra = '';
    if (!$link) {
        $extra = 'class="eln_noshow"';
    }

    $userurl = new moodle_url('/user/view.php', array('id' => $user->id, 'course' => $courseid));
    $result = '<a href="'.$userurl.'" '.$extra.'>'.fullname($user).'</a>';

    return $result;
}

function eln_print_tabs($selected, $pagename, $subwiki, $cm, $context, $pageexists = true,
        $pagelocked = null) {
    global $CFG;

    $tabrow = array();

    $params = eln_display_wiki_parameters($pagename, $subwiki, $cm);

    $tabrow[] = new tabobject('view',
        'view.php?'.$params, get_string('tab_view', 'eln'));

    if ($subwiki->canedit && !$pagelocked) {
        $tabrow[] = new tabobject('edit',
            'edit.php?'.$params, get_string('tab_edit', 'eln'));
    }

    if ($subwiki->annotation) {
        if ($subwiki->canannotate) {
            $tabrow[] = new tabobject('annotate',
                'annotate.php?'.$params, get_string('tab_annotate', 'eln'));
        }
    }

    if ($pageexists) {
        $tabrow[] = new tabobject('history',
            'history.php?'.$params, get_string('tab_history', 'eln'));
    }

    $tabs = array();
    $tabs[] = $tabrow;
    print_tabs($tabs, $selected, $pageexists ? '' : array('edit', 'annotate'));

    print '<div id="eln_belowtabs">';
    print get_accesshide(ucfirst($selected) . '.', 'h1');
}

/**
 * Prints the header and (if applicable) group selector.
 *
 * @param object $eln Wiki object
 * @param object $cm Course-modules object
 * @param object $subwiki Subwiki objecty
 * @param string $pagename Name of page
 * @param string $afterpage If included, extra content for navigation string after page link
 * @param string $head Things to include inside html head
 * @param string $title optional
 */
function eln_print_header($eln, $cm, $subwiki, $pagename, $afterpage = null,
        $head = '', $title='') {
    global $OUTPUT, $PAGE;

    $wikiname = format_string(htmlspecialchars($eln->name));
    $buttontext = eln_get_search_form($subwiki, $cm->id);

    if ($afterpage && $pagename !== '') {
        $PAGE->navbar->add(htmlspecialchars($pagename), new moodle_url('/mod/eln/view.php',
                array('id' => $cm->id, 'page' => $pagename)));
    } else if ($pagename !== '') {
        $PAGE->navbar->add(htmlspecialchars($pagename));
    } else {
        $PAGE->navbar->add(htmlspecialchars(get_string('startpage', 'eln')));
    }
    if ($afterpage) {
        foreach ($afterpage as $element) {
            $PAGE->navbar->add($element['name'], $element['link']);
        }
    }
    $PAGE->set_button($buttontext);

    if (empty($title)) {
        $title = $wikiname;
    }

    $PAGE->set_title($title);
    $PAGE->set_heading($title);

    echo $OUTPUT->header();
}

/**
 * Prints the footer and also logs the page view.
 *
 * @param object $course Course object
 * @param object $subwiki Subwiki object; used to add parameters to $logurl or the default URL
 * @param object $pagename Page name or NULL if homepage/not relevant
 * @param string $logurl URL to log; if null, uses current page as default
 * @param string $logaction Action to log; if null, uses page before .php as default
 * @param string $loginfo Optional info string
 */
function eln_print_footer($course, $cm, $subwiki, $pagename = null, $logurl = null,
        $logaction = null, $loginfo = null) {
    global $PAGE, $OUTPUT;

    echo '</div>';
    echo $OUTPUT->footer();

    // Log
    $url = $logurl ? $logurl : preg_replace('~^.*/eln/~', '', $_SERVER['PHP_SELF']);

    $url .= (strpos($url, '?') === false ? '?' : '&').'id='.$cm->id;
    if ($subwiki->groupid) {
        $url .= '&group='.$subwiki->groupid;
    }
    if ($subwiki->userid) {
        $url .= '&user='.$subwiki->userid;
    }
    if ($pagename !== null) {
        $url .= '&page='.urlencode($pagename);
        $info = $pagename;
    } else {
        $info = '';
    }
    if ($loginfo) {
        if ($info) {
            $info .= ' ';
        }
        $info .= $loginfo;
    }
    $action = $logaction ? $logaction : preg_replace('~\..*$~', '', $url);
    add_to_log($course->id, 'eln', $action, $url, $info, $cm->id);
}

function eln_nice_date($time, $insentence = null, $showrecent = null) {
    $result = $showrecent ? eln_recent_span($time) : '';
    if (function_exists('specially_shrunken_date')) {
        $result .= specially_shrunken_date($time, $insentence);
    } else {
        $result .= userdate($time);
    }
    $result .= $showrecent ? '</span>' : '';

    return $result;
}

function eln_handle_backup_exception($e, $type = 'backup') {
    if (debugging()) {
        print '<pre>';
        print $e->getMessage().' ('.$e->getCode().')'."\n";
        print $e->getFile().':'.$e->getLine()."\n";
        print $e->getTraceAsString();
        print '</pre>';
    } else {
        print '<div><strong>Error</strong>: '.htmlspecialchars($e->getMessage()).' ('.
                $e->getCode().')</div>';
    }
    print "<div><strong>This $type has failed</strong> (even though it may say otherwise later).
            Resolve this problem before continuing.</div>";
}

/**
 * Checks if page is locked by somebody else (they are currently editing it).
 * @param int $pageid
 * @return bool true if locked
 */
function eln_is_page_locked($pageid) {
    global $USER, $DB;

    // Check for lock.
    $lock = $DB->get_record('eln_locks', array('pageid' => $pageid));
    if (!empty($lock)) {
        $timeoutok = is_null($lock->expiresat) || time() < $lock->expiresat;
        // Consider the page locked if the lock has been confirmed.
        // within OUWIKI_LOCK_PERSISTENCE seconds.
        if ($lock->userid == $USER->id && $timeoutok) {
            // Cool, it's our lock.
            return false;
        } else if (time()-$lock->seenat < OUWIKI_LOCK_PERSISTENCE && $timeoutok) {
            return true;
        }
    }
    return false;
}

/**
 * Obtains an editing lock on a wiki page.
 *
 * @param object $eln Wiki object (used just for timeout setting)
 * @param int $pageid ID of page to be locked
 * @return array Two-element array with a boolean true (if lock has been obtained)
 *   or false (if lock was held by somebody else). If lock was held by someone else,
 *   the values of the wiki_locks entry are held in the second element; if lock was
 *   held by current user then the the second element has a member ->id only.
 */
function eln_obtain_lock($eln, $pageid) {
    global $USER, $DB;

    // Check for lock
    $alreadyownlock = false;
    $lock = $DB->get_record('eln_locks', array('pageid' => $pageid));
    if (!empty($lock)) {
        $timeoutok = is_null($lock->expiresat) || time() < $lock->expiresat;
        // Consider the page locked if the lock has been confirmed
        // within OUWIKI_LOCK_PERSISTENCE seconds
        if ($lock->userid == $USER->id && $timeoutok) {
            // Cool, it's our lock, do nothing except remember it in session
            $lockid = $lock->id;
            $alreadyownlock = true;
        } else if (time()-$lock->seenat < OUWIKI_LOCK_PERSISTENCE && $timeoutok) {
            return array(false, $lock);
        } else {
            // Not locked any more. Get rid of the old lock record.
            try {
                $DB->delete_records('eln_locks', array('pageid' => $pageid));
            } catch (Exception $e) {
                print_error('Unable to delete lock record');
            }
        }
    }

    // Add lock
    if (!$alreadyownlock) {
        // Lock page
        $newlock = new StdClass;
        $newlock->pageid = $pageid;
        $newlock->userid = $USER->id;
        $newlock->lockedat = time();
        $newlock->seenat = $newlock->lockedat;
        if ($eln->timeout) {
            $newlock->expiresat = $newlock->lockedat + $eln->timeout + OUWIKI_TIMEOUT_EXTRA;
        }
        $lockid = $DB->insert_record('eln_locks', $newlock);
    }

    // Store lock information in session so we can clear it later
    if (!array_key_exists(OUWIKI_SESSION_LOCKS, $_SESSION)) {
            $_SESSION[OUWIKI_SESSION_LOCKS]=array();
    }
    $_SESSION[OUWIKI_SESSION_LOCKS][$pageid] = $lockid;
    $lockdata = new StdClass;
    $lockdata->id = $lockid;

    return array(true, $lockdata);
}

/**
 * If the user has an editing lock, releases it. Has no effect otherwise.
 * Note that it doesn't matter if this isn't called (as happens if their
 * browser crashes or something) since locks time out anyway. This is just
 * to avoid confusion of the 'what? it says I'm editing that page but I'm
 * not, I just saved it!' variety.
 *
 * @param int $pageid ID of page that was locked
 */
function eln_release_lock($pageid) {
    global $DB, $COURSE;

    if (!array_key_exists(OUWIKI_SESSION_LOCKS, $_SESSION)) {
        // No locks at all in session
        debugging('No locks in \$_SESSION '. $pageid, DEBUG_DEVELOPER);
        return;
    }

    if (array_key_exists($pageid, $_SESSION[OUWIKI_SESSION_LOCKS])) {
        $lockid = $_SESSION[OUWIKI_SESSION_LOCKS][$pageid];
        unset($_SESSION[OUWIKI_SESSION_LOCKS][$pageid]);
        try {
            $DB->delete_records('eln_locks', array('id' => $lockid));
        } catch (Exception $e) {
            print_error("Unable to delete lock record.");
        }
    }
}

/**
 * Kills any locks on a given page.
 *
 * @param int $pageid ID of page that was locked
 */
function eln_override_lock($pageid) {
    global $DB;

    try {
        $DB->delete_records('eln_locks', array('pageid' => $pageid));
    } catch (Exception $e) {
        error("Unable to delete lock record.");
    }
}

/**
 * Obtains information about all versions of a wiki page in time order (newest first).
 *
 * @param int $pageid Page ID
 * @param mixed $limitfrom If set, used to return results starting from this index
 * @param mixed $limitnum If set, used to return only this many results
 * @return array An array of records (empty if none) containing id, timecreated, userid,
 *   username, firstname, and lastname fields.
 */
function eln_get_page_history($pageid, $selectdeleted, $limitfrom = '', $limitnum = '') {
    global $DB;

    // Set AND clause if not selecting deleted page versions
    $deleted = '';
    if (!$selectdeleted) {
        $deleted = ' AND v.deletedat IS NULL';
    }

    $userfields = user_picture::fields('u');

    $sql = "SELECT v.id AS versionid, v.timecreated, v.deletedat, u.id, u.username,
                $userfields, v.wordcount, v.previousversionid, v.importversionid,
                (SELECT v2.wordcount
                    FROM {eln_versions} v2
                    WHERE v2.id = v.previousversionid)
                    AS previouswordcount
                FROM {eln_versions} v
            LEFT JOIN {user} u ON v.userid = u.id
            WHERE v.pageid = ?
                $deleted
            ORDER BY v.id DESC";

    $result = $DB->get_records_sql($sql, array($pageid), $limitfrom, $limitnum);
    // Fix confusing behaviour when no results
    if (!$result) {
        $result = array();
    }
    return $result;
}

/**
 * Obtains the index information of a subwiki.
 *
 * @param int $subwikiid ID of subwiki
 * @param mixed $limitfrom If set, used to return results starting from this index
 * @param mixed $limitnum If set, used to return only this many results
 * @return array Array of objects, one per page, containing the following fields:
 *   pageid, title, versionid, timecreated, (user) id, username, firstname, lastname,
 *   and linksfrom which is an array of page IDs of pages that currently link to this
 *   one.
 */
function eln_get_subwiki_index($subwikiid, $limitfrom = '', $limitnum = '') {
    global $DB;

    $userfields = user_picture::fields('u');

    // Get all the pages...
    $sql = "SELECT p.id AS pageid, p.title, v.id AS versionid, v.timecreated, $userfields,
            v.wordcount
                FROM {eln_pages} p
            INNER JOIN {eln_versions} v ON p.currentversionid = v.id
            LEFT JOIN {user} u ON v.userid = u.id
                WHERE p.subwikiid = ? AND v.deletedat IS NULL
            ORDER BY CASE WHEN p.title IS NULL THEN '' ELSE UPPER(p.title) END";

    $pages = $DB->get_records_sql($sql, array($subwikiid), $limitfrom, $limitnum);

    // Fix confusing behaviour when no results
    if (!$pages) {
        $pages = array();
    }
    foreach ($pages as $page) {
        $page->linksfrom = array();
    }

    // ...and now get all the links for those pages
    if (count($pages)) {
        list($usql, $params) = $DB->get_in_or_equal(array_keys($pages));
        $sql = 'SELECT l.id, l.topageid, p.id AS frompage
                    FROM {eln_links} l
                INNER JOIN {eln_pages} p ON p.currentversionid = l.fromversionid
                    WHERE l.topageid '.$usql;
        $links = $DB->get_records_sql($sql, $params);
    } else {
        $links = false;
    }
    if (!$links) {
        $links = array();
    }

    // Add links into pages array
    foreach ($links as $obj) {
        $pages[$obj->topageid]->linksfrom[] = $obj->frompage;
    }

    return $pages;
}

/**
 * Obtains the index information of a subwiki.
 *
 * @param object $subwiki
 * @return array Array of objects, one per page, containing the following fields:
 *   pageid, title, versionid, timecreated, (user) id, username, firstname, lastname,
 *   and linksfrom which is an array of page IDs of pages that currently link to this
 *   one.
 */
function eln_get_subwiki_allpages_index($subwiki) {
    global $DB;

    $userfields = user_picture::fields('u', null, 'userid');

    // Get all the pages...
    $sql = "SELECT p.id AS pageid, p.subwikiid, p.title, p.currentversionid, p.firstversionid,
    p.locked, v.id AS versionid, v.xhtml, v.timecreated, v.userid, v.xhtmlformat,
    v.wordcount, v.previousversionid, $userfields
    FROM {eln_pages} p
    JOIN {eln_versions} v ON p.currentversionid = v.id
    LEFT JOIN {user} u ON u.id = v.userid
    WHERE p.subwikiid = ? AND v.deletedat IS NULL
    ORDER BY CASE WHEN p.title IS NULL THEN '' ELSE UPPER(p.title) END";

    $pages = $DB->get_records_sql($sql, array($subwiki->id));

    // Fix confusing behaviour when no results.
    if (!$pages) {
        $pages = array();
    }
    foreach ($pages as $page) {
        $page->linksfrom = array();
    }

    // ...and now get all the links for those pages.
    if (count($pages)) {
        list($usql, $params) = $DB->get_in_or_equal(array_keys($pages));
        $sql = 'SELECT l.id, l.topageid, p.id AS frompage
        FROM {eln_links} l
        INNER JOIN {eln_pages} p ON p.currentversionid = l.fromversionid
        WHERE l.topageid ' . $usql;
        $links = $DB->get_records_sql($sql, $params);
    } else {
        $links = false;
    }
    if (!$links) {
        $links = array();
    }

    // Add links into pages array.
    foreach ($links as $obj) {
        $pages[$obj->topageid]->linksfrom[] = $obj->frompage;
    }

    return $pages;
}

/**
 * Obtains list of recent changes across subwiki.
 *
 * @param int $subwikiid ID of subwiki
 * @param int $limitfrom Database result start, if set
 * @param int $limitnum Database result count (default 51)
 */
function eln_get_subwiki_recentchanges($subwikiid, $limitfrom = '', $limitnum = 51) {
    global $DB;

    $userfields = user_picture::fields('u', null, 'userid');

    $sql = "SELECT v.id AS versionid, v.timecreated, v.userid,
        p.id AS pageid, p.subwikiid, p.title, p.currentversionid,
        $userfields, v.wordcount, v.previousversionid, v.importversionid,
            (SELECT v2.wordcount
                FROM {eln_versions} v2
                WHERE v2.id = v.previousversionid)
            AS previouswordcount
        FROM {eln_pages} p
            INNER JOIN {eln_versions} v ON v.pageid = p.id
            LEFT JOIN {user} u ON v.userid = u.id
        WHERE p.subwikiid = ? AND v.deletedat IS NULL
        ORDER BY v.id DESC";

    $result = $DB->get_records_sql($sql, array($subwikiid), $limitfrom, $limitnum);

    if (!$result) {
        $result = array();
    }

    return $result;
}

/**
 * Obtains list of contributions to wiki made by a particular user,
 * in similar format to the 'recent changes' list except ordered by page
 * then date.
 *
 * @param int $subwikiid ID of subwiki
 * @param int $userid ID of subwiki
 * @return Array of all changes (zero-length if none)
 */
function eln_get_contributions($subwikiid, $userid) {
    global $DB;

    $sql = "SELECT v.id AS versionid, v.timecreated, v.userid,
            p.id AS pageid, p.subwikiid, p.title, p.currentversionid,
                (SELECT MAX(id) FROM {eln_versions} v2
                    WHERE v2.pageid = p.id AND v2.id < v.id) AS previousversionid
            FROM {eln_pages} p
            INNER JOIN {eln_versions} v ON v.pageid = p.id
                WHERE p.subwikiid = ? AND v.userid = ? AND v.deletedat IS NULL
            ORDER BY CASE WHEN p.title IS NULL THEN '' ELSE UPPER(p.title) END, v.id";

    $result = $DB->get_records_sql($sql, array($subwikiid, $userid));

    if (!$result) {
        $result = array();
    }

    return $result;
}

/**
 * Obtains list of recently created pages across subwiki.
 *
 * @param int $subwikiid ID of subwiki
 * @param int $limitfrom Database result start, if set
 * @param int $limitnum Database result count (default 51)
 * @return Array (may be 0-length) of page-version records, with the following
 *   fields: pageid,subwikiid,title,currentversionid,versionid,timecreated,userid,
 *   firstname,lastname,username. The version fields relate to the first version of
 *   the page.
 */
function eln_get_subwiki_recentpages($subwikiid, $limitfrom = '', $limitnum = 51) {
    global $DB;
    $result = array();

    $subwikis = $DB->get_records_sql('SELECT MIN(v.id)
                                    FROM {eln_pages} p
                                INNER JOIN {eln_versions} v ON v.pageid = p.id
                                WHERE p.subwikiid = ? AND v.deletedat IS NULL
                                GROUP BY p.id', array($subwikiid));

    if ($subwikis) {
        list($usql, $params) = $DB->get_in_or_equal(array_keys($subwikis));

        $userfields = user_picture::fields('u', null, 'userid');

        $sql = "SELECT p.id AS pageid, p.subwikiid, p.title, p.currentversionid,
                v.id AS versionid, v.timecreated, v.userid, $userfields,
                u.username, v.wordcount, v.importversionid
                FROM {eln_versions} v
                INNER JOIN {eln_pages} p ON v.pageid = p.id
                LEFT JOIN {user} u ON v.userid = u.id
                WHERE v.id $usql
                ORDER BY v.id DESC";

        $result = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    }

    return $result;
}

/**
 * Obtains the list of pages in a subwiki that are linked to, but do not exist.
 *
 * @param int $subwikiid ID of subwiki
 * @param mixed $limitfrom If set, used to return results starting from this index
 * @param mixed $limitnum If set, used to return only this many results
 * @return array Array of missing title => array of source page titles. Sorted
 *   in alphabetical order of missing title.
 */
function eln_get_subwiki_missingpages($subwikiid, $limitfrom = '', $limitnum = '') {
    global $DB;

    // Get all the pages that either link to a nonexistent page, or link to
    // a page that has been created but has no versions.
    $sql = 'SELECT l.id, l.tomissingpage, p2.title, p.title AS fromtitle
                FROM {eln_pages} p
            INNER JOIN {eln_versions} v ON p.currentversionid = v.id
            INNER JOIN {eln_links} l ON v.id = l.fromversionid
            LEFT JOIN {eln_pages} p2 ON l.topageid = p2.id
                WHERE p.subwikiid = ?
                AND (l.tomissingpage IS NOT NULL
                    OR (l.topageid IS NOT NULL AND p2.currentversionid IS NULL))
                AND v.deletedat IS NULL';

    $result = $DB->get_records_sql($sql, array($subwikiid), $limitfrom, $limitnum);

    // Fix confusing behaviour when no results
    if (!$result) {
        $result = array();
    }
    $missing = array();
    foreach ($result as $obj) {
        if (is_null($obj->tomissingpage) || $obj->tomissingpage === '') {
            $title = $obj->title;
        } else {
            $title = $obj->tomissingpage;
        }
        if (!array_key_exists($title, $missing)) {
            $missing[$title] = array();
        }
        $missing[$title][] = $obj->fromtitle;
    }
    uksort($missing, 'strnatcasecmp');

    return $missing;
}



/**
 * Given HTML content, finds all our marked section headings.
 *
 * @param string $content XHTML content
 * @return string of sections heading skeleton.
 */
function eln_find_sections_html($content) {
    $results = array();
    $matchlist = array();
    preg_match_all('~<h([0-9]) id="ouw_s([0-9]+_[0-9]+)">(.*?)</h([0-9])>~s',
            $content, $matchlist, PREG_SET_ORDER);
    print_object($matchlist);
    $results = '';
    foreach ($matchlist as $matches) {

    $results .= $matches[0];
    }

    return $results;
}


















/**
 * Given HTML content, finds all our marked section headings.
 *
 * @param string $content XHTML content
 * @return array Associative array of section ID => current title
 */
function eln_find_sections($content) {
    $results = array();
    $matchlist = array();
    preg_match_all('~<h([0-9]) id="ouw_s([0-9]+_[0-9]+)">(.*?)</h([0-9])>~s',
            $content, $matchlist, PREG_SET_ORDER);
    foreach ($matchlist as $matches) {
        if ($matches[1] != $matches[4]) {
            // Some weird s*** with nested headings
            continue;
        }
        $section = $matches[2];
        $content = $matches[3];
        // Remove tags and decode entities
        $content = preg_replace('|<.*?>|', '', $content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        // Tidy up whitespace
        $content = preg_replace('|\s+|', ' ', $content);
        $content = trim($content);
        if ($content) {
            $results[$section] = $content;
        }
    }

    return $results;
}

/**
 * Obtains various details about a named section. (This function will call error()
 * if it can't find the section; it shouldn't fail if the section was checked with
 * eln_find_sections.)
 *
 * @param string $content XHTML content
 * @param string $sectionxhtmlid ID of desired section
 * @return Object containing ->startpos and ->content
 */
function eln_get_section_details($content, $sectionxhtmlid) {
    // Check heading number
    $matches = array();
    if (!preg_match('|<h([0-9]) id="ouw_s'.$sectionxhtmlid.'">|s', $content, $matches)) {
        error('Unable to find expected section');
    }
    $h = $matches[1];

    // Find position of heading and of next heading with equal or lower number
    $startpos = strpos($content, $stupid = '<h'.$h.' id="ouw_s'.$sectionxhtmlid.'">');
    if ($startpos === false) {
        error('Unable to find expected section again');
    }
    $endpos = strlen($content);
    for ($count = 1; $count <= $h; $count++) {
        $nextheading = strpos($content, '<h'.$count, $startpos + 1);
        if ($nextheading !== false && $nextheading < $endpos) {
            $endpos = $nextheading;
        }
    }

    // Extract the relevant slice of content and return
    $result = new StdClass;
    $result->startpos = $startpos;
    $result->size = $endpos - $startpos;
    $result->content = substr($content, $startpos, $result->size);

    return $result;
}

function eln_internal_re_headings($matches) {
    global $elninternalre;

    return '<h'.$matches[1].' id="ouw_s'.$elninternalre->version.'_'.
            ($elninternalre->count++).'">';
}

/**
 * Saves a change to the given page while recording section details.
 *
 * @param object $cm Course-module object
 * @param object $subwiki Subwiki object
 * @param string $pagename Name of page (NO SLASHES)
 * @param string $contentbefore Previous XHTML Content (NO SLASHES)
 * @param string $newcontent Content of new section (NO SLASHES)
 * @param object $sectiondetails Information from eln_get_section_details for section
 */

//    eln_save_new_version($course, $cm, $eln, $subwiki, $newpagename, $sourcecontent, -1, -1, -1, null, $formdata);

function eln_save_new_version_section($course, $cm, $eln, $subwiki, $pagename,
        $contentbefore, $newcontent, $sectiondetails, $formdata = null) {
    // Put section into content
    $result = substr($contentbefore, 0, $sectiondetails->startpos).$newcontent.
        substr($contentbefore, $sectiondetails->startpos + $sectiondetails->size);
    // Store details of change size in db
    eln_save_new_version($course, $cm, $eln, $subwiki, $pagename, $result,
        $sectiondetails->startpos, strlen($newcontent), $sectiondetails->size, null, $formdata);
}

/**
 * Internal function. Sorts deletions into reverse order so the byte numbers
 * stay accurate.
 *
 * @param object $a Deletion object
 * @param object $b Other one
 * @return int Negative to put $a before $b, etc
 */
function eln_internal_sort_deletions($a, $b) {
    return $b->startbyte - $a->startbyte;
}

/**
 * Saves a new version of the given named page within a subwiki. Can create
 * a new page or just add a new version to an existing one. In case of
 * failure, ends up calling error() rather than returning something.
 *
 * @param object $course Course object
 * @param object $cm Course-module object
 * @param object $eln OU wiki object
 * @param object $subwiki Subwiki object
 * @param string $pagename Name of page (NO SLASHES)
 * @param string $content XHTML Content (NO SLASHES)
 * @param int $changestart For section changes. Start position of change. (-1 if not section change)
 * @param int $changesize Size of changed section.
 * @param int $changeprevsize Previous size of changed section
 * @param bool $nouser If true, creates as system
 * @param object $formdata if coming from edit will have content embedded media and attachments
 * @param int revertversionid if coming from revert.php will have an older versionid
 */
function eln_save_new_version($course, $cm, $eln, $subwiki, $pagename, $content,
        $changestart = -1, $changesize = -1, $changeprevsize = -1, $nouser = null,
        $formdata = null, $revertversionid = null, $importversionid = null) {

    global $DB, $USER;
    global $elninternalre, $eln_count; // Nasty but I can't think of a better way!

    $transaction = $DB->start_delegated_transaction();

    // Find page if it exists
    $pageversion = eln_get_current_page($subwiki, $pagename, OUWIKI_GETPAGE_CREATE);

    $previousversionid = null;
    if ($pageversion->currentversionid) {
        $previousversionid = $pageversion->currentversionid;
    }

    // Analyse content for HTML headings that don't already have an ID.
    // These are all assigned unique, fairly short IDs.

    // Get number of version [guarantees in-page uniqueness of generated IDs]
    $versionnumber = $DB->count_records('eln_versions', array('pageid' => $pageversion->pageid));

    // Remove any spaces from annotation tags that were added for editing or by users
    // and remove any duplicate annotation tags
    $pattern = '~<span\b.id=\"annotation(.+?)\">.*?</span>~';
    $replace = '<span id="annotation$1"></span>';
    $content = preg_replace($pattern, $replace, $content);
    unset($pattern, $replace, $used);

    // Get rid of any heading tags that only contain whitespace
    $emptypatterns = array();
    for ($i = 1; $i <= 6; $i++) {
        $emptypatterns[] = '~<h'.$i.'[^>]*>\s*(<br[^>]*>\s*)*</h'.$i.'>~';
    }
    $content = preg_replace($emptypatterns, '', $content);

    // List all headings that already have IDs, to check for duplicates
    $matches = array();
    preg_match_all('|<h[1-9] id="ouw_s(.*?)">(.*?)</h[1-9]>|',
        $content, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);

    // Organise list by ID
    $byid = array();
    foreach ($matches as $index => $data) {
        $id = $data[1][0];
        if (!array_key_exists($id, $byid)) {
            $byid[$id] = array();
        }
        $byid[$id][] = $index;
    }

    // Handle any duplicates
    $deletebits = array();
    foreach ($byid as $id => $duplicates) {
        if (count($duplicates) > 1) {
            // We have a duplicate. By default, keep the first one
            $keep = $duplicates[0];

            // See if there is a title entry in the database for it
            $knowntitle = $DB->get_field('eln_sections', 'title',
                    array('xhtmlid' => $id, 'pageid' => $pageversion->pageid));
            if ($knowntitle) {
                foreach ($duplicates as $duplicate) {
                    $title = eln_get_section_title(null, null, $matches[$duplicate][2][0]);
                    if ($title === $knowntitle) {
                        $keep = $duplicate;
                        break;
                    }
                }
            }

            foreach ($duplicates as $duplicate) {
                if ($duplicate !== $keep) {
                    $deletebits[] = (object) array(
                        'startbyte' => $matches[$duplicate][1][1] - 10,
                        'bytes' => strlen($matches[$duplicate][1][0]) + 11);
                }
            }
        }
    }

    // Were there any?
    if (count($deletebits) > 0) {
        // Sort in reverse order of starting position
        usort($deletebits, 'eln_internal_sort_deletions');

        // Delete each bit
        foreach ($deletebits as $deletebit) {
            $content = substr($content, 0, $deletebit->startbyte).
                substr($content, $deletebit->startbyte + $deletebit->bytes);
        }
    }

    // Replace existing empty headings with an ID including version count plus another index
    $eln_count = 0;
    $elninternalre = new stdClass();
    $elninternalre->version = $versionnumber;
    $elninternalre->count = 0;
    $sizebefore = strlen($content);
    $content = preg_replace_callback('/<h([1-9])>/', 'eln_internal_re_headings', $content);
    $sizeafter = strlen($content);

    // Replace wiki links to [[Start page]] with the correct (non
    // language-specific) format [[]]
    $regex = str_replace('.*?', preg_quote(get_string('startpage', 'eln')),
        OUWIKI_LINKS_SQUAREBRACKETS) . 'ui';
    $newcontent = @preg_replace($regex, '[[]]', $content);
    if ($newcontent === null) {
        // Unicode support not available! Change the regex and try again
        $regex = preg_replace('~ui$~', 'i', $regex);
        $newcontent = preg_replace($regex, '[[]]', $content);
    }
    $content = $newcontent;

    // Create version
    $version = new StdClass;
    $version->pageid = $pageversion->pageid;
    $version->xhtml = $content; // May be altered later (see below)
    $version->xhtmlformat = FORMAT_MOODLE; // Using fixed value here is a bit rubbish
    $version->timecreated = time();
    $version->wordcount = eln_count_words($content);
    $version->previousversionid = $previousversionid;
    $version->importversionid = $importversionid;
    if (!$nouser) {
        $version->userid = $USER->id;
    }
    if ($changestart != -1) {
        $version->changestart = $changestart;
        // In tracking the new size, account for any added headings etc
        $version->changesize = $changesize + ($sizeafter - $sizebefore);
        $version->changeprevsize = $changeprevsize;
    }
    try {
        $versionid = $DB->insert_record('eln_versions', $version);

        // if firstversionid is already set in the current page use that
        // else this is a new page and version entirely
        if (!$pageversion->firstversionid) {
            $DB->set_field('eln_pages', 'firstversionid', $versionid, array('id' => $version->pageid));
        }
    } catch (Exception $e) {
        eln_dberror($e);
    }

    // information needed for attachments
    $fs = get_file_storage();
    $modcontext = context_module::instance($cm->id);
    $prevversion = ($revertversionid) ? $revertversionid : $pageversion->versionid;

    // save new files connected with the version from the formdata if set
    if ($formdata) {
        $formdata->content = file_save_draft_area_files($formdata->content['itemid'],
                $modcontext->id, 'mod_eln', 'content', $versionid,
                array('subdirs' => 0), $content);
        if ($content !== $formdata->content) {
            $DB->set_field('eln_versions', 'xhtml', $formdata->content,
                    array('id' => $versionid));
        }
        if (isset($formdata->attachments)) {
            file_save_draft_area_files($formdata->attachments, $modcontext->id, 'mod_eln',
                    'attachment', $versionid, array('subdirs' => 0));
        }
    } else {
        if ($oldfiles = $fs->get_area_files($modcontext->id, 'mod_eln', 'content',
                $prevversion)) {
            foreach ($oldfiles as $oldfile) {
                // copy this file to the version record.
                $fs->create_file_from_storedfile(array(
                    'contextid' => $modcontext->id,
                    'filearea' => 'content',
                    'itemid' => $versionid), $oldfile);
            }
        }
    }
    if (!$formdata || (!empty($formdata->section) || !empty($formdata->newsection))) {
        // copy attached files from previous version when no form or no attachments element.
        if ($oldfiles = $fs->get_area_files($modcontext->id, 'mod_eln', 'attachment',
                $prevversion)) {
            foreach ($oldfiles as $oldfile) {
                // copy this file to the version record.
                $fs->create_file_from_storedfile(array(
                        'contextid' => $modcontext->id,
                        'filearea' => 'attachment',
                        'itemid' => $versionid), $oldfile);
            }
        }
    }

    // Update latest version
    $DB->set_field('eln_pages', 'currentversionid', $versionid,
            array('id' => $pageversion->pageid));

    // Analyse for links
    $wikilinks = array();
    $externallinks = array();

    // Wiki links: ordinary [[links]]
    $matches = array();
    preg_match_all(OUWIKI_LINKS_SQUAREBRACKETS, $content, $matches, PREG_PATTERN_ORDER);
    foreach ($matches[1] as $match) {
        // Convert to page name (this also removes HTML tags etc)
        $wikilinks[] = eln_get_wiki_link_details($match)->page;
    }

    // Note that we used to support CamelCase links but have removed support because:
    // 1. Confusing: students type JavaScript or MySpace and don't expect it to become a link
    // 2. Not accessible: screenreaders cannot cope with run-together words, and
    //    dyslexic students can have difficulty reading them

    // External links
    preg_match_all('/<a [^>]*href=(?:(?:\'(.*?)\')|(?:"(.*?))")/',
        $content, $matches, PREG_PATTERN_ORDER);
    foreach ($matches[1] as $match) {
        if ($match) {
            $externallinks[] = html_entity_decode($match);
        }
    }
    foreach ($matches[2] as $match) {
        if ($match) {
            $externallinks[] = html_entity_decode($match);
        }
    }

    // Add link records
    $link = new StdClass;
    $link->fromversionid = $versionid;
    foreach ($wikilinks as $targetpage) {
        if (!empty($targetpage)) {
            $pagerecord = $DB->get_record_select('eln_pages',
                    'subwikiid = ? AND UPPER(title) = UPPER(?)', array($subwiki->id, $targetpage));
            if ($pagerecord) {
                $pageid = $pagerecord->id;
            } else {
                $pageid = false;
            }
        } else {
            $pageid = $DB->get_field_select('eln_pages', 'id',
                    'subwikiid = ? AND title IS NULL', array($subwiki->id));
        }
        if ($pageid) {
            $link->topageid = $pageid;
            $link->tomissingpage = null;
        } else {
            $link->topageid = null;
            $link->tomissingpage = strtoupper($targetpage);
        }
        try {
            $link->id = $DB->insert_record('eln_links', $link);
        } catch (Exception $e) {
            eln_dberror($e);
        }
    }
    $link->topageid = null;
    $link->tomissingpage = null;
    foreach ($externallinks as $url) {
        // Restrict length of URL
        if (textlib::strlen($url) > 255) {
            $url = textlib::substr($url, 0, 255);
        }
        $link->tourl = $url;
        try {
            $link->id = $DB->insert_record('eln_links', $link);
        } catch (Exception $e) {
            eln_dberror($e);
        }
    }

    // Inform search, if installed
    if (eln_search_installed()) {
        $doc = new local_ousearch_document();
        $doc->init_module_instance('eln', $cm);
        if ($subwiki->groupid) {
            $doc->set_group_id($subwiki->groupid);
        }
        $doc->set_string_ref($pageversion->title === '' ? null : $pageversion->title);
        if ($subwiki->userid) {
            $doc->set_user_id($subwiki->userid);
        }
        $title = $pageversion->title;
        $doc->update($title, $content);
    }

    // Check and remove any files not included in new version.
    $unknownfiles = array();
    $versioncontent = $DB->get_field('eln_versions', 'xhtml', array('id' => $versionid));
    if (! empty($version->previousversionid)) {
        // Get any filenames in content.
        preg_match_all("#@@PLUGINFILE@@/(\S)+([.]\w+)#", $versioncontent, $matches);
        if (! empty($matches)) {
            // Extract the file names from the matches.
            $filenames = array();
            foreach ($matches[0] as $match) {
                // Get file name.
                $match = str_replace('@@PLUGINFILE@@/', '', $match);
                array_push($filenames, urldecode($match));
            }

            // Get version files.
            if ($elnfiles = $fs->get_area_files($modcontext->id, 'mod_eln', 'content',
                $versionid)) {
                // For each file check to see whether there is a match.
                foreach ($elnfiles as $storedfile) {
                    $storedfilename = $storedfile->get_filename();
                    // If filename is a directory ignore - must be a valid file.
                    if (!$storedfile->is_directory() && !in_array($storedfilename, $filenames)) {
                        // Delete file.
                        $storedfile->delete();
                    }
                }
            }
        }
    }

    $transaction->allow_commit();
    return $versionid;
}

/**
 * Given the text of a wiki link (between [[ and ]]), this function converts it
 * into a safe page name by removing white space at each end and restricting to
 * max 200 characters. Also splits out the title (if provided).
 *
 * @param string $wikilink HTML code between [[ and ]]
 * @return object Object with parameters ->page (page name as PHP UTF-8
 *   string), ->title (link title as HTML; either an explicit title if specified
 *   or the start page string or the page name as html), ->rawpage (page name
 *   as HTML including possible entities, tags), and ->rawtitle (link title if
 *   specified as HTML including possible entities, tags; null if not specified)
 */
function eln_get_wiki_link_details($wikilink) {
    // Split out title if present (note: because | is lower-ascii it is safe
    // to use byte functions rather than UTF-8 ones)
    $rawtitle = null;
    $bar = strpos($wikilink, '|');
    if ($bar !== false) {
        $rawtitle = trim(substr($wikilink, $bar+1));
        $wikilink = substr($wikilink, 0, $bar);
    }

    // Remove whitespace at either end
    $wikilink = trim($wikilink);
    $rawpage = $wikilink;

    // Remove html tags
    $wikilink = html_entity_decode(preg_replace(
        '/<.*?>/', '', $wikilink), ENT_QUOTES, 'UTF-8');

    // Trim to 200 characters or less (note: because we don't want to cut it off
    // in the middle of a character, we use proper UTF-8 functions)
    if (textlib::strlen($wikilink) > 200) {
        $wikilink = textlib::substr($wikilink, 0, 200);
        $space = textlib::strrpos($wikilink, ' ');
        if ($space > 150) {
            $wikilink = textlib::substr($wikilink, 0, $space);
        }
    }

    // Remove non-breaking spaces
    $wikilink = str_replace(html_entity_decode('&nbsp;', ENT_QUOTES, 'UTF-8'), ' ', $wikilink);

    // What will the title be of this link?
    if ($rawtitle) {
        $title = $rawtitle;
    } else if ($wikilink === '') {
        $title = get_string('startpage', 'eln');
    } else {
        $title = $rawpage;
    }

    // Return object with both pieces of information
    return (object) array(
                        'page' => $wikilink,
                        'title' => $title,
                        'rawtitle' => $rawtitle,
                        'rawpage' => $rawpage
                    );
}

/** @return True if OU search extension is installed */
function eln_search_installed() {
    global $CFG;
    return @include_once($CFG->dirroot.'/local/ousearch/searchlib.php');
}

/**
 * Obtains the title (contents of h1-6 tag as plain text) for a
 * named section.
 *
 * @param string $sectionxhtmlid Section ID not including prefix
 * @param string $xhtml Full XHTML content of page
 * @param string $extracted If the title has already been pulled out of
 *   the XHTML, supply this variable (other two are ignored)
 * @return mixed Title or false if not found
 */
function eln_get_section_title($sectionxhtmlid, $xhtml, $extracted = null) {
    // Get from HTML if not already extracted
    $matches = array();
    if (!$extracted && preg_match(
        '|<h[1-9] id="ouw_s'.$sectionxhtmlid.'">(.*?)</h[1-9]>|', $xhtml, $matches)) {
        $extracted = $matches[1];
    }
    if (!$extracted) {
        // Not found in HTML
        return false;
    }

    // Remove tags and decode entities
    $stripped = preg_replace('|<.*?>|', '', $extracted);
    $stripped = html_entity_decode($stripped, ENT_QUOTES, 'UTF-8');
    // Tidy up whitespace
    $stripped = preg_replace('|\s+|', ' ', $stripped);

    return trim($stripped);
}

/**
 * Obtains list of wiki links from other pages of the wiki to this one.
 *
 * @param int $pageid
 * @return array Array (possibly zero-length) of page objects
 */
function eln_get_links_to($pageid) {
    global $DB;

    $links = $DB->get_records_sql('SELECT DISTINCT p.id, p.title, UPPER(p.title) AS uppertitle
                                    FROM {eln_links} l
                                INNER JOIN {eln_pages} p ON p.currentversionid = l.fromversionid
                                WHERE l.topageid = ?
                                    ORDER BY UPPER(p.title)', array($pageid));

    return $links ? $links : array();
}

// @return Array listing XHTML tags that we stick in a couple newlines after
function eln_internal_newline_tags() {
    return array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p', 'ul', 'li', 'table');
}

/**
 * Normalises/pretty-prints XHTML. This is intended to produce content that can
 * reasonably be edited using the plaintext editor and that has linebreaks only in
 * places we know about. Should be called before eln_save_version.
 *
 * @param string $content Content from html editor
 * @return string Content after pretty-printing
 */
function eln_format_xhtml_a_bit($content) {
    // 0. Remove unnecessary linebreak at start of textarea
    if (substr($content, 0, 2) == "\r\n") {
        $content = substr($content, 2);
    }

    // 1. Replace all (possibly multiple) whitespace with single spaces
    try {
        $content = preg_replace('/\s+/u', ' ', $content);
    } catch(moodle_exception $e) {
        // u modifier will throw error if invalid utf8 sent - fallback.
        $content = preg_replace('/\s+/', ' ', $content);
    }

    // 2. Add two line breaks after tags marked as requiring newline
    $newlinetags = eln_internal_newline_tags();
    $searches = array();
    foreach ($newlinetags as $tag) {
        $searches[] = '|(</'.$tag.'>) ?(?!\n\n)|i';
    }
    $content = preg_replace($searches, '$1'."\n\n", $content);

    // 3. Add single line break after <br/>
    $content = preg_replace('|(<br\s*/?>)\s*|', '$1'."\n", $content);

    return $content;
}

function eln_xhtml_to_plain($content) {
    // Just get rid of <br/>
    $content = preg_replace('|<br\s*/?>|', '', $content);

    return $content;
}

function eln_plain_to_xhtml($content) {
    // Convert CRLF to LF (makes easier!)
    $content = preg_replace('/\r?\n/', "\n" , $content);

    // Remove line breaks that are added by format_xhtml_a_bit
    // i.e. that were already present
    $newlinetags = eln_internal_newline_tags();
    $searches = array();
    foreach ($newlinetags as $tag) {
        $searches[] = '|(</'.$tag.'>)\n\n|i';
    }
    $content = preg_replace($searches, '$1', $content);

    // Now turn all the other line breaks into <br/>
    $content = str_replace("\n", '<br />', $content);

    return $content;
}

/**
 * @param string $content Arbitrary string
 * @return string Version of string suitable for inclusion in double-quoted
 *   Javascript variable within XHTML.
 */
function eln_javascript_escape($content) {
    // Escape slashes
    $content = str_replace("\\", "\\\\", $content);

    // Escape newlines
    $content = str_replace("\n", "\\n", $content);

    // Escape double quotes
    $content = str_replace('"', '\\"', $content);

    // Remove ampersands and left-angle brackets (for XHTML)
    $content = str_replace('<', '\\x3c', $content);
    $content = str_replace('&', '\\x26', $content);

    return $content;
}

/**
 * Generates a 16-digit magic number at random.
 *
 * @return string 16-digit long string
 */
function eln_generate_magic_number() {
    $result = rand(1, 9);
    for ($i = 0; $i < 15; $i++) {
        $result .= rand(0, 9);
    }

    return $result;
}

/**
 * @param object $subwiki For details of user/group and ID so that
 * we can make links
 *
 * @param object $cm Course-module object (again for making links)
 * @param object $pageversion Data from page and version tables.
 * @return string HTML content for page
 */
function eln_display_create_page_form($subwiki, $cm, $pageversion) {
    $result = '';

    // shared form elements
    $genericformdetails = '<form method="get" action="edit.php">' .
            '<div class="eln_addnew_div">' .
            '<input type="hidden" name="id" value="'.$cm->id.'"/>';

    $result .= '<div id="eln_addnew"><ul>';

    // Create new section.
    $result .= '<li>' . $genericformdetails;
    if ($pageversion->title !== '') {
        $result .= '<input type="hidden" name="page" value="' . $pageversion->title . '" />';
    }
    $result .= '<input type="hidden" name="user" value="' . $subwiki->userid . '" />';
    $result .= '<label for="ouw_newsectionname">' . get_string('addnewsection', 'eln') . '</label> ' .
            '<input type="text" size="30" name="newsection" id="ouw_newsectionname" value="" />' .
            '<input type="submit" id="ouw_add" name="ouw_subb" value="' .
            get_string('add', 'eln').'" />' .
            '</div></form></li>';

    // Create new page.
    $result .= '<li>' . $genericformdetails .
            '<input type="hidden" name="frompage" value="' . $pageversion->title . '" />' .
            '<label for="ouw_newpagename">' . get_string('createnewpage', 'eln') . '</label> '.
            '<input type="text" name="page" id="ouw_newpagename" size="30" value="" />' .
            '<input type="submit" id="ouw_create" name="ouw_subb" value="' .
            get_string('create', 'eln') . '" />' .
            '</div></form></li>'; 



if ($pageversion->title !== '') {
    $result .= '<li>' . $genericformdetails .
            '<input type="hidden" name="clonefrompage" value="' . $pageversion->title . '" />' .
            '<label for="ouw_clonepagename">' . get_string('clonenewpage', 'eln') . '</label> '.
            '<input type="text" name="clonepage" id="ouw_clonepagename" size="30" value="" />' .
            '<input type="submit" id="ouw_clone" name="ouw_subb" value="' .
            get_string('clone', 'eln') . '" />' .
            '</div></form></li>'; 
}

    $result .= '</ul></div>';

    return $result;
}



/**
 * @param object $subwiki For details of user/group and ID so that
 * we can make links
 *
 * @param object $cm Course-module object (again for making links)
 * @param object $pageversion Data from page and version tables.
 * @return string HTML content for page
 */
function eln_display_search_page_form($subwiki, $cm) {
    $result = '';

    // shared form elements
    $genericformdetails = '<form method="get" action="wikiindex.php">' .
            '<div class="eln_addnew_div">' .
            '<input type="hidden" name="id" value="'.$cm->id.'"/>';

    $result .= '<div id="eln_addnew"><ul>';

    // Create new section.
    $result .= $genericformdetails;
   
    //$result .= '<input type="hidden" name="page" value="" />';
    
    $result .= '<input type="hidden" name="type" value="search" />';
    $result .= '<label for="ouw_searchterm">' . get_string('searchinfo', 'eln') . '</label> ' .
            '<input type="text" size="30" name="searchterm" id="ouw_searchterm" value="" />' .
            '<input type="submit" id="ouw_search" name="ouw_subb" value="' .
            get_string('search', 'eln').'" />' .
            '</div></form>';

    $result .= '</div>';

    return $result;
}






/**
 * @param string $cm ID of course module
 * @param string $subwiki details if it exists
 * @param string $pagename of the original wiki page for which the new page is a link of,
 *   null for start page
 * @param string $newpagename page name of the new page being created (not null)
 * @param string $content of desired new page
 */
function eln_create_new_page($course, $cm, $eln, $subwiki, $pagename, $newpagename,
        $content, $formdata) {
    global $DB;
    $transaction = $DB->start_delegated_transaction();

    // need to get old page and new page
    $sourcecontent = '';
    if ($sourcepage = eln_get_current_page($subwiki, $pagename)) {
        $sourcecontent = $sourcepage->xhtml;
        $sourcecontent .= '<p>[['.htmlspecialchars($newpagename).']]</p>';
    }
    //echo "Before sourcecontent in create new page! <br>";
    //print_object($sourcecontent);
    // Create the new page
    $pageversion = eln_get_current_page($subwiki, $newpagename, OUWIKI_GETPAGE_CREATE);

    // need to save version - will call error if does not work
    eln_save_new_version($course, $cm, $eln, $subwiki, $newpagename, $content, -1, -1, -1,
            null, $formdata);

    // save the revised original page as a new version
    eln_save_new_version($course, $cm, $eln, $subwiki, $pagename, $sourcecontent);

    $transaction->allow_commit();
}






/**
 * @param string $cm ID of course module
 * @param string $subwiki details if it exists
 * @param string $pagename of the original wiki page for which the new page is a link of,
 *   null for start page
 * @param string $newpagename page name of the new page being created (not null)
 * @param string $content of desired new page
 */
//CRL
function eln_clone_new_page($course, $cm, $eln, $subwiki, $pagename, $newpagename,
        $content, $formdata) {
    global $DB;
    $transaction = $DB->start_delegated_transaction();

    // need to get old page and new page
    $sourcecontent = '';
    if ($sourcepage = eln_get_current_page($subwiki, $pagename)) {
        $sourcecontent = $sourcepage->xhtml;
        //crl don't append new page name
        //$sourcecontent .= '<p>[['.htmlspecialchars($newpagename).']]</p>';
    }
    //echo "Before sourcecontent! <br>";
    //print_object($sourcecontent);
    // Create the new page
    $pageversion = eln_get_current_page($subwiki, $newpagename, OUWIKI_GETPAGE_CREATE);

    // need to save version - will call error if does not work
//    eln_save_new_version($course, $cm, $eln, $subwiki, $newpagename, $sourcecontent, -1, -1, -1,
//            null, $formdata);

    //eln_save_new_version($course, $cm, $eln, $subwiki, $newpagename, $sourcecontent,-1, -1, -1,
    //        null, $formdata);

    eln_save_new_version($course, $cm, $eln, $subwiki, $newpagename, $content,-1, -1, -1,
            null, $formdata);

    // save the revised original page as a new version
    // disabled by CRL - maybe add clone info to history in future
    //eln_save_new_version($course, $cm, $eln, $subwiki, $pagename, $sourcecontent);

    $transaction->allow_commit();
}





















/**
 * Creates a new section on a page from scratch
 *
 * @param string $cm ID of course module
 * @param string $subwiki details if it exists
 * @param string $pagename of the original wiki page for which the new page is a link of
 * @param string $newcontent of desired new section
 * @param string $sectionheader for the new section
 */
function eln_create_new_section($course, $cm, $eln, $subwiki, $pagename, $newcontent,
        $sectionheader, $formdata) {
    $sourcepage = eln_get_current_page($subwiki, $pagename);
    $sectiondetails = eln_get_new_section_details($sourcepage->xhtml, $sectionheader);
    eln_save_new_version_section($course, $cm, $eln, $subwiki, $pagename,
            $sourcepage->xhtml, $newcontent, $sectiondetails, $formdata);
}

/**
 * Obtains various details about a named section. (This function will call error()
 * if it can't find the section; it shouldn't fail if the section was checked with
 * eln_find_sections.)
 *
 * @param string $content XHTML content
 * @param string $sectionheader for the new section
 * @return Object containing ->startpos and ->content
 */
function eln_get_new_section_details($content, $sectionheader) {
    // Create new section details
    $result = new StdClass;
    $result->startpos = strlen($content);
    $result->size = 0;
    $result->content = $sectionheader;

    return $result;
}

/**
 * Obtains information about all the annotations for the given page.
 *
 * @param int $pageid ID of wiki page
 * @return array annotations indexed by annotation id. Returns an empty array if none found.
 */
function eln_get_annotations($pageversion) {
    global $DB;

    $annotations = array();

    $userfields = user_picture::fields('u', null, 'userid');

    $rs = $DB->get_records_sql("SELECT a.id, a.pageid, a.userid, a.timemodified,
                                    a.content, $userfields
                                FROM {eln_annotations} a
                                INNER JOIN {user} u ON a.userid = u.id
                                    WHERE a.pageid = ?
                                    ORDER BY a.id", array($pageversion->pageid));

    // look through the results and check for orphanes annotations.
    // Also set the position and tag for later use.
    if ($rs) {
        $annotations = $rs;
        foreach ($annotations as &$annotation) {
            $spanstr = '<span id="annotation'.$annotation->id.'">';
            $position = strpos($pageversion->xhtml, $spanstr);
            if ($position !== false) {
                $annotation->orphaned = 0;
                $annotation->position = $position;
                $annotation->annotationtag = $spanstr;
            } else {
                $annotation->orphaned = 1;
                $annotation->position = '';
                $annotation->annotationtag = '';
            }
            $annotation->content = $annotation->content;
        }
    }

    return $annotations;
}

/**
 * Sets up the annotation markers
 *
 * @param string $xhtmlcontent The content (xhtml) to be displayed
 * @param int $pageid ID of wiki page
 * @return array annotations indexed by annotation id. Returns an empty array if none found.
 */
function eln_setup_annotation_markers($xhtmlcontent) {
    $content = $xhtmlcontent;
    // get lists of all the tags
    $pattern = '~</?.+?>~';
    $taglist = array();
    $tagcount = preg_match_all($pattern, $content, $taglist, PREG_OFFSET_CAPTURE);

    $pattern = '~\[\[.+?]\]~';
    $taglist2 = array();
    $tagcount = preg_match_all($pattern, $content, $taglist2, PREG_OFFSET_CAPTURE);

    // merge the lists together
    $taglist = array_merge($taglist[0], $taglist2[0]);

    // create a new array of tags against char positions.
    $tagpositions = array();
    foreach ($taglist as $tag) {
        $tagpositions[$tag[1]] = $tag[0];
    }

    // look at each postion, check it's not within a tag and create a list of space locations
    $spacepositions = array();
    $newcontent = '';
    $prevpos = 0;
    $space = false;
    $markeradded = false;
    $pos = 0;
    while ($pos < strlen($content)) {
        // we check if the $pos is the start of a tag and do something for particular tags
        if (array_key_exists($pos, $tagpositions)) {
            if ($tagpositions[$pos] == '<p>') {
                $newcontent .= $tagpositions[$pos];
                $pos += strlen($tagpositions[$pos]);
                $newcontent .= eln_get_annotation_marker($pos);
                $markeradded = true;
                $space = false;
                continue;
            } else if ($tagpositions[$pos] == '</p>') {
                $newcontent .= eln_get_annotation_marker($pos);
                $newcontent .= $tagpositions[$pos];
                $pos += strlen($tagpositions[$pos]);
                $markeradded = true;
                $space = false;
                continue;
            } else if (strpos($tagpositions[$pos], '<span id="annotation') !== false) {
                // we're at the opening annotation tag span so we need to skip past </span>
                // which is the next tag in $tagpositions[]
                $newcontent .= $tagpositions[$pos];
                $pos += strlen($tagpositions[$pos]);
                while (!array_key_exists($pos, $tagpositions)) {
                    $newcontent .= substr($content, $pos, 1);
                    $pos++;
                    // print_object('while '.$pos);// jb23347 commented out as looks like debugging
                }

                $newcontent .= $tagpositions[$pos];
                $pos += strlen($tagpositions[$pos]);
                $markeradded = true;
                continue;
            } else if (strpos($tagpositions[$pos], '<a ') !== false) {
                // markers are not added in the middle of an anchor tag so need to skip
                // to after the closing </a> in $tagpositions[]
                $newcontent .= eln_get_annotation_marker($pos);
                $markeradded = true;
                $space = true;
                $newcontent .= $tagpositions[$pos];
                $pos += strlen($tagpositions[$pos]);
                while (!array_key_exists($pos, $tagpositions)) {
                    $newcontent .= substr($content, $pos, 1);
                    $pos++;
                }

                $newcontent .= $tagpositions[$pos];
                $pos += strlen($tagpositions[$pos]);
                continue;
            } else {
                $newcontent .= $tagpositions[$pos];
                $pos += strlen($tagpositions[$pos]);
                continue;
            }
        }

        // if we have not already inserted a marker then check for a space
        // next time through we can check for a non space char indicating the start of a new word
        if (!$markeradded) {
            // this is the first char so if no marker has been added due to a <p> then
            // pretend the preceding char was a space to force adding a marker
            if ($pos == 0) {
                $space = true;
            }
            if (substr($content, $pos, 1) === ' ') {
                $space = true;
            } else if ($space) {
                $newcontent .= eln_get_annotation_marker($pos);
                $space = false;
            }

            // add the current charactor from the original content
            $newcontent .= substr($content, $pos, 1);
            $pos++;
        } else {
            $markeradded = false;
        }
    }

    $content = $newcontent;
    return $content;
}

/**
 * Returns a formatted annotation marker
 *
 * @param integer $position The character position of the annotation
 * @return string the formatted annotation marker
 */
function eln_get_annotation_marker($position) {
    global $OUTPUT;

    $icon = '<img src="'.$OUTPUT->pix_url('annotation-marker', 'eln').'" alt="'.
            get_string('annotationmarker', 'eln').'" title="'.
            get_string('annotationmarker', 'eln').'" />';
    return '<span class="eln-annotation-marker" id="marker'.$position.'">'.$icon.'</span>';
}

/**
 * Highlights existing annotations in the xhtml for display.
 *
 * @param string $xhtmlcontent The content (xhtml) to be displayed: output variable
 * @param object $annotations List of annotions in a object
 * @param string $page The page being displayed
 * @return string content (xhtml) to be displayed
 */
function eln_highlight_existing_annotations($xhtmlcontent, $annotations, $page) {
    global $OUTPUT, $PAGE;
    $elnoutput = $PAGE->get_renderer('mod_eln');

    $content = $xhtmlcontent;

    $icon = '<img src="'.$OUTPUT->pix_url('annotation', 'eln').'" alt="'.
            get_string('expandannotation', 'eln').'" title="'.
            get_string('expandannotation', 'eln').'" />';

    usort($annotations, "eln_internal_position_sort");
    // we only need the used annotations, not the orphaned ones.
    $usedannotations = array();
    foreach ($annotations as $annotation) {
        if (!$annotation->orphaned) {
            $usedannotations[$annotation->id] = $annotation;
        }
    }

    $annotationnumber = count($usedannotations);
    if ($annotationnumber) {
        // cycle through the annotations and process ready for display
        foreach ($usedannotations as $annotation) {
            switch ($page) {
                case 'view':
                    $elnoutput = $PAGE->get_renderer('mod_eln');
                    $replace = '<span class="eln-annotation-tag" id="annotation'.
                        $annotation->id.'">'.
                        $icon.$elnoutput->eln_print_hidden_annotation($annotation);
                    break;
                case 'annotate':
                    $replace = '<span id="zzzz'.$annotationnumber.'"><strong>('.
                            $annotationnumber.')</strong>';
                    break;
                case 'edit':
                    $replace = $annotation->annotationtag.'&nbsp;';
                    break;
                case 'portfolio':
                    $replace = '<span id="annotation'.$annotation->id.'">'.
                            $elnoutput->eln_print_portfolio_annotation($annotation);
                    break;
                case 'clear' :
                    $replace = '<span>';
                    break;
            }
            $content = str_replace($annotation->annotationtag, $replace, $content);
            $annotationnumber--;
        }
        if ($page === 'clear') {
            // Get rid of any empty tags added by clear
            $content = str_replace('<span></span>', '', $content);
        }
    }
    return $content;
}

/**
 * Inserts new annotations into the xhtml at the marker location
 *
 * @param string $marker The marker id added to the annotation edit page
 * @param string &$xhtml A reference to the subwiki xhtml
 * @param string $content The content of the annotation
 */
function eln_insert_annotation($position, &$xhtml, $id) {
    $replace = '<span id="annotation'.$id.'"></span>';
    $xhtml = substr_replace($xhtml, $replace, $position, 0);
}

// Array sort callback function
function eln_internal_position_sort($a, $b) {
    return intval($b->position) - intval($a->position);
}

/**
 * Cleans up the annotation tags
 *
 * @param $updated_annotations
 * @param string &$xhtml A reference to the subwiki xhtml
 * @return bool $result
 */
function eln_cleanup_annotation_tags($updated_annotations, &$xhtml) {
    $result = false;
    $matches = array();
    $pattern = '~<span\b.id=\"annotation([0-9].+?)\"[^>]*>(.*?)</span>~';

    preg_match_all($pattern, $xhtml, $matches);
    foreach ($matches[1] as $match) {
        if (!array_key_exists($match, $updated_annotations)) {
            $deletepattern = '~<span\b.id=\"annotation'.$match.'\">.*?</span>~';
            $xhtml = preg_replace($deletepattern, '', $xhtml);
            $result = true;
        }
    }

    return $result;
}

/**
 * Sets the page editing lock according to $lock
 *
 * @param integer $pageid Wiki page id
 * @param bool $lock
 * @return nothing
 */
function eln_lock_editing($pageid, $lock) {
    global $DB;

    $locked = eln_is_page_editing_locked($pageid);

    if ($lock != $locked) {
        $dataobject = new stdClass();
        $dataobject->id = $pageid;
        $dataobject->locked = ($lock) ? 1 : 0;

        try {
            $DB->update_record('eln_pages', $dataobject);
        } catch (Exception $e) {
            eln_dberror($e, 'Could not change the lock status for this wiki page');
        }
    }
}

/**
 * Returns the lock status of a wiki page
 *
 * @param integer $pageid Wiki page id
 * @return bool True if locked
 */
function eln_is_page_editing_locked($pageid) {
    global $DB;

    $rs = $DB->get_records_sql('SELECT locked FROM {eln_pages} WHERE id = ?', array($pageid));

    foreach ($rs as $record) {
        return ($record->locked == '1') ? true : false;
    }

    return false;
}

/**
 * Sets up the lock page button and form html
 *
 * @param object $pageversion Page/version object
 * @param int $cmid Course module id
 * @return string $result Contains the html for the form
 */
function eln_display_lock_page_form($pageversion, $cmid) {
    $result='';

    $genericformdetails ='<form method="get" action="lock.php">
    <div class="eln_lock_div">
    <input type="hidden" name="ouw_pageid" value="'.$pageversion->pageid.'" />
    <input type="hidden" name="id" value="'.$cmid.'" />';
    $buttonvalue = ($pageversion->locked == '1') ?  get_string('unlockpage', 'eln') :
            get_string('lockpage', 'eln');

    $result .= '<div id="eln_lock">'.
    $genericformdetails.
    '<input type="submit" id="ouw_lock" name="ouw_lock" value="'.$buttonvalue.'" />
    </div>
    </form>
    </div>';

    return $result;
}

/**
 * Sets up the editing lock
 *
 * @param object $lock
 * @param string $eln
 */
function eln_print_editlock($lock, $eln) {
    global $DB, $PAGE;

    // Prepare the warning about lock without JS...
    $a = new StdClass;
    $a->now = userdate(time(), get_string('strftimetime'));
    $a->minutes = (int)(OUWIKI_LOCK_NOJS/60);
    $a->deadline = userdate(time() + $a->minutes*60, get_string('strftimetime'));
    $nojswarning = get_string('nojswarning', 'eln', $a);
    $nojsstart = '<p class="ouw_nojswarning">';

    // Put in the AJAX for keeping the lock, if on a supported browser
    $ie = core_useragent::check_browser_version('MSIE', 6.0);
    $ff = core_useragent::check_browser_version('Gecko', 20051106);
    $op = core_useragent::check_browser_version('Opera', 9.0);
    $sa = core_useragent::check_browser_version('Safari', 412);
    $ch = core_useragent::check_browser_version('Chrome', 14);
    $js = $ie || $ff || $op || $sa || $ch;
    if ($js) {
        $nojsdisabled = get_string('nojsdisabled', 'eln');
        $nojs = $nojsstart.$nojsdisabled.' '.$nojswarning.
            '<img src="nojslock.php?lockid='.$lock->id.'" alt=""/></p>';

        $strlockcancelled = eln_javascript_escape(get_string('lockcancelled', 'eln'));
        $intervalms = OUWIKI_LOCK_RECONFIRM * 1000;

        $timeoutscript = '';
        if ($eln->timeout) {
            $countdownurgent = eln_javascript_escape(get_string('countdownurgent', 'eln'));
            $timeoutscript = "var ouw_countdownto = (new Date()).getTime()+1000*{$eln->timeout};
                    var ouw_countdowninterval=setInterval(function() {
                    var countdown=document.getElementById('ouw_countdown');
                    var timeleft=ouw_countdownto-(new Date().getTime());
                    if (timeleft < 0) {
                        clearInterval(ouw_countdowninterval);
                        document.forms['mform1'].elements['save'].click();
                        return;
                    }
                    if(timeleft<2*60*1000) {
                        var urgent=document.getElementById('ouw_countdownurgent');
                        if(!urgent.firstChild) {
                            urgent.appendChild(document.createTextNode(\"".$countdownurgent."\"));
                            countdown.style.fontWeight='bold';
                            countdown.style.color='red';
                        }
                    }
                    var minutes=Math.floor(timeleft/(60*1000));
                    var seconds=Math.floor(timeleft/1000) - minutes*60;
                    var text=minutes+':';
                    if(seconds<10) text+='0';
                    text+=seconds;
                    while(countdown.firstChild) {
                        countdown.removeChild(countdown.firstChild);
                    }
                    countdown.appendChild(document.createTextNode(text));
                },500);
            ";
        }

        print "<script type='text/javascript'>
            var intervalID;
            function handleResponse(id, o) {
                if (o.responseText=='cancel') {
                    document.forms['mform1'].elements['preview'].disabled=true;
                    document.forms['mform1'].elements['save'].disabled=true;
                    clearInterval(intervalID);
                    alert(\"$strlockcancelled\");
                }
            }
            function handleFailure(o) {
                // Ignore for now
            }
            intervalID=setInterval(function() {
                var cfg = {
                    method: 'POST',
                    data: 'lockid={$lock->id}',
                    on: {
                        success: handleResponse,
                        failure: handleFailure
                    }
                };
                Y.io('confirmlock.php', cfg);
            }, $intervalms);
            $timeoutscript
            </script>
            <noscript>
            $nojs
            </noscript>
        ";
    } else {
        // If they have a non-supported browser, update the lock time right now without
        // going through the dodgy image method, to reserve their 15-minute slot.
        // (This means it will work for Lynx, for instance.)
        print $nojsstart.get_string('nojsbrowser', 'eln').' '.$nojswarning.'.</p>';
        $lock->seenat = time() + OUWIKI_LOCK_NOJS;
        $DB->update_record('eln_locks', $lock);
    }
}

/**
 * Get last-modified time for wiki, as it appears to this user. This takes into
 * account the user's groups/individual settings if required. (Does not check
 * that user can view the wiki.)
 *
 * @param object $cm Course-modules entry for wiki
 * @param object $Course Course object
 * @param int $userid User ID or 0 = current
 * @return int Last-modified time for this user as seconds since epoch
 */
function eln_get_last_modified($cm, $course, $userid = 0) {
    global $USER, $DB;

    if (!$userid) {
        $userid = $USER->id;
    }
    $eln = $DB->get_record('eln', array('id' => $cm->instance));

    // Default applies no restriction
    $restrictjoin = '';
    $restrictwhere = '';
    $context = context_module::instance($cm->id);

    switch($eln->subwikis) {
        case OUWIKI_SUBWIKIS_SINGLE:
            break;

        case OUWIKI_SUBWIKIS_GROUPS:
            if (!has_capability('moodle/site:accessallgroups', $context, $userid) &&
                groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS) {
                // Restrictions only in separate groups mode and if you don't have
                // access all groups
                $restrictjoin = "INNER JOIN {groups_members} gm ON gm.groupid = sw.groupid";
                $restrictwhere = "AND gm.userid = :userid";
            }
            break;

        case OUWIKI_SUBWIKIS_INDIVIDUAL:
            if (!has_capability('mod/eln:viewallindividuals', $context)) {
                // You can't view everyone so restrict.
                if (has_capability('mod/eln:viewgroupindividuals', $context)) {
                    // You can view everyone in your group - TODO this is complicated.
                    $restrictjoin = '
                        INNER JOIN {groups_members} gm ON gm.userid = sw.userid
                        INNER JOIN {groups} g ON g.id = gm.groupid
                        INNER JOIN {groups_members} gm2 ON gm2.groupid = g.id
                    ';
                    $restrictwhere = "AND g.courseid = :courseid AND gm2.userid = :userid";

                    if ($cm->groupingid) {
                        $restrictjoin .= "INNER JOIN {groupings_groups} gg ON gg.groupid = g.id";
                        $restrictwhere .= "AND gg.groupingid = :groupingid";
                    }
                } else {
                    // You can only view you.
                    $restrictwhere = 'AND sw.userid = :userid';
                }
            }
            break;
    }

    // Query for newest version that follows these restrictions
    $sql = "SELECT MAX(v.timecreated)
        FROM {eln_versions} v
            INNER JOIN {eln_pages} p ON p.id = v.pageid
            INNER JOIN {eln_subwikis} sw ON sw.id = p.subwikiid
            $restrictjoin
        WHERE sw.wikiid = :wikiid AND v.deletedat IS NULL
        $restrictwhere";

    $params = array(
        'courseid'   => $course->id,
        'userid'     => $userid,
        'groupingid' => $cm->groupingid,
        'wikiid'     => $cm->instance
    );

    return $DB->get_field_sql($sql, $params);
}

/**
 * Returns html for a search form for the nav bar
 * @param object $subwiki wiki to be searched
 * @param int $cmid wiki to be searched
 * @return string html
 */
function eln_get_search_form($subwiki, $cmid) {
    if (!eln_search_installed()) {
        return '';
    }
    global $OUTPUT, $CFG;
    $query = optional_param('query', '', PARAM_RAW);
    $out = html_writer::start_tag('form', array('action' => 'search.php', 'method' => 'get'));
    $out .= html_writer::start_tag('div');
    $out .= html_writer::tag('label', get_string('search', 'eln'),
            array('for' => 'eln_searchquery'));
    $out .= $OUTPUT->help_icon('search', 'eln');
    $out .= html_writer::empty_tag('input',
            array('type' => 'hidden', 'name' => 'id', 'value' => $cmid));
    if (!$subwiki->defaultwiki) {
        if ($subwiki->groupid) {
            $out .= html_writer::empty_tag('input',
                    array('type' => 'hidden', 'name' => 'group', 'value' => $subwiki->groupid));
        }
        if ($subwiki->userid) {
            $out .= html_writer::empty_tag('input',
                    array('type' => 'hidden', 'name' => 'user', 'value' => $subwiki->userid));
        }
    }
    $out .= html_writer::empty_tag('input', array('type' => 'text', 'name' => 'query',
            'id' => 'eln_searchquery', 'value' => $query));
    $out .= html_writer::empty_tag('input', array('type' => 'image',
            'id' => 'ousearch_searchbutton', 'alt' => get_string('search'),
            'title' => get_string('search'), 'src' => $OUTPUT->pix_url('i/search')));
    $out .= html_writer::end_tag('div');
    $out .= html_writer::end_tag('form');
    return $out;
}

/**
 * Returns a wordcount for the given content
 *
 * @param string $content
 * @returns int
 */
function eln_count_words($content) {

    // Strip tags and convert entities to text
    $content = html_entity_decode(strip_tags($content), ENT_QUOTES, 'UTF-8');

    // combine to a single word
    // hyphen
    // apostrophe
    // left single quote
    // right single quote
    $content = str_replace('-', '', $content);
    $content = str_replace('\'', '', $content);
    $content = str_replace(html_entity_decode('&lsquo;', ENT_QUOTES, 'UTF-8'), '', $content);
    $content = str_replace(html_entity_decode('&rsquo;', ENT_QUOTES, 'UTF-8'), '', $content);

    // add a space for comma
    $content = str_replace(',', ' ', $content);

    // non-breaking space to space
    $content = str_replace(html_entity_decode('&nbsp;', ENT_QUOTES, 'UTF-8'), ' ', $content);

    // Remove:
    // 0 - empty lines
    // 1 - double spaces
    $pattern[0] = '/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/';
    $pattern[1] = '/\s\s+/';
    $content = preg_replace($pattern, ' ', $content);

    // trim again for extra spaces created
    $content = trim($content);

    if (empty($content)) {
        return 0;
    } else {
        return 1 + substr_count($content, ' ');
    }
}

/**
 * Returns a difference in wordcounts between two
 * versions as a string.
 *
 * @param int $current
 * @param int $previous
 * @param mixed $previouspage false if this is the first page
 * @returns string
 */
function eln_wordcount_difference($current, $previous, $previouspage = null) {

    if (!$previouspage) {
        return $current;
    }

    if ($previous == 0) {
        return "+$current";
    }

    if ($current == 0) {
        return "-$previous";
    }

    if ($current == $previous) {
        return '';
    }

    $diff = $current - $previous;
    if ($diff <= 0) {
        return $diff;
    } else {
        return "+$diff";
    }
}

/**
 * Checks what level of participation the currently
 * logged in user can view
 *
 * @param object $course
 * @param object $eln
 * @param object $subwiki
 * @param object $cm
 * @param integer $userid default null is the current user
 * @return integer
 */
function eln_can_view_participation($course, $eln, $subwiki, $cm, $userid = null) {
    global $USER;
    if (!$userid) {
        $userid = $USER->id;
    }
    $context = context_module::instance($cm->id);

    $groupmode = groups_get_activity_groupmode($cm, $course);
    $groupid = $subwiki->groupid;

    $allowgroup =
            ($groupmode == NOGROUPS || $groupmode == VISIBLEGROUPS)
            || (has_capability('moodle/site:accessallgroups', $context, $userid))
            || (groups_is_member($groupid, $userid));

    if (has_capability('mod/eln:viewparticipation', $context, $userid) && $allowgroup) {
            return OUWIKI_USER_PARTICIPATION;
    } else if ((int)$eln->subwikis != OUWIKI_SUBWIKIS_INDIVIDUAL &&
            has_capability('mod/eln:edit', $context, $userid) && $allowgroup) {
        return OUWIKI_MY_PARTICIPATION;
    }

    return OUWIKI_NO_PARTICIPATION;
}

/**
 * Returns a single users participation for userparticipation.php
 *
 * $param int $userid
 * @param object $subwiki
 * @return array user participation records
 */
function eln_get_user_participation($userid, $subwiki) {
    global $DB;

    $params = array(
        'subwikiid' => $subwiki->id,
        'userid'    => $userid
    );

    $sql = 'SELECT v.id, p.title, v.timecreated, v.wordcount, p.id AS pageid,
                v.previousversionid, v.importversionid,
            (SELECT v2.wordcount
                FROM {eln_versions} v2
                WHERE v2.id = v.previousversionid)
            AS previouswordcount, p.currentversionid, p.firstversionid
        FROM {eln_pages} p
            INNER JOIN {eln_versions} v ON v.pageid = p.id
        WHERE p.subwikiid = :subwikiid AND v.userid = :userid
            AND v.deletedat IS NULL
        ORDER BY v.timecreated ASC';
    $changes = $DB->get_records_sql($sql, $params);

    $user = eln_get_user($userid);

    return array($user, $changes);
}

/**
 * Retrieve a user object
 *
 * @param integer $userid
 * @return object user record
 */
function eln_get_user($userid) {
    global $DB;
    $fields = user_picture::fields();
    $fields .= ',username,idnumber';
    $user = $DB->get_record('user', array('id' => $userid), $fields, MUST_EXIST);
    return $user;
}

/**
 * Returns users to view in participation.php and related version
 * change information
 *
 * @param object $eln
 * @param object $subwiki
 * @param object $context
 * @param int $groupid
 * @param string $sort
 * @return array user participation
 */
function eln_get_participation($eln, $subwiki, $context,
    $groupid, $sort = 'u.firstname, u.lastname') {
    global $DB;

    // get user objects
    list($esql, $params) = get_enrolled_sql($context, 'mod/eln:edit', $groupid);
    $fields = user_picture::fields('u');
    $fields .= ',u.username,u.idnumber';
    $sql = "SELECT $fields
                FROM {user} u
                JOIN ($esql) eu ON eu.id = u.id
                ORDER BY $sort ASC";
    $users = $DB->get_records_sql($sql, $params);

    $join = '';
    $where = ' WHERE v.userid IN (' . implode(',', array_keys($users)) .')';
    if ((int)$eln->subwikis == OUWIKI_SUBWIKIS_INDIVIDUAL) {
        $params['elnid'] = $eln->id;
        $where = ' AND s.wikiid = :elnid';
        $join = 'JOIN {eln_subwikis} s ON s.id = p.subwikiid';
    } else {
        $params['subwikiid'] = $subwiki->id;
        $where = ' AND p.subwikiid = :subwikiid';
    }

    $vsql = "SELECT v.id AS versionid, v.wordcount, v.importversionid,
                    p.id AS pageid, p.subwikiid, p.title, p.currentversionid,
                    v.userid AS userid, v.previousversionid,
                (SELECT v2.wordcount
                    FROM {eln_versions} v2
                    WHERE v2.id = v.previousversionid)
                AS previouswordcount, p.firstversionid
            FROM {eln_pages} p
                $join
                JOIN {eln_versions} v ON v.pageid = p.id
            $where AND v.deletedat IS NULL
            ORDER BY v.id ASC";
    $versions = $DB->get_records_sql($vsql, $params);

    $changes = array('users' => $users, 'versions' => $versions);

    return eln_sort_participation($changes);
}

/**
 * Sorts version data and calculates changes
 * per user for rendering
 *
 * @param array $data
 * @return array
 */
function eln_sort_participation($data) {
    global $DB;

    if (empty($data['users'])) {
        return array(); // no users
    }
    if (empty($data['versions'])) {
        return $data['users']; // users but no versions
    }

    $byusers = $data['users'];
    foreach ($data['versions'] as $version) {
        if (isset($byusers[$version->userid])) {

            // setup properties
            if (!isset($byusers[$version->userid]->wordsadded)) {
                $byusers[$version->userid]->wordsadded = 0;
            }
            if (!isset($byusers[$version->userid]->wordsdeleted)) {
                $byusers[$version->userid]->wordsdeleted = 0;
            }
            if (!isset($byusers[$version->userid]->pagecreates)) {
                $byusers[$version->userid]->pagecreates = 0;
            }
            if (!isset($byusers[$version->userid]->pageedits)) {
                $byusers[$version->userid]->pageedits = 0;
            }
            if (!isset($byusers[$version->userid]->pageimports)) {
                $byusers[$version->userid]->pageimports = array();
            }

            // calculations
            if ($version->versionid == $version->firstversionid) {
                $byusers[$version->userid]->pagecreates++;

                // user created this page so entire wordcount is valid
                if (isset($version->wordcount)) {
                    $byusers[$version->userid]->wordsadded += $version->wordcount;
                }
            } else {
                $byusers[$version->userid]->pageedits++;

                // wordcount calculation
                if (isset($version->wordcount)) {
                    if ($version->previouswordcount) {
                        $words = eln_wordcount_difference($version->wordcount,
                            $version->previouswordcount, true);
                    } else {
                        $words = eln_wordcount_difference($version->wordcount, 0, false);
                    }
                    if ($words < 0) {
                        $byusers[$version->userid]->wordsdeleted += abs($words);
                    } else {
                        $byusers[$version->userid]->wordsadded += abs($words);
                    }
                }
            }
            if (!empty($version->importversionid)) {
                $byusers[$version->userid]->pageimports[$version->pageid] = 1;
            }
        }
    }

    // return sorted array
    return $byusers;
}

/**
 * Grades users from the participation.php page
 *
 * @param array $newgrades
 * @param object $cm
 * @param object $eln
 * @param object $course
 */
function eln_update_user_grades($newgrades, $cm, $eln, $course) {
    global $CFG, $SESSION;

    require_once($CFG->libdir.'/gradelib.php');
    $grading_info = grade_get_grades($course->id, 'mod',
        'eln', $eln->id, array_keys($newgrades));

    foreach ($grading_info->items[0]->grades as $key => $grade) {
        if (array_key_exists($key, $newgrades)) {
            if ($newgrades[$key] != $grade->grade) {
                if ($newgrades[$key] == -1) {
                    // no grade
                    $grade->rawgrade = null;
                } else {
                    $grade->rawgrade = $newgrades[$key];
                }
                $grade->userid = $key;
                $eln->cmidnumber = $cm->id;

                eln_grade_item_update($eln, $grade);
            }
        }
    }

    // add a message to display to the page
    if (!isset($SESSION->elngradesupdated)) {
        $SESSION->elngradesupdated = get_string('gradesupdated', 'eln');
    }
}


/* Display eln in structured view helper functions */

function eln_display_wikiindex_page_in_index($indexitem, $subwiki, $cm) {
    global $eln;
    if ($startpage = $indexitem->title === '') {
        $title = get_string('startpage', 'eln');
        $output = '<div class="ouw_index_startpage">';
    } else {
        $title = $indexitem->title;
        $output = '';
    }

    $output .= '<div class="ouw_title"><a class="ouw_title_link" href="view.php?' .
            eln_display_wiki_parameters($indexitem->title, $subwiki, $cm).
            '">' . htmlspecialchars($title) . '</a></div>';
    $lastchange = new StdClass;
    $lastchange->userlink = eln_display_user($indexitem, $cm->course);
    $lastchange->date = eln_recent_span($indexitem->timecreated).eln_nice_date($indexitem->timecreated) . '</span>';
    $output .= '<div class="ouw_indexinfo">';
    if ($eln->enablewordcount) {
        $output .= '<span class="ouw_wordcount">' . get_string('numwords', 'eln', $indexitem->wordcount) . '</span>';
        $output .= '<div class="spacer"></div>';
    }
    $output .= ' <span class="ouw_lastchange">' . get_string('lastchange', 'eln', $lastchange) . '</span>';
    $output .= '</div>';
    if ($startpage) {
        $output .= '</div>';
    }
    return $output;
}

function eln_display_entirewiki_page_in_index($pageinfo, $subwiki, $cm, $index, $context) {
    global $eln;

    // Get page details.
    $pageversion = eln_get_current_page($subwiki, $pageinfo->title);
    // If the page hasn't really been created yet, skip it.
    if (is_null($pageversion->xhtml)) {
        continue;
    }
    $visibletitle = $pageversion->title === '' ? get_string('startpage', 'eln') : $pageversion->title;

    $pageversion->xhtml = file_rewrite_pluginfile_urls($pageversion->xhtml, 'pluginfile.php',
            $context->id, 'mod_eln', 'content', $pageversion->versionid);
    $pageversion->xhtml = eln_convert_content($pageversion->xhtml, $subwiki, $cm, $index, $pageversion->xhtmlformat);

    $output = '<div class="ouw_entry"><a name="' . $pageversion->pageid . '"></a><h1 class="ouw_entry_heading"><a href="view.php?' .
            eln_display_wiki_parameters($pageversion->title, $subwiki, $cm) .
            '">' . htmlspecialchars($visibletitle) . '</a></h1>';
    $output .=  $pageversion->xhtml;
    $output .=  '</div>';

    return $output;
}

function eln_display_portfolio_page_in_index($pageversion) {

    if (is_null($pageversion->xhtml)) {
        return;
    }

    $output = '<div class="ouw_entry">';
    $output .=  $pageversion->xhtml;
    $output .=  '</div>';
    return $output;
}

function eln_build_up_sub_index($pageid, $index, &$subtree) {
    $thispage = $index[$pageid];
    if (count($thispage->linksto) > 0) {
        foreach ($thispage->linksto as $childid) {
            eln_build_up_sub_index($childid, $index, $subtree);
        }
    }
    $subtree[$pageid] = $thispage;
}

function eln_get_sub_tree_from_index($pageid, $index) {
    $subtree = array();
    $thispage = $index[$pageid];
    $subtree[$pageid] = $thispage;
    if (!empty($thispage->linksto)) {
        foreach ($thispage->linksto as $pageidid) {
            eln_build_up_sub_index($pageid, $index, $subtree);
        }
    }
    return $subtree;
}

function eln_tree_index($func, $pageid, &$index = null, $subwiki = null, $cm = null, $context = null, $check = null) {
    $extra = '';
    $thispage = $index[$pageid];
    if ($check) {
        // Add a checkbox against this page.
        $extra = html_writer::checkbox('page' . $thispage->pageid, $thispage->pageid, false, null,
                array('id' => 'page' . $thispage->pageid, 'class' => 'eln_page_checkbox'));
        $extra .= get_accesshide(get_string('pagecheckboxlabel', 'eln', $thispage->title), 'label',
                '', 'for="page' . $thispage->pageid . '"');
    }
    $output = '<li>' . $extra . $func($thispage, $subwiki, $cm, $index, $context);
    if (count($thispage->children) > 0) {
        $output .= '<ul>';
        foreach ($thispage->children as $childid) {
            $output .= eln_tree_index($func, $childid, $index, $subwiki, $cm, $context, $check);
        }
        $output .= '</ul>';
    }
    $output .= '</li>';
    return $output;
}

/**
 * Builds the tree structure for the hierarchical index. This is basically
 * a breadth-first search: we place each page on the nearest-to-home level
 * that we can find for it.
 */
function eln_build_tree(&$index) {
    // Set up new data to fill.
    foreach ($index as $indexitem) {
        $indexitem->linksto = array();
        $indexitem->children = array();
    }

    // Preprocess: build links TO as well as FROM.
    foreach ($index as $indexitem) {
        foreach ($indexitem->linksfrom as $fromid) {
            $index[$fromid]->linksto[] = $indexitem->pageid;
        }
    }

    // Begin with top level, which is first in results.
    reset($index);
    $index[key($index)]->placed = true;
    $nextlevel = array(reset($index)->pageid);
    do {
        $thislevel = $nextlevel;
        $nextlevel = array();
        foreach ($thislevel as $sourcepageid) {
            foreach ($index[$sourcepageid]->linksto as $linkto) {
                if (empty($index[$linkto]->placed)) {
                    $index[$linkto]->placed = true;
                    $index[$sourcepageid]->children[] = $linkto;
                    $nextlevel[] = $linkto;
                }
            }
        }
    } while (count($nextlevel) > 0);
}

function eln_has_subwikis($elnid) {
    $rs = eln_get_subwikis($elnid);
    if (!empty($rs)) {
        return true;
    } else {
        return false;
    }
}

function eln_get_subwikis($wikiid) {
    global $DB;
    $rs = $DB->get_records('eln_subwikis', array('wikiid' => $wikiid));
    return $rs;
}

function eln_subwiki_content_exists($subwikiid) {
    global $DB;
    $rs = $DB->get_records_select('eln_pages', 'subwikiid = ? AND currentversionid IS NOT null', array($subwikiid));
    if (!empty($rs)) {
        return true;
    } else {
        return false;
    }
}

function eln_get_wiki_details($version) {
    global $DB, $COURSE;

    $sql = 'SELECT * from {eln} w, {eln_subwikis} s, {eln_pages} p, {eln_versions} v
    WHERE
    s.id = p.subwikiid
    AND w.id = s.wikiid
    AND p.id = v.pageid
    AND v.id = ?';

    $selectedeln = $DB->get_record_sql($sql, array($version), MUST_EXIST);
    $selectedeln->group = null;
    $selectedeln->user = null;
    $selectedeln->courseshortname = null;
    if ($selectedeln->groupid) {
        $selectedeln->group = groups_get_group_name($selectedeln->groupid);
    } else if ($selectedeln->subwikis == OUWIKI_SUBWIKIS_INDIVIDUAL && $selectedeln->userid) {
        $user = $DB->get_record('user', array('id' => $selectedeln->userid));
        $selectedeln->user = fullname($user);
    }
    if ($COURSE->id != $selectedeln->course) {
        $selectedeln->courseshortname = $DB->get_field('course', 'shortname', array('id' => $selectedeln->course));
    }
    return $selectedeln;
}

/**
 * eln_portfolio_caller_base abstract class to extend portfolio_module_caller_base
 */
abstract class eln_portfolio_caller_base extends portfolio_module_caller_base {
    protected $withannotations;

    protected $subwiki, $eln, $modcontext;

    // Attachments: array of arrays of stored_file, keyed on versionid.
    protected $attachments = array();

    protected function load_base_data($subwikiid) {
        global $DB, $COURSE;

        // Load base data.
        $this->subwiki = $DB->get_record(
                'eln_subwikis', array('id' => $subwikiid), '*', MUST_EXIST);
        $this->eln = $DB->get_record(
                'eln', array('id' => $this->subwiki->wikiid), '*', MUST_EXIST);
        if (!empty($COURSE->id) && $COURSE->id == $this->eln->course) {
            $course = $COURSE;
        } else {
            $course = $DB->get_record(
                    'course', array('id' => $this->eln->course), '*', MUST_EXIST);
        }
        $modinfo = get_fast_modinfo($course);
        $instances = $modinfo->get_instances_of('eln');
        if (!array_key_exists($this->eln->id, $instances)) {
            throw new portfolio_caller_exception('error_export', 'eln');
        }
        $this->cm = $instances[$this->eln->id];
        $this->modcontext = context_module::instance($this->cm->id);
    }

    /**
     * Adds all the files from the given pageversions.
     * @param array $pageversions
     */
    protected function add_files($pageversions) {
        // This doesn't scale (2 queries/page) but follows the API. We could do,
        // it faster but I'm doubtful about bypassing the API in this case.
        $fs = get_file_storage();
        $files = array();
        foreach ($pageversions as $pageversion) {
            $attach = $fs->get_area_files($this->modcontext->id, 'mod_eln', 'attachment',
                    $pageversion->versionid, "sortorder, itemid, filepath, filename", false);
            $this->attachments[$pageversion->versionid] = $attach;
            $embed  = $fs->get_area_files($this->modcontext->id, 'mod_eln', 'content',
                    $pageversion->versionid, "sortorder, itemid, filepath, filename", false);
            $files = array_merge($files, $attach, $embed);
        }
        $this->set_file_and_format_data($files);

        if (empty($this->multifiles) && !empty($this->singlefile)) {
            $this->multifiles = array($this->singlefile); // Copy_files workaround.
        }
        // If there are files, change to rich/plain.
        if (!empty($this->multifiles)) {
            $this->add_format(PORTFOLIO_FORMAT_RICHHTML);
        } else {
            $this->add_format(PORTFOLIO_FORMAT_PLAINHTML);
        }
    }

    /**
     * @param array $files Array of file items to copy
     * @return void
     */
    protected function copy_files($files) {
        if (empty($files)) {
            return;
        }
        foreach ($files as $f) {
            $this->get('exporter')->copy_existing_file($f);
        }
    }

    /**
     * Obtains page html suitable for use in portfolio export.
     * @param object $pageversion Page and version data
     * @param array $attachments Attachments array indexed by versionid
     * @param object $context Moodle context object
     * @param object $eln OU wiki object
     * @param object $subwiki Subwiki object
     * @param object $course Course object
     * @param bool $withannotations If true, includes annotations
     * @param portfolio_format $portfolioformat Portfolio format
     * @param string $plugin the portfolio plugin being used.
     * @return string HTML code
     */
    public static function get_page_html($pageversion, $attachments,
            $context, $eln, $subwiki, $course, $withannotations,
            portfolio_format $portfolioformat, $plugin) {
        global $DB;

        // Format the page body.
        $options = portfolio_format_text_options();
        $options->filter = true;
        $formattedtext = portfolio_rewrite_pluginfile_urls($pageversion->xhtml, $context->id,
                'mod_eln', 'content', $pageversion->versionid, $portfolioformat);
        $formattedtext = format_text($formattedtext, $pageversion->xhtmlformat,
                $options, $course->id);

        // Get annotations - only if using annotation system. prevents unnecessary db access.
        if ($eln->annotation) {
            $annotations = eln_get_annotations($pageversion);
        } else {
            $annotations = array();
        }

        // Convert or remove the annotations.
        if ($eln->annotation && count($annotations)) {
            eln_highlight_existing_annotations($formattedtext, $annotations,
                    $withannotations ? 'portfolio' : 'clear');
        }

        // Do overall page, starting with title.
        $title = $pageversion->title;
        if ($title === '') {
            $title = get_string('startpage', 'eln');
        }
        $output = html_writer::tag('h2', s($title));

        // Last change info.
        $user = new stdClass();
        foreach (explode(',', user_picture::fields()) as $field) {
            if ($field == 'id') {
                $user->id = $pageversion->userid;
            } else {
                $user->$field = $pageversion->$field;
            }
        }
        $lastchange = get_string('lastchange', 'eln', (object)array(
                'date' => userdate($pageversion->timecreated),
                'userlink' => eln_display_user($user, $course->id)));
        $output .= html_writer::tag('p', html_writer::tag('small',
                html_writer::tag('i', $lastchange)));

        // Main text.
        $output .= html_writer::tag('div', $formattedtext);

        // Word count.
        if ($eln->enablewordcount) {
            $wordcount = get_string('numwords', 'eln', $pageversion->wordcount);
            $output .= html_writer::tag('div', html_writer::empty_tag('br'));
            $output .= html_writer::tag('p',
                    html_writer::tag('small', $wordcount),
                    array('class' => 'ouw_wordcount'));
        }

        // Attachments.
        if ($attachments[$pageversion->versionid]) {
            $output .= html_writer::start_tag('div', array('class' => 'attachments'));
            $output .= html_writer::tag('h3', get_string('attachments', 'eln'));
            $output .= html_writer::start_tag('ul');
            foreach ($attachments[$pageversion->versionid] as $file) {
                if ($plugin == 'rtf') {
                    $filename = $file->get_filename();
                    $path = moodle_url::make_pluginfile_url($context->id, 'mod_eln',
                            'attachment', $pageversion->versionid, '/', $filename, true);
                    $atag = html_writer::tag('a', $filename, array('href' => $path));
                } else {
                    $atag = $portfolioformat->file_output($file);
                }
                $output .= html_writer::tag('li', $atag);
            }
            $output .= html_writer::end_tag('ul');
            $output .= html_writer::end_tag('div');
        }

        // Replace all user links with user name so that you can not access user links from within exported document.
        $output = preg_replace('~<a href="[^"]*/user/view.php[^"]*"\s*>(.*?)</a>~', '$1', $output);
        return $output;
    }

    public function get_navigation() {
        global $CFG;

        $navlinks = array();
        $navlinks[] = array(
                'name' => format_string($this->eln->name),
                'link' => $CFG->wwwroot . '/mod/eln/wikiindex.php?id=' . $this->cm->id,
                'type' => 'title'
        );
        return array($navlinks, $this->cm);
    }

    public function expected_time() {
        return $this->expected_time_file();
    }

    public function check_permissions() {
        $context = context_module::instance($this->cm->id);
        return (has_capability('mod/eln:view', $context));
    }

    public static function display_name() {
        return get_string('modulename', 'eln');
    }

    public static function base_supported_formats() {
        return array(PORTFOLIO_FORMAT_RICHHTML, PORTFOLIO_FORMAT_PLAINHTML);
    }

    /**
     * @param string $name Name to be used in filename
     * @return string Safe version of name (replaces unknown characters with _)
     */
    protected function make_filename_safe($name) {
        $result = @preg_replace('~[^A-Za-z0-9 _!,.-]~u', '_', $name);
        // Cope with Unicode support not being available.
        if ($result === null) {
            $result = preg_replace('~[^A-Za-z0-9 _!,.-]~', '_', $name);
        }
        return $result;
    }
}

/**
 * Portfolio class for exporting a single page.
 */
class eln_page_portfolio_caller extends eln_portfolio_caller_base {
    protected $pageid;

    // Pageversion: data object with fields from eln_pages and _versions.
    private $pageversion;

    public static function expected_callbackargs() {
        return array(
                'pageid' => true,
                'withannotations' => false
        );
    }

    public function load_data() {
        global $DB;

        // Load basic data.
        $page = $DB->get_record('eln_pages', array('id' => $this->pageid), '*', MUST_EXIST);
        $this->load_base_data($page->subwikiid);

        // Load page version.
        $this->pageversion = eln_get_current_page($this->subwiki, $page->title);

        // Add files from page.
        $this->add_files(array($this->pageversion));
    }

    public function get_return_url() {
        $params['id'] = $this->cm->id;
        if (!empty($this->pageversion->title)) {
            $params['page'] = $this->pageversion->title;
        }
        $url = new moodle_url('/mod/eln/view.php', $params);
        return $url->out(false);
    }

    public function get_navigation() {
        global $CFG;

        $title = format_string($this->pageversion->title);
        $name = $title === '' ? get_string('startpage', 'eln') : $title;

        $navlinks[] = array(
                'name' => $name,
                'link' => $CFG->wwwroot . '/mod/eln/view.php?id=' . $this->cm->id . '&page=' .
                $this->pageversion->title,
                'type' => 'title'
        );
        return array($navlinks, $this->cm);
    }

    /**
     * a page with or without attachment
     *
     * @global object
     * @global object
     * @uses PORTFOLIO_FORMAT_RICH
     * @return mixed
     */
    public function prepare_package() {
        global $CFG;

        $pagehtml = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" ' .
                '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">' .
                html_writer::start_tag('html', array('xmlns' => 'http://www.w3.org/1999/xhtml'));
        $pagehtml .= html_writer::tag('head',
                html_writer::empty_tag('meta',
                        array('http-equiv' => 'Content-Type', 'content' => 'text/html; charset=utf-8')) .
                html_writer::tag('title', get_string('export', 'forumngfeature_export')));
        $pagehtml .= html_writer::start_tag('body') . "\n";

        $pagehtml .= $this->prepare_page($this->pageversion);

        $pagehtml .= html_writer::end_tag('body') . html_writer::end_tag('html');

        $content = $pagehtml;
        $name = $this->make_filename_safe($this->pageversion->title === '' ?
                get_string('startpage', 'eln') : $this->pageversion->title) . '.html';
        $manifest = ($this->exporter->get('format') instanceof PORTFOLIO_FORMAT_RICH);

        $this->copy_files($this->multifiles);
        $this->get('exporter')->write_new_file($content, $name, $manifest);
    }

    /**
     * @param object $pageversion Pageversion object
     * @return string Page html
     */
    private function prepare_page($pageversion) {
        return eln_portfolio_caller_base::get_page_html($pageversion, $this->attachments,
                $this->modcontext, $this->eln,
                $this->subwiki, $this->get('course'), $this->withannotations,
                $this->get('exporter')->get('format'),
                $this->get('exporter')->get('instance')->get('plugin'));
    }

    public function get_sha1() {
        $filesha = '';
        if (!empty($this->multifiles)) {
            $filesha = $this->get_sha1_file();
        }

        return sha1($filesha . ',' . $this->pageversion->title . ',' . $this->pageversion->xhtml);
    }
}

/**
 * Portfolio class for exporting the entire subwiki contents (all pages).
 */
class eln_all_portfolio_caller extends eln_portfolio_caller_base {
    protected $subwikiid, $tree;

    // Pageversions: array of data objects with fields from eln_pages and _versions.
    private $pageversions;

    public static function expected_callbackargs() {
        return array(
                'subwikiid' => true,
                'withannotations' => false,
                'tree' => false
        );
    }

    public function load_data() {
        global $DB, $COURSE;

        // Load base data.
        $this->load_base_data($this->subwikiid);

        // Load all page-versions.
        $this->pageversions = eln_get_subwiki_allpages_index($this->subwiki);
        eln_build_tree($this->pageversions);

        // Get all files used in subwiki.
        $this->add_files($this->pageversions);
    }

    public function get_return_url() {
        $url = new moodle_url('/mod/eln/wikiindex.php', array('id' => $this->cm->id));
        return $url->out(false);
    }

    private function prepare_tree_inline_styles() {
        $pagehtml = '';
        $pagehtml .= html_writer::start_tag('style', array('type' => 'text/css')) . "\n";
        $pagehtml .= 'ul.ouw_index,
        ul.ouw_index li,
        ul.ouw_indextree,
        ul.ouw_indextree li {
        list-style-type:none;
        margin:0;
        padding:0;
        }

        ul.ouw_indextree ul {
            margin-left:2.5em;
        } ';

        $pagehtml .= html_writer::end_tag('style') . "\n";
        return $pagehtml;
    }

    public function prepare_package() {
        global $CFG;

        $pagehtml = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" ' .
                '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">' .
                html_writer::start_tag('html', array('xmlns' => 'http://www.w3.org/1999/xhtml'));
        $content =  html_writer::empty_tag('meta',
                        array('http-equiv' => 'Content-Type', 'content' => 'text/html; charset=utf-8')).
                        html_writer::tag('title', get_string('export', 'forumngfeature_export'));
        if ($this->tree) {
            $content .= $this->prepare_tree_inline_styles();
        }
        $pagehtml .= html_writer::tag('head', $content);
        $pagehtml .= html_writer::start_tag('body') . "\n";
        $pagehtml .= html_writer::tag('h1', s($this->eln->name));

        if ($this->tree) {
            $orphans = false;
            $pagehtml .=  '</ul>';
            foreach ($this->pageversions as $pageversion) {
                if (count($pageversion->linksfrom) == 0 && $pageversion->title !== '') {
                    $orphans = true;
                } else {
                    $pageversion->xhtml = $this->prepare_page($pageversion);
                }
            }
            $pagehtml .= '<ul class="ouw_indextree">';
            $func = 'eln_display_portfolio_page_in_index';
            $pagehtml .= eln_tree_index(
                    $func,
                    reset($this->pageversions)->pageid,
                    $this->pageversions,
                    $this->subwiki,
                    $this->cm);
            $pagehtml .=  '</ul>';
            if ($orphans) {
                $pagehtml .=  '<h2 class="ouw_orphans">'.get_string('orphanpages', 'eln').'</h2>';
                $pagehtml .=  '<ul class="ouw_indextree">';
                foreach ($this->pageversions as $pageversion) {
                    if (count($pageversion->linksfrom) == 0 && $pageversion->title !== '') {
                        $pageversion->xhtml = $this->prepare_page($pageversion);
                        $orphanindex = eln_get_sub_tree_from_index($pageversion->pageid, $this->pageversions);
                        eln_build_tree($orphanindex);
                        $pagehtml .= eln_tree_index($func, $pageversion->pageid, $orphanindex, $this->subwiki, $this->cm);
                    }
                }
                $pagehtml .=  '</ul>';
            }
        } else {
            $orphans = false;
            foreach ($this->pageversions as $pageversion) {
                if (count($pageversion->linksfrom) == 0 && $pageversion->title !== '') {
                    $orphans = true;
                } else {
                    $pagehtml .= $this->prepare_page($pageversion);
                }
            }

            if ($orphans) {
                $pagehtml .= '<h2 class="ouw_orphans">'.get_string('orphanpages', 'eln').'</h2>';
                foreach ($this->pageversions as $pageversion) {
                    if (count($pageversion->linksfrom) == 0 && $pageversion->title !== '') {
                        $pagehtml .= $this->prepare_page($pageversion);
                    }
                }
            }
        }

        $pagehtml .= html_writer::end_tag('body') . html_writer::end_tag('html');
        $content = $pagehtml;
        $name = $this->make_filename_safe($this->eln->name) . '.html';
        $manifest = ($this->exporter->get('format') instanceof PORTFOLIO_FORMAT_RICH);

        $this->copy_files($this->multifiles);
        $this->get('exporter')->write_new_file($content, $name, $manifest);
    }

    /**
     * @param object $pageversion Pageversion object
     * @return string Page html
     */
    private function prepare_page($pageversion) {
        return eln_portfolio_caller_base::get_page_html($pageversion,
                $this->attachments, $this->modcontext, $this->eln,
                $this->subwiki, $this->get('course'), $this->withannotations,
                $this->get('exporter')->get('format'),
                $this->get('exporter')->get('instance')->get('plugin'));
    }

    public function get_sha1() {
        $filesha = '';
        if (!empty($this->multifiles)) {
            $filesha = $this->get_sha1_file();
        }
        $bigstring = $filesha;
        foreach ($this->pageversions as $pageversion) {
            $bigstring .= ',' . $pageversion->title . ',' . $pageversion->xhtml;
        }
        return sha1($bigstring);
    }
}
