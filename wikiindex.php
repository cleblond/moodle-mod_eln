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
 * 'Wiki index' page. Displays an index of all pages in the wiki, in
 * various formats.
 *
 * @copyright &copy; 2007 The Open University
 * @author s.marshall@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 */

require_once(dirname(__FILE__) . '/../../config.php');
require($CFG->dirroot.'/mod/eln/basicpage.php');

//$treemode = optional_param('type', '', PARAM_ALPHA) == 'tree';
//$searchmode = optional_param('search', '', PARAM_ALPHA) == 'search';
$searchterm = optional_param('searchterm', '', PARAM_ALPHA);
$mode = optional_param('type', '', PARAM_ALPHA);

//echo "mode=".$mode;


$id = required_param('id', PARAM_INT); // Course Module ID
//echo "searchmode=".$searchmode;
//echo "searchterm=".$searchterm;

$url = new moodle_url('/mod/eln/wikiindex.php', array('id'=>$id));
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

// Get basic wiki parameters
$wikiparams = eln_display_wiki_parameters('', $subwiki, $cm);

// Do header
echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki, get_string('index', 'eln'), $context, null, false);

// Print tabs for selecting index type
$tabrow = array();
$tabrow[] = new tabobject('alpha', 'wikiindex.php?'.$wikiparams,
    get_string('tab_index_alpha', 'eln'));
$tabrow[] = new tabobject('tree', 'wikiindex.php?'.$wikiparams.'&amp;type=tree',
    get_string('tab_index_tree', 'eln'));
$tabrow[] = new tabobject('search', 'wikiindex.php?'.$wikiparams.'&amp;type=search',
    get_string('tab_index_search', 'eln'));
$tabs = array();
$tabs[] = $tabrow;

/*
if ($mode == "tree) {
$mode='tree';
} else if ($searchmode) {
$mode='search';
} else {
$mode = 'alpha';
}
*/

if($mode === "")$mode='alpha';

print_tabs($tabs, $mode);
print '<div id="eln_belowtabs">';

global $orphans;
// Get actual index
$index = eln_get_subwiki_index($subwiki->id);

//print_object($subwiki);
///CRL added
//print_object($index);

$orphans = false;
$func = 'eln_display_wikiindex_page_in_index';
if (count($index) == 0) {
    print '<p>'.get_string('startpagedoesnotexist', 'eln').'</p>';
} else if ($mode == 'tree') {
    eln_build_tree($index);
    // Print out in hierarchical form...
    print '<ul class="ouw_indextree">';
    print eln_tree_index($func, reset($index)->pageid, $index, $subwiki, $cm);
    print '</ul>';
    foreach ($index as $indexitem) {
        if (count($indexitem->linksfrom) == 0 && $indexitem->title !== '') {
            $orphans = true;
            break;
        }
    }
} else if ($mode == 'search') {
    // ...search mode
    //    eln_build_tree($index);
    print eln_display_search_page_form($subwiki, $cm);
    //$searchterm = "maximum";
    print '<ul class="ouw_index">';
    if ($searchterm) print '<h3>Search Results ...</h3>';
    foreach ($index as $indexitem) {
       // print $indexitem->title."<br>";
        $sourcepage = eln_get_current_page($subwiki, $indexitem->title);
       // print $sourcepage->xhtml;
        if ($searchterm && strchr($sourcepage->xhtml, $searchterm)){
            print '<li>' . eln_display_wikiindex_page_in_index($indexitem, $subwiki, $cm) . '</li>';
        }

    }
    //print '</ul>';
    print '</ul>';
} else {
    // ...or standard alphabetical
    print '<ul class="ouw_index">';
    foreach ($index as $indexitem) {
        if (count($indexitem->linksfrom)!= 0 || $indexitem->title === '') {
            print '<li>' . eln_display_wikiindex_page_in_index($indexitem, $subwiki, $cm) . '</li>';
        } else {
            $orphans = true;
        }
    }
    print '</ul>';
}




//CRL Hide for Search
if ($mode !== 'search') {

	if ($orphans) {
	    print '<h2 class="ouw_orphans">'.get_string('orphanpages', 'eln').'</h2>';
	    print '<ul class="ouw_index">';
	    foreach ($index as $indexitem) {
		if (count($indexitem->linksfrom) == 0 && $indexitem->title !== '') {
		    if ($mode == 'tree') {
		        $orphanindex = eln_get_sub_tree_from_index($indexitem->pageid, $index);
		        eln_build_tree($orphanindex);
		        print eln_tree_index($func, $indexitem->pageid, $orphanindex, $subwiki, $cm);
		    } else {
		        print '<li>' . eln_display_wikiindex_page_in_index($indexitem, $subwiki, $cm) . '</li>';
		    }
		}
	    }
	    print '</ul>';
	}

	$missing = eln_get_subwiki_missingpages($subwiki->id);
	if (count($missing) > 0) {
	    print '<div class="ouw_missingpages"><h2>'.get_string('missingpages', 'eln').'</h2>';
	    print '<p>'.get_string(count($missing) > 1 ? 'advice_missingpages' : 'advice_missingpage', 'eln').'</p>';
	    print '<ul>';
	    $first = true;
	    foreach ($missing as $title => $from) {
		print '<li>';
		if ($first) {
		    $first = false;
		} else {
		    print ' &#8226; ';
		}
		print '<a href="view.php?'.eln_display_wiki_parameters($title, $subwiki, $cm).'">'.
		    htmlspecialchars($title).'</a> <span class="ouw_missingfrom">('.
		    get_string(count($from) > 1 ? 'frompages' : 'frompage', 'eln',
		        '<a href="view.php?'.eln_display_wiki_parameters($from[0], $subwiki, $cm).'">'.
		        ($from[0] ? htmlspecialchars($from[0]) : get_string('startpage', 'eln')).'</a>)</span>');
		print '</li>';
	    }
	    print '</ul>';
	    print '</div>';
	}




$tree = 0;
if (!empty($treemode)) {
    $wikiparams.= '&amp;type=tree';
    $tree = 1;
}

if (count($index) != 0) {
    print '<div class="ouw_entirewiki"><h2>'.get_string('entirewiki', 'eln').'</h2>';
    print '<p>'.get_string('onepageview', 'eln').'</p><ul>';
    print '<li id="eln_down_html"><a href="entirewiki.php?'.$wikiparams.'&amp;format=html">'.
        get_string('format_html', 'eln').'</a></li>';

    // Are there any files in this wiki?
    $context = context_module::instance($cm->id);
    $result = $DB->get_records_sql("
SELECT
    f.id
FROM
    {eln_subwikis} sw
    JOIN {eln_pages} p ON p.subwikiid = sw.id
    JOIN {eln_versions} v ON v.pageid = p.id
    JOIN {files} f ON f.itemid = v.id
WHERE
    sw.id = ? AND f.contextid = ? AND f.component = 'mod_eln' AND f.filename NOT LIKE '.'
    AND f.filearea = 'attachment' AND v.id IN (SELECT MAX(v.id) from {eln_versions} v WHERE v.pageid = p.id)
    ", array($subwiki->id, $context->id), 0, 1);
    $anyfiles = count($result) > 0;
    $wikiparamsarray = array('subwikiid' => $subwiki->id, 'tree' => $tree);
    print $elnoutput->render_export_all_li($subwiki, $anyfiles, $wikiparamsarray);

    if (has_capability('moodle/course:manageactivities', $context)) {
        $str = get_string('format_template', 'eln');
        $filesexist = false;
        if ($anyfiles) {
            // Images or attachment files found.
            $filesexist = true;
        }

        print '<li id="eln_down_template"><a href="entirewiki.php?' . $wikiparams . '&amp;format=template&amp;filesexist='
            .$filesexist.'">' . $str . '</a></li>';
    }
    print '</ul></div>';
}

}  //End hide for search mode


// Footer
eln_print_footer($course, $cm, $subwiki, $pagename);
