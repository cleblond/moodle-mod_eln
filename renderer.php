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
 * Moodle renderer used to display special elements of the eln module
 *
 * @package    mod
 * @subpackage eln
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/mod/eln/locallib.php');

class mod_eln_renderer extends plugin_renderer_base {

    /**
     * Print the main page content
     *
     * @param object $subwiki For details of user/group and ID so that
     *   we can make links
     * @param object $cm Course-module object (again for making links)
     * @param object $pageversion Data from page and version tables.
     * @param bool $hideannotations If true, adds extra class to hide annotations
     * @return string HTML content for page
     */
    public function eln_print_page($subwiki, $cm, $pageversion, $gewgaws = null,
            $page = 'history', $showwordcount = 0, $hideannotations = false) {
        $output = '';
        $modcontext = context_module::instance($cm->id);

        global $CFG, $elninternalre;

        require_once($CFG->libdir . '/filelib.php');

        // Get annotations - only if using annotation system. prevents unnecessary db access
        if ($subwiki->annotation) {
            $annotations = eln_get_annotations($pageversion);
        } else {
            $annotations = '';
        }

        // Title
        $title = $pageversion->title === '' ? get_string('startpage', 'eln') :
                htmlspecialchars($pageversion->title);

        // setup annotations according to the page we are on
        if ($page == 'view') {
            // create the annotations
            if ($subwiki->annotation && count($annotations)) {
                $pageversion->xhtml = eln_highlight_existing_annotations($pageversion->xhtml, $annotations, 'view');
            }
        } else if ($page == 'annotate') {
            // call function for the annotate page
            $pageversion->xhtml = eln_setup_annotation_markers($pageversion->xhtml);
            $pageversion->xhtml = eln_highlight_existing_annotations($pageversion->xhtml, $annotations, 'annotate');
        }

        // Must rewrite plugin urls AFTER doing annotations because they depend on byte position.
        $pageversion->xhtml = file_rewrite_pluginfile_urls($pageversion->xhtml, 'pluginfile.php',
                $modcontext->id, 'mod_eln', 'content', $pageversion->versionid);
        $pageversion->xhtml = eln_convert_content($pageversion->xhtml, $subwiki, $cm, null,
                $pageversion->xhtmlformat);

        // get files up here so we have them for the portfolio button addition as well
        $fs = get_file_storage();
        $files = $fs->get_area_files($modcontext->id, 'mod_eln', 'attachment',
                $pageversion->versionid, "timemodified", false);

        $output .= html_writer::start_tag('div', array('class' => 'eln-content' .
                ($hideannotations ? ' eln-hide-annotations' : '')));
        $output .= html_writer::start_tag('div', array('class' => 'ouw_topheading'));
        $output .= html_writer::start_tag('div', array('class' => 'ouw_heading'));
        $output .= html_writer::tag('h2', format_string($title),
                array('class' => 'ouw_topheading'));

        if ($gewgaws) {
            $output .= $this->render_heading_bit(1, $pageversion->title, $subwiki,
                    $cm, null, $annotations, $pageversion->locked, $files,
                    $pageversion->pageid);
        } else {
            $output .= html_writer::end_tag('div');
        }

        // List of recent changes
        if ($gewgaws && $pageversion->recentversions) {
            /*$output .= html_writer::start_tag('div', array('class' => 'ouw_recentchanges'));
            $output .= get_string('recentchanges', 'eln').': ';
            $output .= html_writer::start_tag('span', array('class' => 'ouw_recentchanges_list'));

            $first = true;
            foreach ($pageversion->recentversions as $recentversion) {
                if ($first) {
                    $first = false;
                } else {
                    $output .= '; ';
                }

                $output .= eln_recent_span($recentversion->timecreated);
                $output .= eln_nice_date($recentversion->timecreated);
                $output .= html_writer::end_tag('span');
                $output .= ' (';
                $recentversion->id = $recentversion->userid; // so it looks like a user object
                $output .= eln_display_user($recentversion, $cm->course, false);
                $output .= ')';
            }

            $output .= '; ';
            $pagestr = '';
            if (strtolower(trim($title)) !== strtolower(get_string('startpage', 'eln'))) {
                $pagestr = '&page='.$title;
            }
            $output .= html_writer::tag('a', get_string('seedetails', 'eln'),
                    array('href' => $CFG->wwwroot.'/mod/eln/history.php?id='.
                    $cm->id . $pagestr));
            $output .= html_writer::end_tag('span');
            $output .= html_writer::end_tag('div');  */
        }

        $output .= html_writer::end_tag('div');
        $output .= html_writer::start_tag('div', array('class' => 'ouw_belowmainhead'));

        // spacer
        $output .= html_writer::start_tag('div', array('class' => 'ouw_topspacer'));
        $output .= html_writer::end_tag('div');

        // Content of page
        $output .= $pageversion->xhtml;

        if ($gewgaws) {
            // Add in links/etc. around headings.
            $elninternalre = new stdClass();
            $elninternalre->pagename = $pageversion->title;
            $elninternalre->subwiki = $subwiki;
            $elninternalre->cm = $cm;
            $elninternalre->annotations = $annotations;
            $elninternalre->locked = $pageversion->locked;
            $elninternalre->pageversion = $pageversion;
            $elninternalre->files = $files;
            $output = preg_replace_callback(
                    '|<h([1-9]) id="ouw_s([0-9]+_[0-9]+)">(.*?)(<br\s*/>)?</h[1-9]>|s',
                    'eln_internal_re_heading', $output);
        }
        $output .= html_writer::start_tag('div', array('class'=>'clearer'));
        $output .= html_writer::end_tag('div');

        $output .= html_writer::end_tag('div');

        // Render wordcount
        if ($showwordcount) {
            $output .= $this->eln_render_wordcount($pageversion->wordcount);
        }

        $output .= html_writer::end_tag('div');

        // attached files
        if ($files) {
            $output .= html_writer::start_tag('div', array('class' => 'eln-post-attachments'));
            $output .= html_writer::tag('h3', get_string('attachments', 'eln'),
                    array('class' => 'ouw_topheading'));
            $output .= html_writer::start_tag('ul');
            foreach ($files as $file) {
                $output .= html_writer::start_tag('li');
                $filename = $file->get_filename();
                $mimetype = $file->get_mimetype();
                $iconimage = html_writer::empty_tag('img',
                        array('src' => $this->output->pix_url(file_mimetype_icon($mimetype)),
                        'alt' => $mimetype, 'class' => 'icon'));
                $path = file_encode_url($CFG->wwwroot . '/pluginfile.php', '/' . $modcontext->id .
                        '/mod_eln/attachment/' . $pageversion->versionid . '/' . $filename);
                $output .= html_writer::tag('a', $iconimage, array('href' => $path));
                $output .= html_writer::tag('a', s($filename), array('href' => $path));
                $output .= html_writer::end_tag('li');
            }
            $output .= html_writer::end_tag('ul');
            $output .= html_writer::end_tag('div');
        }

        // pages that link to this page
        if ($gewgaws) {
            $links = eln_get_links_to($pageversion->pageid);
            if (count($links) > 0) {
                $output .= html_writer::start_tag('div', array('class'=>'ouw_linkedfrom'));
                $output .= html_writer::tag('h3', get_string(
                        count($links) == 1 ? 'linkedfromsingle' : 'linkedfrom', 'eln'),
                        array('class'=>'ouw_topheading'));
                $output .= html_writer::start_tag('ul');
                $first = true;
                foreach ($links as $link) {
                    $output .= html_writer::start_tag('li');
                    if ($first) {
                        $first = false;
                    } else {
                        $output .= '&#8226; ';
                    }
                    $linktitle = ($link->title) ? htmlspecialchars($link->title) :
                            get_string('startpage', 'eln');
                    $output .= html_writer::tag('a', $linktitle,
                            array('href' => $CFG->wwwroot . '/mod/eln/view.php?' .
                            eln_display_wiki_parameters(
                                $link->title, $subwiki, $cm, OUWIKI_PARAMS_URL)));
                    $output .= html_writer::end_tag('li');
                }
                $output .= html_writer::end_tag('ul');
                $output .= html_writer::end_tag('div');
            }
        }

        // disply the orphaned annotations
        if ($subwiki->annotation && $annotations && $page != 'history') {
            $orphaned = '';
            foreach ($annotations as $annotation) {
                if ($annotation->orphaned) {

                    $orphaned .= $this->eln_print_hidden_annotation($annotation);
                }
            }
            if ($orphaned !== '') {
                $output .= html_writer::tag('h3', get_string('orphanedannotations', 'eln'));
                $output .= $orphaned;
            } else {
                $output = $output;
            }
        }

        return array($output, $annotations);
    }

    public function render_heading_bit($headingnumber, $pagename, $subwiki, $cm,
            $xhtmlid, $annotations, $locked, $files, $pageid) {
        global $CFG;

        $output = '';
        $output .= html_writer::start_tag('div', array('class' => 'ouw_byheading'));

        // Add edit link for page or section
        if ($subwiki->canedit && !$locked) {
            $str = $xhtmlid ? 'editsection' : 'editpage';

            $output .= html_writer::tag('a', get_string($str, 'eln'), array(
                    'href' => $CFG->wwwroot . '/mod/eln/edit.php?' .
                        eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_URL) .
                        ($xhtmlid ? '&section=' . $xhtmlid : ''),
                    'class' => 'ouw_' . $str));
        }

        // output the annotate link if using annotation system, only for page not section
        if (!$xhtmlid && $subwiki->annotation) {
            // Add annotate link
            if ($subwiki->canannotate) {
                $output .= ' ' .html_writer::tag('a', get_string('annotate', 'eln'),
                        array('href' => $CFG->wwwroot.'/mod/eln/annotate.php?' .
                        eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_URL),
                        'class' => 'ouw_annotate'));
            }

            // 'Expand/collapse all' and 'Show/hide all' annotation controls
            if ($annotations != false) {
                $orphancount = 0;
                foreach ($annotations as $annotation) {
                    if ($annotation->orphaned == 1) {
                        $orphancount++;
                    }
                }
                if (count($annotations) > $orphancount) {
                    // Show and hide annotation icon links. Visibility controlled by CSS.
                    $output .= html_writer::start_tag('span', array('id' => 'showhideannotationicons'));
                    $output .= ' '.html_writer::tag('a', get_string('showannotationicons', 'eln'),
                            array('href' => 'hideannotations.php?hide=0&' . eln_display_wiki_parameters(
                            $pagename, $subwiki, $cm, OUWIKI_PARAMS_URL) . '&sesskey=' . sesskey(),
                            'id' => 'showannotationicons'));
                    $output .= html_writer::tag('a', get_string('hideannotationicons', 'eln'),
                            array('href' => 'hideannotations.php?hide=1&' . eln_display_wiki_parameters(
                            $pagename, $subwiki, $cm, OUWIKI_PARAMS_URL) . '&sesskey=' . sesskey(),
                            'id' => 'hideannotationicons'));
                    $output .= html_writer::end_tag('span');

                    // Expand and collapse annotations links.
                    $output .= html_writer::start_tag('span', array('id' => 'expandcollapseannotations'));
                    $output .= ' '.html_writer::tag('a', get_string('expandallannotations', 'eln'),
                        array(
                            'href' => 'javascript:M.mod_eln_view.elnShowAllAnnotations("block")',
                            'id' => 'expandallannotations'
                        ));
                    $output .= html_writer::tag('a', get_string('collapseallannotations', 'eln'),
                        array(
                            'href' => 'javascript:M.mod_eln_view.elnShowAllAnnotations("none")',
                            'id' => 'collapseallannotations'
                        ));
                    $output .= html_writer::end_tag('span');
                }
            }
        }

        // On main page, add export button
        if (!$xhtmlid && $CFG->enableportfolios) {
            require_once($CFG->libdir . '/portfoliolib.php');
            $button = new portfolio_add_button();
            $button->set_callback_options('eln_page_portfolio_caller',
                    array('pageid' => $pageid), 'mod_eln');
            if (empty($files)) {
                $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
            } else {
                $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
            }
            $output .= ' ' . $button->to_html(PORTFOLIO_ADD_TEXT_LINK).' ';
        }

        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div');

        return $output;
    }

    /**
     * Renders the 'export entire wiki' link.
     * @param object $subwiki Subwiki data object
     * @param bool $anyfiles True if any page of subwiki contains files
     * @param array $wikiparamsarray associative array
     * @return string HTML content of list item with link, or nothing if none
     */
    public function render_export_all_li($subwiki, $anyfiles, $wikiparamsarray) {
        global $CFG;

        if (!$CFG->enableportfolios) {
            return '';
        }

        require_once($CFG->libdir . '/portfoliolib.php');
        $button = new portfolio_add_button();
        $button->set_callback_options('eln_all_portfolio_caller',
               $wikiparamsarray, 'mod_eln');
        if ($anyfiles) {
            $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
        } else {
            $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
        }
        return html_writer::tag('li', $button->to_html(PORTFOLIO_ADD_TEXT_LINK));
    }

    public function eln_internal_re_heading_bits($matches) {
        global $elninternalre;

        $tag = "h$matches[1]";
        $output = '';
        $output .= html_writer::start_tag('div', array('class' => 'ouw_heading ouw_heading'.
                $matches[1]));
        $output .= html_writer::tag($tag, $matches[3], array('id' => 'ouw_s'.$matches[2]));

        $output .= $this->render_heading_bit($matches[1],
                $elninternalre->pagename, $elninternalre->subwiki,
                $elninternalre->cm, $matches[2], $elninternalre->annotations,
                $elninternalre->locked, $elninternalre->files,
                $elninternalre->pageversion->pageid);

        return $output;
    }

    public function eln_print_preview($content, $page, $subwiki, $cm, $contentformat) {
        global $CFG;

        // Convert content.
        $content = eln_convert_content($content, $subwiki, $cm, null, $contentformat);
        // Need to replace brokenfile.php with draftfile.php since switching off filters
        // will switch off all filter.
        $content = str_replace("\"$CFG->httpswwwroot/brokenfile.php#",
                "\"$CFG->httpswwwroot/draftfile.php", $content);
        // Create output to be returned for printing.
        $output = html_writer::tag('p', get_string('previewwarning', 'eln'),
                array('class' => 'ouw_warning'));
        $output .= html_writer::start_tag('div', array('class' => 'ouw_preview'));
        $output .= $this->output->box_start("generalbox boxaligncenter");
        // Title & content of page.
        $title = $page !== null && $page !== '' ? htmlspecialchars($page) :
                get_string('startpage', 'eln');
        $output .= html_writer::tag('h1', $title);
        $output .= $content;
        $output .= html_writer::end_tag('div');
        $output .= $this->output->box_end();
        return $output;
    }

    /**
     * Format the diff content for rendering
     *
     * @param v1 object version one of the page
     * @param v2 object version two of the page
     * @return output
     */
    public function eln_print_diff($v1, $v2) {

        $output = '';

        // left: v1
        $output .= html_writer::start_tag('div', array('class' => 'ouw_left'));
        $output .= html_writer::start_tag('div', array('class' => 'ouw_versionbox'));
        $output .= html_writer::tag('h1', $v1->version, array('class' => 'accesshide'));
        $output .= html_writer::tag('div', $v1->date, array('class' => 'ouw_date'));
        $output .= html_writer::tag('div', $v1->savedby, array('class' => 'ouw_person'));
        $output .= html_writer::end_tag('div');
        $output .= html_writer::start_tag('div', array('class' => 'ouw_diff eln_content'));
        $output .= $v1->content;
        $output .= html_writer::tag('h3', get_string('attachments', 'eln'), array());
        $output .= html_writer::tag('div', $v1->attachments,
                array('class' => 'eln_attachments'));
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div');

        // right: v2
        $output .= html_writer::start_tag('div', array('class' => 'ouw_right'));
        $output .= html_writer::start_tag('div', array('class' => 'ouw_versionbox'));
        $output .= html_writer::tag('h1', $v2->version, array('class' => 'accesshide'));
        $output .= html_writer::tag('div', $v2->date, array('class' => 'ouw_date'));
        $output .= html_writer::tag('div', $v2->savedby, array('class' => 'ouw_person'));
        $output .= html_writer::end_tag('div');
        $output .= html_writer::start_tag('div', array('class' => 'ouw_diff eln_content'));
        $output .= $v2->content;
        $output .= html_writer::tag('h3', get_string('attachments', 'eln'), array());
        $output .= html_writer::tag('div', $v2->attachments,
                array('class' => 'eln_attachments'));
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div');

        // clearer
        $output .= html_writer::tag('div', '&nbsp;', array('class' => 'clearer'));

        return $output;
    }

    /**
     * Format the compared file for rendering as part of the diff
     *
     * @param file object
     * @param action string
     * @return output
     */
    public function eln_print_attachment_diff($file, $action = 'none') {
        global $OUTPUT, $CFG;

        $filename = $file->get_filename();
        $mimetype = $file->get_mimetype();
        $iconimage = html_writer::empty_tag('img',
                array('src' => $OUTPUT->pix_url(file_mimetype_icon($mimetype)),
                'alt' => $mimetype, 'class' => 'icon'));

        if ($action === 'add') {
            $addedstart = html_writer::empty_tag('img', array(
                'src' => $OUTPUT->pix_url('diff_added_begins', 'eln'),
                'alt' => get_string('addedbegins', 'eln'),
                'class' => 'icon')
            );
            $addedend = html_writer::empty_tag('img', array(
                'src' => $OUTPUT->pix_url('diff_added_ends', 'eln'),
                'alt' => get_string('addedends', 'eln'),
                'class' => 'icon')
            );

            $output = html_writer::start_tag('li');
            $output .= $addedstart;
            $output .= html_writer::tag('span', " $iconimage $filename ",
                    array('class' => 'ouw_added'));
            $output .= $addedend;
            $output .= html_writer::end_tag('li');

        } else if ($action === 'delete') {
            $deletedstart = html_writer::empty_tag('img' , array(
                'src' => $OUTPUT->pix_url('diff_deleted_begins', 'eln'),
                'alt' => get_string('deletedbegins', 'eln'),
                'class' => 'icon')
            );
            $deletedend = html_writer::empty_tag('img', array(
                'src' => $OUTPUT->pix_url('diff_deleted_ends', 'eln'),
                'alt' => get_string('deletedends', 'eln'),
                'class' => 'icon')
            );

            $output = html_writer::start_tag('li');
            $output .= $deletedstart;
            $output .= html_writer::tag('span', " $iconimage $filename ",
                    array('class' => 'ouw_deleted'));
            $output .= $deletedend;
            $output .= html_writer::end_tag('li');
        } else {
            // default case; no change in file
            $output = html_writer::tag('li', "$iconimage $filename");
        }

        return $output;
    }

    /**
     * Format the hidden annotations for rendering
     *
     * @param annotation object
     * @return output
     */
    public function eln_print_hidden_annotation($annotation) {
        global $DB, $COURSE, $OUTPUT;

        $author = $DB->get_record('user', array('id' => $annotation->userid), '*', MUST_EXIST);
        $picture = null;
        $size = 0;
        $return = true;
        $classname = ($annotation->orphaned) ? 'eln-orphaned-annotation' : 'eln-annotation';
        $output = html_writer::start_tag('span',
                array('class' => $classname, 'id' => 'annotationbox'.$annotation->id));
        $output .= $OUTPUT->user_picture($author, array('courseid' => $COURSE->id));
        $output .= get_accesshide(get_string('startannotation', 'eln'));
        $output .= html_writer::start_tag('span', array('class' => 'eln-annotation-content'));
        $output .= html_writer::tag('span', fullname($author),
                array('class' => 'eln-annotation-content-title'));
        $output .= $annotation->content;
        $output .= html_writer::end_tag('span');
        $output .= html_writer::tag('span', get_string('endannotation', 'eln'),
                array('class' => 'accesshide'));
        $output .= html_writer::end_tag('span');

        return $output;
    }

    /**
     * Format the annotations for portfolio export
     *
     * @param annotation object
     * @return output
     */
    public function eln_print_portfolio_annotation($annotation) {
        global $DB, $COURSE, $OUTPUT;

        $author = $DB->get_record('user', array('id' => $annotation->userid), '*', MUST_EXIST);

        $output = '[';
        $output .= html_writer::start_tag('i');
        $output .= html_writer::tag('span', $annotation->content, array('style' => 'colour: red'));
        $output .= ' - '. fullname($author) . ', ' . userdate($annotation->timemodified);
        $output .= html_writer::end_tag('i');
        $output .= '] ';

        return $output;
    }

    /**
     * Prints the header and (if applicable) group selector.
     *
     * @param object $eln Wiki object
     * @param object $cm Course-modules object
     * @param object $course
     * @param object $subwiki Subwiki objecty
     * @param string $pagename Name of page
     * @param object $context Context object
     * @param string $afterpage If included, extra content for navigation string after page link
     * @param bool $hideindex If true, doesn't show the index/recent pages links
     * @param bool $notabs If true, prints the after-tabs div here
     * @param string $head Things to include inside html head
     * @param string $title
     * @param string $querytext for use when changing groups against search criteria
     */
    public function eln_print_start($eln, $cm, $course, $subwiki, $pagename, $context,
            $afterpage = null, $hideindex = null, $notabs = null, $head = '', $title='', $querytext = '') {
        global $USER, $OUTPUT;
        $output = '';

        if ($pagename == null) {
            $pagename = '';
        }

        eln_print_header($eln, $cm, $subwiki, $pagename, $afterpage, $head, $title);

        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
        $page = basename($_SERVER['PHP_SELF']);

        // Print group/user selector
        $showselector = true;
        if (($page == 'userparticipation.php' && $canview != OUWIKI_MY_PARTICIPATION)
            || $page == 'participation.php'
                && (int)$eln->subwikis == OUWIKI_SUBWIKIS_INDIVIDUAL) {
            $showselector = false;
        }
        if ($showselector) {
            $selector = eln_display_subwiki_selector($subwiki, $eln, $cm,
                $context, $course, $page, $querytext);
            $output .= $selector;
        }

        // Print index link
        if (!$hideindex) {
            $output .= html_writer::start_tag('div', array('id' => 'eln_indexlinks'));
            $output .= html_writer::start_tag('ul');

                $output .= html_writer::start_tag('li', array('id' => 'eln_nav_index'));
                $output .= html_writer::tag('a', get_string('myindex', 'eln'),
                        array('href' => 'view.php?id='.$cm->id));
                $output .= html_writer::end_tag('li');

            if ($page == 'wikiindex.php') {

                /*$output .= html_writer::start_tag('li', array('id' => 'eln_nav_index'));
                $output .= html_writer::tag('a', get_string('myindex', 'eln'),
                        array('href' => 'view.php?id='.$cm->id));
                $output .= html_writer::end_tag('li'); */

                $output .= html_writer::start_tag('li', array('id' => 'eln_nav_index'));
                $output .= html_writer::start_tag('span');
                $output .= get_string('index', 'eln');
                $output .= html_writer::end_tag('span');
                $output .= html_writer::end_tag('li');
            } else {

               /* $output .= html_writer::start_tag('li', array('id' => 'eln_nav_index'));
                $output .= html_writer::tag('a', get_string('index', 'eln'),
                        array('href' => 'view.php?id='.$cm->id));
                $output .= html_writer::end_tag('li'); */

                $output .= html_writer::start_tag('li', array('id' => 'eln_nav_index'));
                $output .= html_writer::tag('a', get_string('index', 'eln'),
                        array('href' => 'wikiindex.php?'.
                        eln_display_wiki_parameters('', $subwiki, $cm, OUWIKI_PARAMS_URL)));
                $output .= html_writer::end_tag('li');
            }
            if ($page == 'wikihistory.php') {
                $output .= html_writer::start_tag('li', array('id' => 'eln_nav_history'));
                $output .= html_writer::start_tag('span');
                $output .= get_string('wikirecentchanges', 'eln');
                $output .= html_writer::end_tag('span');
                $output .= html_writer::end_tag('li');
            } else {
                $output .= html_writer::start_tag('li', array('id' => 'eln_nav_history'));
                $output .= html_writer::tag('a', get_string('wikirecentchanges', 'eln'),
                        array('href' => 'wikihistory.php?'.
                        eln_display_wiki_parameters('', $subwiki, $cm, OUWIKI_PARAMS_URL)));
                $output .= html_writer::end_tag('li');
            }
            // Check for mod setting and ability to edit that enables this link.
            if (($subwiki->canedit) && ($eln->allowimport)) {
                $output .= html_writer::start_tag('li', array('id' => 'eln_import_pages'));
                if ($page == 'import.php') {
                    $output .= html_writer::tag('span', get_string('import', 'eln'));
                } else {
                    $importlink = new moodle_url('/mod/eln/import.php',
                            eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_ARRAY));
                    $output .= html_writer::link($importlink, get_string('import', 'eln'));
                }
                $output .= html_writer::end_tag('li');
            }
            if ($canview == OUWIKI_USER_PARTICIPATION) {
                $participationstr = get_string('participationbyuser', 'eln');
                $participationpage = 'participation.php?' .
                    eln_display_wiki_parameters('', $subwiki, $cm, OUWIKI_PARAMS_URL);
            } else if ($canview == OUWIKI_MY_PARTICIPATION) {
                $participationstr = get_string('myparticipation', 'eln');
                $participationpage = 'userparticipation.php?' .
                        eln_display_wiki_parameters('', $subwiki, $cm, OUWIKI_PARAMS_URL);
                $participationpage .= '&user='.$USER->id;
            }

            if ($canview > OUWIKI_NO_PARTICIPATION) {
                if (($cm->groupmode != 0) && isset($subwiki->groupid)) {
                    $participationpage .= '&group='.$subwiki->groupid;
                }
                if ($page == 'participation.php' || $page == 'userparticipation.php') {
                    $output .= html_writer::start_tag('li',
                        array('id' => 'eln_nav_participation'));
                    $output .= html_writer::start_tag('span');
                    $output .= $participationstr;
                    $output .= html_writer::end_tag('span');
                    $output .= html_writer::end_tag('li');
                } else {
                    $output .= html_writer::start_tag('li',
                        array('id' => 'eln_nav_participation'));
                    $output .= html_writer::tag('a', $participationstr,
                            array('href' => $participationpage));
                    $output .= html_writer::end_tag('li');
                }
            }

            $output .= html_writer::end_tag('ul');

            $output .= html_writer::end_tag('div');
        } else {
            $output .= html_writer::start_tag('div', array('id' => 'eln_noindexlink'));
            $output .= html_writer::end_tag('div');
        }
        if ($page == 'participation.php' || $page == 'userparticipation.php') {
            $output .= $OUTPUT->heading($participationstr);
        }

        $output .= html_writer::start_tag('div', array('class' => 'clearer'));
        $output .= html_writer::end_tag('div');
        if ($notabs) {
            $extraclass = $selector ? ' eln_gotselector' : '';
            $output .= html_writer::start_tag('div',
                    array('id' => 'eln_belowtabs', 'class' => 'eln_notabs'.$extraclass));
            $output .= html_writer::end_tag('div');
        }

        return $output;
    }

    /**
     * Format the wordcount for display
     *
     * @param string $wordcount
     * @return output
     */
    public function eln_render_wordcount($wordcount) {
        $output = html_writer::start_tag('div', array('class' => 'ouw_wordcount'));
        $output .= html_writer::tag('span', get_string('numwords', 'eln', $wordcount));
        $output .= html_writer::end_tag('div');
        return $output;
    }

    /**
     * Print all user participation records for display
     *
     * @param object $cm
     * @param object $course
     * @param string $pagename
     * @param int $groupid
     * @param object $eln
     * @param object $subwiki
     * @param string $download (csv)
     * @param int $page flexible_table pagination page
     * @param bool $grading_info gradebook grade information
     * @param array $participation mixed array of user participation values
     * @param object $context
     * @param bool $viewfullnames
     * @param string groupname
     */
    public function eln_render_participation_list($cm, $course, $pagename, $groupid, $eln,
        $subwiki, $download, $page, $grading_info, $participation, $context, $viewfullnames,
        $groupname) {
        global $DB, $CFG, $OUTPUT;

        require_once($CFG->dirroot.'/mod/eln/participation_table.php');
        $perpage = OUWIKI_PARTICIPATION_PERPAGE;

        // filename for downloading setup
        $filename = "$course->shortname-".format_string($eln->name, true);
        if (!empty($groupname)) {
            $filename .= '-'.format_string($groupname, true);
        }

        $table = new eln_participation_table($cm, $course, $eln,
            $pagename, $groupid, $groupname, $grading_info);
        $table->setup($download);
        $table->is_downloading($download, $filename, get_string('participation', 'eln'));

        // participation doesn't need standard eln tabs so we need to
        // add this one div in manually
        if (!$table->is_downloading()) {
            echo html_writer::start_tag('div', array('id' => 'eln_belowtabs'));
        }

        if (!empty($participation)) {
            if (!$table->is_downloading()) {
                if ($perpage > count($participation)) {
                    $perpage = count($participation);
                }
                $table->pagesize($perpage, count($participation));
                $offset = $page * $perpage;
                $endposition = $offset + $perpage;
            } else {
                // always export all users
                $endposition = count($participation);
                $offset = 0;
            }
            $currentposition = 0;
            foreach ($participation as $user) {
                if ($currentposition == $offset && $offset < $endposition) {
                    $fullname = fullname($user, $viewfullnames);

                    // control details link
                    $details = false;

                    // pages
                    $pagecreates = 0;
                    if (isset($user->pagecreates)) {
                        $pagecreates = $user->pagecreates;
                        $details = true;
                    }
                    $pageedits = 0;
                    if (isset($user->pageedits)) {
                        $pageedits = $user->pageedits;
                        $details = true;
                    }

                    // words
                    $wordsadded = 0;
                    $wordsdeleted = 0;
                    if ($eln->enablewordcount) {
                        if (isset($user->wordsadded)) {
                            $wordsadded = $user->wordsadded;
                            $details = true;
                        }
                        if (isset($user->wordsdeleted)) {
                            $wordsdeleted = $user->wordsdeleted;
                            $details = true;
                        }
                    }

                    // Allow import.
                    $imports = 0;
                    if ($eln->allowimport) {
                        if (isset($user->pageimports)) {
                            $imports = count($user->pageimports);
                            $details = true;
                        }
                    }

                    // grades
                    if ($grading_info) {
                        if (!$table->is_downloading()) {
                            $attributes = array('userid' => $user->id);
                            if (!isset($grading_info->items[0]->grades[$user->id]->grade)) {
                                $user->grade = -1;
                            } else {
                                $user->grade = $grading_info->items[0]->grades[$user->id]->grade;
                                $user->grade = abs($user->grade);
                            }
                            $menu = html_writer::select(make_grades_menu($eln->grade),
                                'menu['.$user->id.']', $user->grade,
                                array(-1 => get_string('nograde')), $attributes);
                            $gradeitem = '<div id="gradeuser'.$user->id.'">'. $menu .'</div>';
                        } else {
                            if (!isset($grading_info->items[0]->grades[$user->id]->grade)) {
                                $gradeitem = get_string('nograde');
                            } else {
                                $gradeitem = $grading_info->items[0]->grades[$user->id]->grade;
                            }
                        }
                    }

                    // user details
                    if (!$table->is_downloading()) {
                        $picture = $OUTPUT->user_picture($user);
                        $userurl = new moodle_url('/user/view.php?',
                            array('id' => $user->id, 'course' => $course->id));
                        $userdetails = html_writer::link($userurl, $fullname);
                        if ($details) {
                            $detailparams = array('id' => $cm->id, 'pagename' => $pagename,
                                'user' => $user->id, 'group' => $groupid);
                            $detailurl = new moodle_url('/mod/eln/userparticipation.php',
                                $detailparams);
                            $accesshidetext = get_string('userdetails', 'eln', $fullname);
                            $detaillink = html_writer::start_tag('small');
                            $detaillink .= ' (';
                            $detaillink .= html_writer::tag('span', $accesshidetext,
                                    array('class' => 'accesshide'));
                            $detaillink .= html_writer::link($detailurl,
                                get_string('detail', 'eln'));
                            $detaillink .= ')';
                            $detaillink .= html_writer::end_tag('small');
                            $userdetails .= $detaillink;
                        }
                    }

                    // add row
                    if (!$table->is_downloading()) {
                        if ($eln->enablewordcount) {
                            $row = array($picture, $userdetails, $pagecreates,
                                $pageedits, $wordsadded, $wordsdeleted);
                        } else {
                            $row = array($picture, $userdetails, $pagecreates, $pageedits);
                        }
                    } else {
                        $row = array($fullname, $pagecreates, $pageedits,
                            $wordsadded, $wordsdeleted);
                    }
                    if ($eln->allowimport) {
                        $row[] = $imports;
                        // $row[] = 666;
                    }
                    if (isset($gradeitem)) {
                        $row[] = $gradeitem;
                    }
                    $table->add_data($row);
                    $offset++;
                }
                $currentposition++;
            }
        }

        $table->finish_output();
        // print the grade form footer if necessary
        if (!$table->is_downloading() && $grading_info && !empty($participation)) {
            echo $table->grade_form_footer();
        }
    }

    /**
     * Render single user participation record for display
     *
     * @param object $user
     * @param array $changes user participation
     * @param object $cm
     * @param object $course
     * @param object $eln
     * @param object $subwiki
     * @param string $pagename
     * @param int $groupid
     * @param string $download
     * @param bool $canview level of participation user can view
     * @param object $context
     * @param string $fullname
     * @param bool $cangrade permissions to grade user participation
     * @param string $groupname
     */
    public function eln_render_user_participation($user, $changes, $cm, $course,
        $eln, $subwiki, $pagename, $groupid, $download, $canview, $context, $fullname,
        $cangrade, $groupname) {
        global $DB, $CFG, $OUTPUT;

        require_once($CFG->dirroot.'/mod/eln/participation_table.php');

        $filename = "$course->shortname-".format_string($eln->name, true);
        if (!empty($groupname)) {
            $filename .= '-'.format_string($groupname, true);
        }
        $filename .= '-'.format_string($fullname, true);

        // setup the table
        $table = new eln_user_participation_table($cm, $course, $eln,
            $pagename, $groupname, $user, $fullname);
        $table->setup($download);
        $table->is_downloading($download, $filename, get_string('participation', 'eln'));
        // participation doesn't need standard eln tabs so we need to
        // add this one div in manually
        if (!$table->is_downloading()) {
            echo html_writer::start_tag('div', array('id' => 'eln_belowtabs'));
            if (count($changes) < $table->pagesize) {
                $table->pagesize(count($changes), count($changes));
            }
        }

        $previouswordcount = false;
        $lastdate = null;
        foreach ($changes as $change) {
            $date = userdate($change->timecreated, get_string('strftimedate'));
            $time = userdate($change->timecreated, get_string('strftimetime'));
            if (!$table->is_downloading()) {
                if ($date == $lastdate) {
                    $date = null;
                } else {
                    $lastdate = $date;
                }
                $now = time();
                $edittime = $time;
                if ($now - $edittime < 5*60) {
                    $category = 'ouw_recenter';
                } else if ($now - $edittime < 4*60*60) {
                    $category = 'ouw_recent';
                } else {
                    $category = 'ouw_recentnot';
                }
                $time = html_writer::start_tag('span', array('class' => $category));
                $time .= $edittime;
                $time .= html_writer::end_tag('span');
            }
            $page = $change->title ? htmlspecialchars($change->title) :
                get_string('startpage', 'eln');
            $row = array($date, $time, $page);

            // word counts
            if ($eln->enablewordcount) {
                $previouswordcount = false;
                if ($change->previouswordcount) {
                    $words = eln_wordcount_difference($change->wordcount,
                        $change->previouswordcount, true);
                } else {
                    $words = eln_wordcount_difference($change->wordcount, 0, false);
                }
                if (!$table->is_downloading()) {
                    $row[] = $words;
                } else {
                    if ($words <= 0) {
                        $row[] = 0;
                        $row[] = $words;
                    } else {
                        $row[] = $words;
                        $row[] = 0;
                    }
                }
            }

            // Allow imports.
            if ($eln->allowimport) {
                $imported = '';
                if ($change->importversionid) {
                    $wikidetails = eln_get_wiki_details($change->importversionid);
                    $wikiname = $wikidetails->name;
                    if ($wikidetails->courseshortname) {
                        $coursename = $wikidetails->courseshortname. '<br/>';
                        $imported = $coursename . $wikiname;
                    } else {
                        $imported = $wikiname;
                    }
                    if ($wikidetails->group) {
                        $users = '<br/> [[' .$wikidetails->group. ']]';
                        $imported = $imported . $users;
                    } else if ($wikidetails->user) {
                        $users = '<br/>[[' .$wikidetails->user. ']]';
                        $imported = $imported . $users;
                    }
                }
                $row[] = $imported;
            }

            if (!$table->is_downloading()) {
                $pageparams = eln_display_wiki_parameters($change->title, $subwiki, $cm);
                $pagestr = $page . ' ' . $lastdate . ' ' . $edittime;
                if ($change->id != $change->firstversionid) {
                    $accesshidetext = get_string('viewwikichanges', 'eln', $pagestr);
                    $changeurl = new moodle_url("/mod/eln/diff.php?$pageparams" .
                        "&v2=$change->id&v1=$change->previousversionid");
                    $changelink = html_writer::start_tag('small');
                    $changelink .= ' (';
                    $changelink .= html_writer::link($changeurl, get_string('changes', 'eln'));
                    $changelink .= ')';
                    $changelink .= html_writer::end_tag('small');
                } else {
                    $accesshidetext = get_string('viewwikistartpage', 'eln', $pagestr);
                    $changelink = html_writer::start_tag('small');
                    $changelink .= ' (' . get_string('newpage', 'eln') . ')';
                    $changelink .= html_writer::end_tag('small');
                }
                $current = '';
                if ($change->id == $change->currentversionid) {
                    $viewurl = new moodle_url("/mod/eln/view.php?$pageparams");
                } else {
                    $viewurl = new moodle_url("/mod/eln/viewold.php?" .
                        "$pageparams&version=$change->id");
                }
                $actions = html_writer::tag('span', $accesshidetext, array('class' => 'accesshide'));
                $actions .= html_writer::link($viewurl, get_string('view'));
                $actions .= $changelink;
                $row[] = $actions;
            }

            // add to the table
            $table->add_data($row);
        }

        $table->finish_output();
        if (!$table->is_downloading() && $cangrade && $eln->grade != 0) {
            $this->eln_render_user_grade($course, $cm, $eln, $user, $pagename, $groupid);
        }
    }

    /**
     * Render single users grading form
     *
     * @param object $course
     * @param object $cm
     * @param object $eln
     * @param object $user
     */
    public function eln_render_user_grade($course, $cm, $eln, $user, $pagename, $groupid) {
        global $CFG;

        require_once($CFG->libdir.'/gradelib.php');
        $grading_info = grade_get_grades($course->id, 'mod', 'eln', $eln->id, $user->id);

        if ($grading_info) {
            if (!isset($grading_info->items[0]->grades[$user->id]->grade)) {
                $user->grade = -1;
            } else {
                $user->grade = abs($grading_info->items[0]->grades[$user->id]->grade);
            }
            $grademenu = make_grades_menu($eln->grade);
            $grademenu[-1] = get_string('nograde');

            $formparams = array();
            $formparams['id'] = $cm->id;
            $formparams['user'] = $user->id;
            $formparams['page'] = $pagename;
            $formparams['group'] = $groupid;
            $formaction = new moodle_url('/mod/eln/savegrades.php', $formparams);
            $mform = new MoodleQuickForm('savegrade', 'post', $formaction,
                '', array('class' => 'savegrade'));

            $mform->addElement('header', 'usergrade', get_string('usergrade', 'eln'));

            $mform->addElement('select', 'grade', get_string('grade'),  $grademenu);
            $mform->setDefault('grade', $user->grade);

            $mform->addElement('submit', 'savechanges', get_string('savechanges'));

            $mform->display();
        }
    }
}
