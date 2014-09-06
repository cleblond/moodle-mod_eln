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
 * Save template feature. Saves entire subwiki contents as an XML template.
 *
 * @copyright &copy; 2007 The Open University
 * @author s.marshall@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 */

require_once(dirname(__FILE__) . '/../../config.php');
require($CFG->dirroot.'/mod/eln/basicpage.php');

$id = required_param('id', PARAM_INT); // Course Module ID
$pagename = optional_param('page', '', PARAM_TEXT);
$filesexist = optional_param('filesexist', 0, PARAM_INT);

$url = new moodle_url('/mod/eln/view.php', array('id' => $id, 'page' => $pagename));
$PAGE->set_url($url);

if ($id) {
    if (!$cm = get_coursemodule_from_id('eln', $id)) {
        print_error('invalidcoursemodule');
    }

    // Checking course instance
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

    if (!$eln = $DB->get_record('eln', array('id' => $cm->instance))) {
        print_error('invalidcoursemodule');
    }

    $PAGE->set_cm($cm);
}
$context = context_module::instance($cm->id);
$PAGE->set_pagelayout('incourse');
require_course_login($course, true, $cm);

$elnoutput = $PAGE->get_renderer('mod_eln');

$format = required_param('format', PARAM_ALPHA);
if ($format !== OUWIKI_FORMAT_HTML && $format !== OUWIKI_FORMAT_RTF && $format !== OUWIKI_FORMAT_TEMPLATE) {
    print_error('Unexpected format');
}

// Get basic wiki details for filename
$filename = $course->shortname.'.'.$eln->name;
$filename = preg_replace('/[^A-Za-z0-9.-]/' , '_', $filename);

$markup = '';
$fs = null;

switch ($format) {
    case OUWIKI_FORMAT_TEMPLATE:
        $markup = '<wiki>';
        $files = array();
        $fs = get_file_storage();
        break;
    case OUWIKI_FORMAT_RTF:
        require_once($CFG->dirroot.'/local/rtf.php');
        $markup = '<root><p>'.get_string('savedat', 'eln', userdate(time())).'</p><hr />';
        break;
    case OUWIKI_FORMAT_HTML:
        // Do header
        echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki, get_string('entirewiki', 'eln'), $context, null, false, true);
        print '<div class="eln_content">';
        break;
}

// Get list of all pages.
$first = true;
$index = eln_get_subwiki_index($subwiki->id);
$brokenimagestr = get_string('brokenimage', 'eln');

$orphans = false;
$treemode = optional_param('type', '', PARAM_ALPHA) == 'tree';

// Check for orphan posts.
foreach ($index as $indexitem) {
    if (count($indexitem->linksfrom) == 0 && $indexitem->title !== '') {
        $orphans = true;
        break;
    }
}

// If tree view specified.
if (($treemode) && ($format == OUWIKI_FORMAT_HTML) ) {
    eln_build_tree($index);
    // Print out in hierarchical form...
    print '<ul class="ouw_indextree">';
    $functionname = 'eln_display_entirewiki_page_in_index';
    print eln_tree_index($functionname, reset($index)->pageid, $index, $subwiki, $cm, $context);
    print '</ul>';

    if ($orphans) {
        print '<h2 class="ouw_orphans">'.get_string('orphanpages', 'eln').'</h2>';
        print '<ul class="ouw_indextree">';
        foreach ($index as $indexitem) {
            if (count($indexitem->linksfrom) == 0 && $indexitem->title !== '') {
                $orphanindex = eln_get_sub_tree_from_index($indexitem->pageid, $index);
                eln_build_tree($orphanindex);
                print eln_tree_index($functionname, $indexitem->pageid, $orphanindex, $subwiki, $cm, $context);
            }
        }
        print '</ul>';
    }
} else {
    foreach ($index as $pageinfo) {
        if (count($pageinfo->linksfrom)!= 0 || $pageinfo->title === '') {
            // Get page details.
            $pageversion = eln_get_current_page($subwiki, $pageinfo->title);
            // If the page hasn't really been created yet, skip it.
            if (is_null($pageversion->xhtml)) {
                continue;
            }

            $markup .= get_online_display_content($format, $pageversion, $context, $subwiki, $cm, $index, $fs, $files);

            if ($first) {
                $first = false;
            }
        }
    }

    if ($orphans) {
        if ($format == OUWIKI_FORMAT_HTML) {
            print '<h2 class="ouw_orphans">'.get_string('orphanpages', 'eln').'</h2>';
        } else if ($format != OUWIKI_FORMAT_TEMPLATE) {
            $markup .= '<h2 class="ouw_orphans">'.get_string('orphanpages', 'eln').'</h2>';
        }

        foreach ($index as $indexitem) {
            if (count($indexitem->linksfrom) == 0 && $indexitem->title !== '') {
                // Get page details.
                $pageversion = eln_get_current_page($subwiki, $indexitem->title);
                // If the page hasn't really been created yet, skip it.
                if (is_null($pageversion->xhtml)) {
                    continue;
                }

                $markup .= get_online_display_content($format, $pageversion, $context, $subwiki, $cm, $index, $fs, $files);

                if ($first) {
                    $first = false;
                }

            }
        }
    }
}

switch ($format) {
    case OUWIKI_FORMAT_TEMPLATE:
        $markup .= '</wiki>';
        // Create temp xml file.
        $filerec = new stdClass();
        $filerec->contextid = $context->id;
        $filerec->component = 'mod_eln';
        $filerec->filearea = 'temp';
        $filerec->filepath = '/';
        $filerec->itemid = $id;
        $filerec->filename = strtolower(get_string('template', 'mod_eln')) . '.xml';
        $files[$filerec->filename] = $fs->create_file_from_string($filerec, $markup);
        $zip = get_file_packer();
        $file = $zip->archive_to_storage($files, $context->id, 'mod_eln', 'temp', $id, '/', $filename . '.zip');
        ///CRL Disable next line to stop template export!
        send_stored_file($file, 0, 0, true, array('dontdie' => true));
        // Delete all our temp files used in this process.
        $fs->delete_area_files($context->id, 'mod_eln', 'temp', $id);
        exit;
        break;

    case OUWIKI_FORMAT_RTF:
        $markup .= '</root>';
        rtf_from_html($filename.'.rtf', $markup);
        break;

    case OUWIKI_FORMAT_HTML:
        eln_print_footer($course, $cm, $subwiki);
        break;
}

function get_online_display_content($format, $pageversion, $context, $subwiki, $cm, $index, $fs, &$files) {
    $markup = '';
    $visibletitle = $pageversion->title === '' ? get_string('startpage', 'eln') : $pageversion->title;

    if ($format != OUWIKI_FORMAT_TEMPLATE) {
        $pageversion->xhtml = file_rewrite_pluginfile_urls($pageversion->xhtml, 'pluginfile.php',
                $context->id, 'mod_eln', 'content', $pageversion->versionid);
    }


    switch ($format) {
        case OUWIKI_FORMAT_TEMPLATE:
            // Print template wiki page.
            $markup .= '<page>';
            if ($pageversion->title !== '') {
                $markup .= '<title>' . htmlspecialchars($pageversion->title) . '</title>';
            }
            $markup .= '<versionid>' . $pageversion->versionid . '</versionid>';

            // Copy images found in content.
            //CRL changed from original ouwiki
            //print_r($pageversion->xhtml);

            preg_match_all('#<img.*?src="@@PLUGINFILE@@/(.*?)".*?>#', $pageversion->xhtml, $matches);
            //echo "here";
            //print_r($matches);
            if (! empty($matches)) {
                // Extract the file names from the matches.
                foreach ($matches[1] as $key => $match) {
                    //echo $match;
                    // Get file name and copy to zip.
                    $match = urldecode($match);
                    // Copy image - on fail swap tag with string.
                    if ($file = $fs->get_file($context->id, 'mod_eln', 'content',
                            $pageversion->versionid, '/', $match)) {
                        $files["/$pageversion->versionid/$match/"] = $file;
                        //print_r($files);
                    } else {
                        
                        $pageversion->xhtml = str_replace($matches[0][$key], $brokenimagestr,
                                $pageversion->xhtml);
                    }
                }
            }



           // Copy files that are linked to on this moodle found in content.
            //CRL changed from original ouwiki
            preg_match_all('#<a.*?href="@@PLUGINFILE@@/(.*?)"(.*?)>.*?</a>#', $pageversion->xhtml, $matches);
            //echo "here";
            //print_r($matches);
            if (! empty($matches)) {
                // Extract the file names from the matches.
                foreach ($matches[1] as $key => $match) {
                    //echo $match;
                    // Get file name and copy to zip.
                    $match = urldecode($match);
                    // Copy image - on fail swap tag with string.
                    if ($file = $fs->get_file($context->id, 'mod_eln', 'content',
                            $pageversion->versionid, '/', $match)) {
                        $files["/$pageversion->versionid/$match/"] = $file;
                        //print_r($files);
                    } else {
                        
                        $pageversion->xhtml = str_replace($matches[0][$key], $brokenimagestr,
                                $pageversion->xhtml);
                    }
                }
            }







            $markup .= '<xhtml>' . htmlspecialchars($pageversion->xhtml) . '</xhtml>';
            // Add attachments.
            if ($attachments = $fs->get_area_files($context->id, 'mod_eln', 'attachment',
                    $pageversion->versionid, 'itemid', false)) {
                // We have attachements.
                $markup .= '<attachments>';
                $attachmentsarray = array();
                foreach ($attachments as $attachment) {
                    $filename = $attachment->get_filename();
                    array_push($attachmentsarray, $filename);
                    $files["/$pageversion->versionid/$filename/"] = $attachment;
                }
                $markup .= implode('|', $attachmentsarray);
                $markup .= '</attachments>';
            }
            $markup .= '</page>';
            break;
        case OUWIKI_FORMAT_RTF:
            $markup .= '<h1>' . htmlspecialchars($visibletitle) . '</h1>';
            $markup .= trim($pageversion->xhtml);
            $markup .= '<br /><br /><hr />';
            break;
        case OUWIKI_FORMAT_HTML:
            print '<div class="ouw_entry"><a name="' . $pageversion->pageid . '"></a><h1 class="ouw_entry_heading">' .
                    '<a href="view.php?' . eln_display_wiki_parameters($pageversion->title, $subwiki, $cm) .
                    '">' . htmlspecialchars($visibletitle) . '</a></h1>';
            print eln_convert_content($pageversion->xhtml, $subwiki, $cm, $index, $pageversion->xhtmlformat);
            print '</div>';
            break;
    }

    return $markup;

}
