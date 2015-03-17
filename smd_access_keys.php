<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_access_keys';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.20';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'http://stefdawson.com/';
$plugin['description'] = 'Permit access to content for a certain time period/number of access attempts';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '1';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '3';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@smd_akey
smd_akey => Access keys
smd_akey_accesses => Access attempts
smd_akey_btn_new => New key
smd_akey_btn_pref => Prefs
smd_akey_deleted => Keys deleted: {deleted}
smd_akey_err_bad_token => Missing or mangled access key
smd_akey_err_expired => Access expired
smd_akey_err_forbidden => Forbidden access
smd_akey_err_invalid_token => Invalid access key
smd_akey_err_limit => Access limit reached
smd_akey_err_missing_timestamp => Missing timestamp
smd_akey_err_unauthorized => Unauthorized access
smd_akey_err_unavailable => Not available
smd_akey_file_download_expires => File download expiry time (seconds)
smd_akey_generated => Access key: {key}
smd_akey_log_ip => Log IP addresses
smd_akey_max => Maximum
smd_akey_need_page => You need to enter a page URL
smd_akey_page => Page
smd_akey_prefs_saved => Preferences saved
smd_akey_prefs_some_explain => This is either a new installation or a different version<br />of the plugin to one you had before.
smd_akey_prefs_some_opts => Click "Install table" to add or update the table<br />leaving all existing data untouched.
smd_akey_prefs_some_tbl => Not all table info available.
smd_akey_pref_legend => Access key preferences
smd_akey_salt_length => Salt length (characters)
smd_akey_tab_name => Access keys
smd_akey_tbl_installed => Table installed
smd_akey_tbl_install_lbl => Install table
smd_akey_tbl_not_installed => Table not installed
smd_akey_tbl_not_removed => Table not removed
smd_akey_tbl_removed => Table removed
smd_akey_time => Issued
smd_akey_trigger => Trigger
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_access_keys
 *
 * A Textpattern CMS plugin for secure tokenized access to resources:
 *  -> Time-based or access attempt limits
 *  -> Untamperable URL-based keys
 *  -> Optional IP logging
 *
 * @author Stef Dawson
 * @link   http://stefdawson.com/
 */
// TODO: Add shortcut to send a newly-generated admin-side key to a user? (dropdown of users + e-mail template in prefs?)
//       Add auto-deletion of expired keys pref:
//        -> File download keys can be deleted on any key access (expiry window is known via prefs)
//        -> Other key accesses can only be deleted when that key is used
//        -> Configurable grace period after expiry, before deletion
//       Obfusctaed URLs (see http://radioartnet.net/11/2011/07/05/samuel-beckett-words-and-music/)
//       Query an access key and separate it into its component parts for convenient testing
if (@txpinterface == 'admin') {
    global $smd_akey_event, $smd_akey_styles, $dbversion;

    $smd_akey_event = 'smd_akey';
    $smd_akey_styles = array(
        'list' =>
         '.smd_hidden { display:none; }',
    );

    if (version_compare($dbversion, '4.6-dev') >= 0) {
        add_privs('prefs.smd_akey', '1,2,3');
    }

    add_privs($smd_akey_event, '1');
    add_privs('plugin_prefs.smd_access_keys', '1');
    register_tab('extensions', $smd_akey_event, gTxt('smd_akey_tab_name'));
    register_callback('smd_akey_dispatcher', $smd_akey_event);
    register_callback('smd_akey_welcome', 'plugin_lifecycle.smd_access_keys');
    register_callback('smd_akey_prefs', 'plugin_prefs.smd_access_keys');
}

global $smd_akey_prefs;
$smd_akey_prefs = array(
    'smd_akey_file_download_expires' => array(
        'html'     => 'text_input',
        'type'     => PREF_HIDDEN,
        'position' => 10,
        'default'  => '3600',
    ),
    'smd_akey_salt_length' => array(
        'html'     => 'text_input',
        'type'     => PREF_HIDDEN,
        'position' => 20,
        'default'  => '8',
    ),
    'smd_akey_log_ip' => array(
        'html'     => 'yesnoradio',
        'type'     => PREF_HIDDEN,
        'position' => 30,
        'default'  => '0',
    ),
);

if (!defined('SMD_AKEYS')) {
    define("SMD_AKEYS", 'smd_akeys');
}

register_callback('smd_access_protect_download', 'file_download');

// ********************
// ADMIN SIDE INTERFACE
// ********************
// Jump off point for event/steps
function smd_akey_dispatcher($evt, $stp)
{
    if (!$stp or !in_array($stp, array(
            'smd_akey_table_install',
            'smd_akey_table_remove',
            'smd_akey_create',
            'smd_akey_prefs',
            'smd_akey_prefsave',
            'smd_akey_multi_edit',
            'smd_akey_change_pageby',
        ))) {
        smd_akey('');
    } else $stp();
}

// Bootstrap when installed/deleted
function smd_akey_welcome($evt, $stp)
{
    $msg = '';
    switch ($stp) {
        case 'installed':
            smd_akey_table_install(0);
            $msg = 'Restrict your Txp world :-)';
            break;
        case 'deleted':
            smd_akey_table_remove(0);
            break;
    }

    return $msg;
}

// Main admin interface
function smd_akey($msg = '')
{
    global $smd_akey_event, $smd_akey_list_pageby, $smd_akey_styles, $logging, $smd_akey_prefs;

    pagetop(gTxt('smd_akey_tab_name'), $msg);

    if (smd_akey_table_exist(1)) {
        extract(gpsa(array('page', 'sort', 'dir', 'crit', 'search_method')));
        if ($sort === '') $sort = get_pref('smd_akey_sort_column', 'time');
        if ($dir === '') $dir = get_pref('smd_akey_sort_dir', 'desc');
        $dir = ($dir == 'asc') ? 'asc' : 'desc';

        switch ($sort) {
            case 'page':
                $sort_sql = 'page '.$dir.', time desc';
            break;

            case 'triggah':
                $sort_sql = 'triggah '.$dir.', time desc';
            break;

            case 'maximum':
                $sort_sql = 'maximum '.$dir.', time desc';
            break;

            case 'accesses':
                $sort_sql = 'accesses '.$dir.', time desc';
            break;

            case 'ip':
                $sort_sql = 'ip '.$dir.', time desc';
            break;

            default:
                $sort = 'time';
                $sort_sql = 'time '.$dir;
            break;
        }

        set_pref('smd_akey_sort_column', $sort, 'smd_akey', PREF_HIDDEN, '', 0, PREF_PRIVATE);
        set_pref('smd_akey_sort_dir', $dir, 'smd_akey', PREF_HIDDEN, '', 0, PREF_PRIVATE);

        $switch_dir = ($dir == 'desc') ? 'asc' : 'desc';

        $criteria = 1;

        if ($search_method and $crit) {
            $crit_escaped = doSlash(str_replace(array('\\','%','_','\''), array('\\\\','\\%','\\_', '\\\''), $crit));
            $critsql = array(
                'page'     => "page like '%$crit_escaped%'",
                'triggah'  => "triggah like '%$crit_escaped%'",
                'maximum'  => "maximum = '$crit_escaped'",
                'accesses' => "accesses = '$crit_escaped'",
                'ip'       => "ip like '%$crit_escaped%'",
            );

            if (array_key_exists($search_method, $critsql)) {
                $criteria = $critsql[$search_method];
                $limit = 500;
            } else {
                $search_method = '';
                $crit = '';
            }
        } else {
            $search_method = '';
            $crit = '';
        }

        $total = safe_count(SMD_AKEYS, "$criteria");

        echo '<div id="'.$smd_akey_event.'_control" class="txp-control-panel">';

        if ($total < 1) {
            if ($criteria != 1) {
                echo n.smd_akey_search_form($crit, $search_method).
                    n.graf(gTxt('no_results_found'), ' class="indicator"').'</div>';
                    return;
            }
        }

        $limit = max($smd_akey_list_pageby, 15);

        list($page, $offset, $numPages) = pager($total, $limit, $page);

        echo n.smd_akey_search_form($crit, $search_method).'</div>';

        // Retrieve the secret keyring table entries
        $secring = safe_rows('*', SMD_AKEYS, "$criteria order by $sort_sql limit $offset, $limit");

        // Set up the buttons and column info
        $newbtn = '<a class="navlink" href="#" onclick="return smd_akey_togglenew();">'.gTxt('smd_akey_btn_new').'</a>';
        $prefbtn = '<a class="navlink" href="?event='.$smd_akey_event.a.'step=smd_akey_prefs">'.gTxt('smd_akey_btn_pref').'</a>';
        $showip = get_pref('smd_akey_log_ip', $smd_akey_prefs['smd_akey_log_ip']['default'], 1);

        echo <<<EOC
<script type="text/javascript">
function smd_akey_togglenew()
{
    box = jQuery("#smd_akey_create");
    if (box.css("display") == "none") {
        box.show();
    } else {
        box.hide();
    }
    jQuery("input.smd_focus").focus();
    return false;
}
jQuery(function() {
    jQuery("#smd_akey_add").click(function () {
        jQuery("#smd_akey_step").val('smd_akey_create');
        jQuery("#smd_akey_form").removeAttr('onsubmit').submit();
    });
});
</script>
EOC;
        // Inject styles
        echo '<style type="text/css">' . $smd_akey_styles['list'] . '</style>';

        // Access key list
        echo n.'<div id="'.$smd_akey_event.'_container" class="txp-container txp-list">';
        echo '<form name="longform" id="smd_akey_form" action="index.php" method="post" onsubmit="return verify(\''.gTxt('are_you_sure').'\')">';
        echo startTable('list');
        echo n.'<thead>'
            .n.tr(tda($newbtn . sp . $prefbtn, ' class="noline"'))
            .n.tr(
                n.column_head(gTxt('smd_akey_page'), 'page', $smd_akey_event, true, $switch_dir, $crit, $search_method, (('page' == $sort) ? "$dir " : '').'page').
                n.column_head(gTxt('smd_akey_trigger'), 'triggah', $smd_akey_event, true, $switch_dir, $crit, $search_method, (('triggah' == $sort) ? "$dir " : '')).
                n.column_head(gTxt('smd_akey_time'), 'time', $smd_akey_event, true, $switch_dir, $crit, $search_method, (('time' == $sort) ? "$dir " : '').'date time').
                n.hCell(gTxt('expires'), 'expires', ' class="date time"').
                n.column_head(gTxt('smd_akey_max'), 'maximum', $smd_akey_event, true, $switch_dir, $crit, $search_method, (('maximum' == $sort) ? "$dir " : '')).
                n.column_head(gTxt('smd_akey_accesses'), 'accesses', $smd_akey_event, true, $switch_dir, $crit, $search_method, (('accesses' == $sort) ? "$dir " : '')).
                (($showip) ? n.column_head('IP', 'ip', $smd_akey_event, true, $switch_dir, $crit, $search_method, (('ip' == $sort) ? "$dir " : '').'ip') : '').
                n.hCell('', '', ' class="multi-edit"')
            ).
            n.'</thead>';

        $multiOpts = array('smd_akey_delete' => gTxt('delete'));

        echo '<tfoot>' . tr(tda(
                select_buttons()
                .n.selectInput('smd_akey_multi_edit', $multiOpts, '', true)
                .n.eInput($smd_akey_event)
                .n.fInput('submit', '', gTxt('go'), 'smallerbox')
            ,' class="multi-edit" colspan="' . (($showip) ? 7 : 6) . '" style="text-align: right; border: none;"'));
        echo '</tfoot>';
        echo '<tbody>';

        // New access key row
        echo '<tr id="smd_akey_create" class="smd_hidden">';
        echo td(fInput('hidden', 'step', 'smd_akey_multi_edit', '', '', '', '', '', 'smd_akey_step').fInput('text', 'smd_akey_newpage', '', 'smd_focus', '', '', '60'))
            .td(fInput('text', 'smd_akey_triggah', ''))
            .td(fInput('text', 'smd_akey_time', safe_strftime('%Y-%m-%d %H:%M:%S'), '', '', '', '25'))
            .td(fInput('text', 'smd_akey_expires', '', '', '', '', '25'))
            .td(fInput('text', 'smd_akey_maximum', '', '', '', '', '5'))
            .td('&nbsp;')
            . (($showip) ? td('&nbsp;') : '')
            .td(fInput('submit', 'smd_akey_add', gTxt('add'), 'smallerbox', '', '', '', '', 'smd_akey_add'));
        echo '</tr>';

        // Remaining access keys
        foreach ($secring as $secidx => $data) {
            if ($showip) {
                $ips = do_list($data['ip'], ' ');
                $iplist = array();
                foreach ($ips as $ip) {
                    $iplist[] = ($logging == 'none') ? $ip : eLink('log', 'log_list', 'search_method', 'ip', $ip, 'crit', $ip);
                }
            }

            $dkey = $data['page'].'|'.$data['t_hex'];
            $timeparts = do_list($data['t_hex'], '-');
            $expiry = (isset($timeparts[1])) ? hexdec($timeparts[1]) : '';

            echo tr(
                td('<a href="'.$data['page'].'">'.$data['page'].'</a>', '', 'page')
                . td($data['triggah'])
                . td(safe_strftime('%Y-%m-%d %H:%M:%S', $data['time']), 85, 'date time')
                . td( (($expiry) ? safe_strftime('%Y-%m-%d %H:%M:%S', $expiry) : '-'), 85, 'date time')
                . td($data['maximum'])
                . td($data['accesses'])
                . ( ($showip) ? td( trim(join(' ', $iplist)), 20, 'ip' ) : '' )
                . td( fInput('checkbox', 'selected[]', $dkey, 'checkbox'), '', 'multi-edit')
            );
        }
        echo '</tbody>';
        echo endTable();
        echo '</form>';

        echo '<div id="'.$smd_akey_event.'_navigation" class="txp-navigation">'.
            n.nav_form($smd_akey_event, $page, $numPages, $sort, $dir, $crit, $search_method, $total, $limit).

            n.pageby_form($smd_akey_event, $smd_akey_list_pageby).
            n.'</div>'.n.'</div>';

    } else {
        // Table not installed
        $btnInstall = '<form method="post" action="?event='.$smd_akey_event.a.'step=smd_akey_table_install" style="display:inline">'.fInput('submit', 'submit', gTxt('smd_akey_tbl_install_lbl'), 'smallerbox').'</form>';
        $btnStyle = ' style="border:0;height:25px"';
        echo startTable('list');
        echo tr(tda(strong(gTxt('smd_akey_prefs_some_tbl')).br.br
                .gTxt('smd_akey_prefs_some_explain').br.br
                .gTxt('smd_akey_prefs_some_opts'), ' colspan="2"')
        );
        echo tr(tda($btnInstall, $btnStyle));
        echo endTable();
    }
}

// Change and store qty-per-page value
function smd_akey_change_pageby()
{
    event_change_pageby('smd_akey');
    smd_akey();
}

// The search dropdown list
function smd_akey_search_form($crit, $method)
{
    global $smd_akey_event, $smd_akey_prefs;

    $doip = get_pref('smd_akey_log_ip', $smd_akey_prefs['smd_akey_log_ip']['default'], 1);

    $methods =	array(
        'page'     => gTxt('smd_akey_page'),
        'triggah'  => gTxt('smd_akey_trigger'),
        'maximum'  => gTxt('smd_akey_max'),
        'accesses' => gTxt('smd_akey_accesses'),
    );

    if ($doip) {
        $methods['ip'] = gTxt('IP');
    }

    return search_form($smd_akey_event, '', $crit, $methods, $method, 'page');
}

// Create a key from the admin side's 'New key' button
function smd_akey_create()
{
    extract(gpsa(array('smd_akey_newpage', 'smd_akey_triggah', 'smd_akey_time', 'smd_akey_expires', 'smd_akey_maximum')));

    if ($smd_akey_newpage) {
        // Just call the public tag with the relevant options
        $key = smd_access_key(
            array(
                'url'     => $smd_akey_newpage,
                'trigger' => $smd_akey_triggah,
                'start'   => $smd_akey_time,
                'expires' => $smd_akey_expires,
                'max'     => $smd_akey_maximum,
            )
        );
        $msg = gTxt('smd_akey_generated', array('{key}' => $key));
    } else {
        $msg = array(gTxt('smd_akey_need_page'), E_ERROR);
    }

    smd_akey($msg);
}

// Handle submission of the multi-edit dropdown options
function smd_akey_multi_edit()
{
    $selected = gps('selected');
    $operation = gps('smd_akey_multi_edit');
    $del = 0;
    $msg = '';

    switch ($operation) {
        case 'smd_akey_delete':
            if ($selected) {
                foreach ($selected as $sel) {
                    $parts = explode('|', $sel);
                    $ret = safe_delete(SMD_AKEYS, "page = '" . $parts[0] . "' AND t_hex = '" . $parts[1] . "'");
                    $del = ($ret) ? $del+1 : $del;
                }
                $msg = gTxt('smd_akey_deleted', array('{deleted}' => $del));
            }
        break;
    }

    smd_akey($msg);
}

// Display the prefs
function smd_akey_prefs()
{
    global $smd_akey_event, $smd_akey_prefs;

    pagetop(gTxt('smd_akey_pref_legend'));

    $out = array();
    $out[] = '<form name="smd_akey_prefs" id="smd_akey_prefs" action="index.php" method="post">';
    $out[] = eInput($smd_akey_event).sInput('smd_akey_prefsave');
    $out[] = startTable('list');
    $out[] = tr(tdcs(strong(gTxt('smd_akey_pref_legend')), 2));
    foreach ($smd_akey_prefs as $idx => $prefobj) {
        $subout = array();
        $subout[] = tda('<label for="'.$idx.'">'.gTxt($idx).'</label>', ' class="noline" style="text-align: right; vertical-align: middle;"');
        $val = get_pref($idx, $prefobj['default']);
        switch ($prefobj['html']) {
            case 'text_input':
                $subout[] = fInputCell($idx, $val, '', '', '', $idx);
            break;
            case 'yesnoradio':
                $subout[] = tda(yesnoRadio($idx, $val),' class="noline"');
            break;
        }
        $out[] = tr(join(n, $subout));
    }

    $out[] = tr(tda('&nbsp;', ' class="noline"') . tda(fInput('submit', '', gTxt('save'), 'publish'), ' class="noline"'));
    $out[] = endTable();
    $out[] = '</form>';

    echo join(n, $out);
}

// Save the prefs
function smd_akey_prefsave()
{
    global $smd_akey_event, $smd_akey_prefs;

    foreach ($smd_akey_prefs as $idx => $prefobj) {
        $val = ps($idx);
        set_pref($idx, $val, $smd_akey_event, $prefobj['type'], $prefobj['html'], $prefobj['position']);
    }

    $msg = gTxt('smd_akey_prefs_saved');

    smd_akey($msg);
}

// Add akey table if not already installed
function smd_akey_table_install($showpane = '1')
{
    $GLOBALS['txp_err_count'] = 0;
    $ret = '';
    $sql = array();

    // Use 'triggah' and 'maximum' because 'trigger' and 'max' are reserved words.
    $sql[] = "CREATE TABLE IF NOT EXISTS `" . PFX . SMD_AKEYS . "` (
        `page` varchar(255) default '',
        `t_hex` varchar(17) default '',
        `time` int(14) default 0,
        `secret` varchar(511) default '',
        `triggah` varchar(255) default '',
        `maximum` int(11) default 0,
        `accesses` int(11) default 0,
        `ip` text,
        PRIMARY KEY (`page`,`t_hex`)
    ) ENGINE=MyISAM";

    if (gps('debug')) {
        dmp($sql);
    }

    foreach ($sql as $qry) {
        $ret = safe_query($qry);
        if ($ret===false) {
            $GLOBALS['txp_err_count']++;
            echo "<b>".$GLOBALS['txp_err_count'].".</b> ".mysql_error()."<br />\n";
            echo "<!--\n $qry \n-->\n";
        }
    }

    // Spit out results
    if ($GLOBALS['txp_err_count'] == 0) {
        if ($showpane) {
            $msg = gTxt('smd_akey_tbl_installed');
            smd_akey($msg);
        }
    } else {
        if ($showpane) {
            $msg = gTxt('smd_akey_tbl_not_installed');
            smd_akey($msg);
        }
    }
}

// ------------------------
// Drop table if in database
function smd_akey_table_remove()
{
    $ret = '';
    $sql = array();
    $GLOBALS['txp_err_count'] = 0;

    if (smd_akey_table_exist()) {
        $sql[] = "DROP TABLE IF EXISTS " .PFX.SMD_AKEYS. "; ";
        if (gps('debug')) {
            dmp($sql);
        }

        foreach ($sql as $qry) {
            $ret = safe_query($qry);
            if ($ret===false) {
                $GLOBALS['txp_err_count']++;
                echo "<b>".$GLOBALS['txp_err_count'].".</b> ".mysql_error()."<br />\n";
                echo "<!--\n $qry \n-->\n";
            }
        }
    }

    if ($GLOBALS['txp_err_count'] == 0) {
        $msg = gTxt('smd_akey_tbl_removed');
    } else {
        $msg = gTxt('smd_akey_tbl_not_removed');
        smd_akey($msg);
    }
}

// ------------------------
function smd_akey_table_exist($type = '')
{
    global $plugins_ver, $DB;

    // Upgrade check
    $ver = get_pref('smd_akey_installed_version', '');

    if (!$ver || $plugins_ver['smd_access_keys'] != $ver) {
        // Increase the size of the t_hex field to allow for expiry times.
        $ret = @safe_field("CHARACTER_MAXIMUM_LENGTH", "INFORMATION_SCHEMA.COLUMNS", "table_name = '" . PFX . SMD_AKEYS . "' AND table_schema = '" . $DB->db . "' AND column_name = 't_hex'");

        if ($ret != '17') {
            safe_alter(SMD_AKEYS, "CHANGE `t_hex` `t_hex` VARCHAR( 17 ) DEFAULT ''");
        }

        // Increase the size of the secret field to allow for longer keys.
        $ret = @safe_field("CHARACTER_MAXIMUM_LENGTH", "INFORMATION_SCHEMA.COLUMNS", "table_name = '" . PFX . SMD_AKEYS . "' AND table_schema = '" . $DB->db . "' AND column_name = 'secret'");

        if ($ret != '511') {
            safe_alter(SMD_AKEYS, "CHANGE `secret` `secret` VARCHAR( 511 ) DEFAULT ''");
        }

        set_pref('smd_akey_installed_version', $plugins_ver['smd_access_keys'], 'smd_akey', PREF_HIDDEN, '', 0);
    }

    if ($type == '1') {
        $tbls = array(SMD_AKEYS => 8);
        $out = count($tbls);
        foreach ($tbls as $tbl => $cols) {
            if (count(@safe_show('columns', $tbl)) == $cols) {
                $out--;
            }
        }
        return ($out===0) ? 1 : 0;
    } else {
        return(@safe_show('columns', SMD_AKEYS));
    }
}

//**********************
// PUBLIC SIDE INTERFACE
//**********************
function smd_access_key($atts, $thing = null)
{
    global $smd_akey_prefs, $smd_akey_info;

    // In case this tag is called from the admin side - needs parse()
    include_once txpath.'/publish.php';

    extract(lAtts(array(
        'secret'       => '',
        'url'          => '',
        'site_name'    => '1',
        'section_mode' => '0',
        'start'        => '',
        'expires'      => '',
        'trigger'      => 'smd_akey',
        'max'          => '',
        'extra'        => '',
        'form'         => '',
        'strength'     => 'SMD_SSL_RAND', // or SMD_MD5, or your own salt
    ), $atts));

    if (smd_akey_table_exist(1)) {
        $thing = (empty($form)) ? $thing : fetch_form($form);
        $thing = (empty($thing)) ? '<txp:smd_access_info item="key" />' : $thing;

        $trigger = trim($trigger);
        $trigger = ($trigger == 'file_download') ? '' : $trigger;
        $hasSSL = function_exists('openssl_random_pseudo_bytes');

        $smd_akey_salt_length = get_pref('smd_akey_salt_length', $smd_akey_prefs['smd_akey_salt_length']['default']);

        // Without a URL, assume current page
        $page = rtrim( (($url) ? $url : serverSet('REQUEST_URI')), '/');

        if ($site_name && (strpos($page, 'http') !== 0)) {
            // Can't use raw hu since it contains the subdir (as does the REQUEST_URI)
            // so duplicate portions would occur in the generated URL
            $urlparts = parse_url(hu);
            $page = $urlparts['scheme'] . '://' . $urlparts['host'] . $page;
        }

        if (!$secret) {
            if ($hasSSL) {
                $secret = bin2hex(openssl_random_pseudo_bytes($smd_akey_salt_length * 8));
            } else {
                $secret = uniqid('', true);
            }
        }

        if ($strength === 'SMD_SSL_RAND' && $hasSSL) {
            // Need to divide by 2 to retain the same key length as v0.1x, because each byte
            // here produces two hex characters.
            $salt = bin2hex(openssl_random_pseudo_bytes(floor($smd_akey_salt_length / 2)));
        } elseif ($strength === 'SMD_MD5')  {
            $salt = substr(md5(uniqid(mt_rand(), true)), 0, $smd_akey_salt_length);
        } else {
            $salt = substr($strength, 0, $smd_akey_salt_length);
        }

        $plen = strlen($page) % 32; // Because 32 is the size of an md5 string and we don't want to fall off the end

        // Generate a timestamp. The clock starts ticking from this moment
        $ts = ($start) ? safe_strtotime($start) : time();
        $ts = ($ts === false) ? time() : $ts;
        $t_hex = dechex($ts);

        // Any expiry to add?
        if ($expires) {
            // Relative offset from the start time, or an absolute expiry?
            $rel = (strpos($expires, '+') === 0) ? true : false;
            if ($rel) {
                $exp = safe_strtotime($expires, $ts);
            } else {
                $exp = safe_strtotime($expires);
            }
            if ($exp !== false) {
                $t_hex .= '-' . dechex($exp);
            }
        }

        // Update/insert the remaining data
        $exists = safe_field('page', SMD_AKEYS, "page='".doSlash($page)."' AND t_hex='".doSlash($t_hex)."'");
        $maxinfo = '';

        if ($max) {
            $maxinfo = ", maximum = '".doSlash($max)."', accesses = '0'";
        }

        if ($exists) {
            safe_update(SMD_AKEYS, "triggah='".doSlash($trigger)."', time='".doSlash($ts)."', secret='".doSlash($secret)."'" . $maxinfo, "page='".doSlash($page)."' AND t_hex='".doSlash($t_hex)."'");
        } else {
            safe_insert(SMD_AKEYS, "page='".doSlash($page)."', t_hex='".doSlash($t_hex)."', triggah='".doSlash($trigger)."', secret='".doSlash($secret)."', time='".doSlash($ts)."'" . $maxinfo);
        }

        // Tack on max if applicable
        $max_safe = $max;
        $max = ($max) ? '.'.$max : '';

        // And any extra
        $extratok = ($extra) ? '/'.$extra : '';

        // Create the raw token...
        $token = md5($salt.$secret.$page.$trigger.$t_hex.$max.$extra);
        // ... and insert the salt partway through
        $salty_token = substr($token, 0, $plen) . $salt . substr($token, $plen);
        $tokensep = ($section_mode) ? '?' : '/';
        $key = $page . (($trigger) ? $tokensep . $trigger : '') . '/' . $salty_token . '/' . $t_hex . $max . $extratok;

        $smd_akey_info = array(
            'ak_page'      => $page,
            'ak_extra'     => $extra,
            'ak_hextime'   => $t_hex,
            'ak_issued'    => $ts,
            'ak_now'       => time(),
            'ak_expires'   => ($expires) ? $exp : '',
            'ak_trigger'   => $trigger,
            'ak_maximum'   => $max_safe,
            'ak_separator' => $tokensep,
            'ak_key'       => $key,
        );

        return parse($thing);
    } else {
        trigger_error(gTxt('smd_akey_tbl_not_installed'), E_USER_NOTICE);
    }
}

// Protect a page for a given time limit from the moment the
// access token has been generated. Embed this tag at the top
// of the page you want to protect or wrap it around part of a
// page you wish to protect. The unique URL to the resource
// is generated by <txp:smd_access_key />
function smd_access_protect($atts, $thing = null)
{
    global $smd_access_error, $smd_access_errcode, $smd_akey_protected_info, $smd_akey_prefs, $permlink_mode, $plugins;

    extract(lAtts(array(
        'trigger'      => 'smd_akey',
        'trigger_mode' => 'exact', // exact, begins, ends, contains
        'site_name'    => '1',
        'section_mode' => '0',
        'force'        => '0',
        'expires'      => '3600', // in seconds
    ), $atts));

    if (smd_akey_table_exist(1)) {
        $url = serverSet('REQUEST_URI');

        if ($site_name && (strpos($url, hu) === false)) {
            $urlparts = parse_url(hu);
            // Can't use raw hu since it contains the subdir (as does the REQUEST_URI)
            // so duplicates would occur in the generated URL
            $url = $urlparts['scheme'] . '://' . $urlparts['host'] . $url;
        }

        if ($section_mode == '1') {
            $halves = explode('?', $url);
            $half1 = explode('/', $halves[0]);
            $half2 = (isset($halves[1])) ? explode('/', $halves[1]) : array();

            $parts = array_merge($half1, $half2);
        } else {
            $parts = explode('/', $url);
        }

        trace_add('[smd_access_key URL elements: ' . join('|', $parts).']');

        // Look for one of the triggers in the URL and bomb out if we find it
        $triggers = do_list($trigger);
        $trigger = $triggers[0]; // Initialise to the first value in case no others are found

        $trigoff = false;

        foreach ($triggers as $trig) {
            switch ($trigger_mode) {
                case 'exact':
                    $trigoff = array_search($trig, $parts);
                    $realTrig = $trig;
                break;
                case 'begins':
                    $count = 0;
                    foreach ($parts as $part) {
                        if (strpos($part, $trig) === 0) {
                            $trigoff = $count;
                            $realTrig = $part;
                            break;
                        }
                        $count++;
                    }
                break;
                case 'ends':
                    $count = 0;
                    foreach ($parts as $part) {
                        $re = '/.+'.preg_quote($trig).'$/i';
                        if (preg_match($re, $part) === 1) {
                            $trigoff = $count;
                            $realTrig = $part;
                            break;
                        }
                        $count++;
                    }
                break;
                case 'contains':
                    $count = 0;
                    foreach ($parts as $part) {
                        $re = '/.*'.preg_quote($trig).'.*$/i';
                        if (preg_match($re, $part) === 1) {
                            $trigoff = $count;
                            $realTrig = $part;
                            break;
                        }
                        $count++;
                    }
                break;
            }

            if ($trigoff !== false) {
                // Found it so set the trigger to be the current item and jump out
                $trigoff = ($trigger == 'file_download') ? $trigoff + 2 : $trigoff;
                $trigger = $realTrig;
                break;
            }
        }

        trace_add('[smd_access_key trigger: ' . $trigger . ($trigoff ? ' found at ' . $trigoff : '') . ']');

        $ret = false;
        $smd_access_error = $smd_access_errcode = '';
        $smd_akey_salt_length = get_pref('smd_akey_salt_length', $smd_akey_prefs['smd_akey_salt_length']['default']);
        $doip = get_pref('smd_akey_log_ip', $smd_akey_prefs['smd_akey_log_ip']['default']);

        if ($trigoff !== false) {
            $tokidx = $trigoff + 1;
            $timeidx = $trigoff + 2;
            $extraidx = $trigoff + 3;

            // OK, on a trigger page, so read the token from the URL
            $tok = (isset($parts[$tokidx]) && strlen($parts[$tokidx]) == intval(32 + $smd_akey_salt_length)) ? $parts[$tokidx] : 0;

            trace_add('[smd_access_key token: ' . $tok .']');

            if ($tok) {
                // The token is present, so read the timestamp from the URL
                $t_hex = (isset($parts[$timeidx])) ? $parts[$timeidx] : 0;

                // Is there a download limit? Extract it if so
                $timeparts = do_list($t_hex, '.');
                $max = (isset($timeparts[1])) ? $timeparts[1] : '0';
                $maxtok = ($max) ? '.'.$max : '';
                $t_hex = $timeparts[0];

                // Any extra info?
                $extras = (isset($parts[$extraidx])) ? array_slice($parts, $extraidx) : array();

                // Recreate the original page URL, sans /trigger/token/time
                if ($trigger == 'file_download') {
                    $trigoff++;
                    $trigger = '';
                }

                // gbp_permanent_links sets messy mode behind the scenes but still uses non-messy URLs
                // so it requires an exception
                $gbp_pl = (is_array($plugins) && in_array('gbp_permanent_links', $plugins));

                if ($permlink_mode == 'messy' && !$gbp_pl) {
                    // Don't want a slash between site and start of query params
                    $page = rtrim(join('/', array_slice($parts, 0, $trigoff-1)), '/') . $parts[$trigoff-1];
                } else {
                    $page = rtrim(join('/', array_slice($parts, 0, $trigoff)), '/');
                }

                // In case the URL contains non-ascii chars
                $page = rawurldecode($page);

                trace_add('[smd_access_key page | timestamp | max | extras: ' . join('|', array($page, $t_hex, $max, $extras)) . ']');

                if ($t_hex) {
                    // The timestamp is present. Next, get the secret key
                    $secret = false;
                    $secring = safe_row('*', SMD_AKEYS, "page='".doSlash($page)."' AND t_hex = '".doSlash($t_hex)."'");

                    if ($secring) {
                        $secret = $secring['secret'];

                        // Extract the salt from the token
                        $plen = strlen($page) % 32;
                        $salt = substr($tok, $plen, $smd_akey_salt_length);
                        $tok = substr($tok, 0, $plen).substr($tok, $plen+$smd_akey_salt_length);
                        $ext = (($extras) ? urldecode(join('/', $extras)) : '');

                        // Regenerate the original token...
                        $check_token = md5($salt.$secret.$page.$trigger.$t_hex.$maxtok.$ext);

                        trace_add('[smd_access_key reconstructed token: ' . $check_token . ']');

                        // ... and compare it to the one in the URL
                        if ($check_token == $tok) {
                            // Token is valid. Now check if the page has expired

                            // Is there an explicit access key expiry? Extract that if so
                            $timeparts = do_list($t_hex, '-');
                            $t_exp = (isset($timeparts[1])) ? hexdec($timeparts[1]) : '';
                            $t_beg = $timeparts[0];

                            $t_dec = hexdec($t_beg);
                            $now = time();

                            // Has the resource become available yet?
                            if ($now < $t_dec) {
                                if ($thing == null) {
                                    txp_die(gTxt('smd_akey_err_unavailable'), 410);
                                } else {
                                    $smd_access_error = 'smd_akey_err_unavailable';
                                    $smd_access_errcode = 410;
                                }
                            } else {
                                // Has token's expiry been reached, or is 'now' greater than 'then' (when token generated) + expiry period?
                                if ($t_exp) {
                                    $tester = true;
                                    $compare_to = $t_exp;
                                } else {
                                    $tester = ($expires != 0);
                                    $compare_to = $t_dec + $expires;
                                }

                                if ($tester && ($now > $compare_to)) {
                                    if ($thing == null) {
                                        txp_die(gTxt('smd_akey_err_expired'), 410);
                                    } else {
                                        $smd_access_error = 'smd_akey_err_expired';
                                        $smd_access_errcode = 410;
                                    }
                                } else {
                                    // Check if the download limit has been exceeded
                                    $vu_qty = $secring['accesses'];
                                    if ($max) {
                                        if ($vu_qty < $max) {
                                            $ret = true;
                                        } else {
                                            if ($thing == null) {
                                                txp_die(gTxt('smd_akey_err_limit'), 410);
                                            } else {
                                                $smd_access_error = 'smd_akey_err_limit';
                                                $smd_access_errcode = 410;
                                            }
                                        }
                                    } else {
                                        $ret = true;
                                    }

                                    // Increment the access counter
                                    $vu_qty++;

                                    // Grab the IP and add it to the list of IPs so far
                                    if ($doip) {
                                        $ips = do_list($secring['ip'], ' ');
                                        $ip = remote_addr();
                                        if (!in_array($ip, $ips)) {
                                            $ips[] = $ip;
                                        }
                                        $ipup = ", ip='".doSlash(trim(join(' ', $ips)))."'";
                                    } else {
                                        $ipup = '';
                                    }

                                    safe_update(SMD_AKEYS, "accesses='".doSlash($vu_qty)."'" . $ipup, "page='".doSlash($page)."' AND t_hex = '".doSlash($t_hex)."'");

                                    // Load up the global array so <txp:smd_access_info> and <txp:if_smd_access_info> work
                                    $smd_akey_protected_info = array(
                                        'page'     => $secring['page'],
                                        'hextime'  => $secring['t_hex'],
                                        'issued'   => $secring['time'],
                                        'now'      => $now,
                                        'expires'  => $compare_to,
                                        'trigger'  => $secring['triggah'],
                                        'maximum'  => $secring['maximum'],
                                        'accesses' => $vu_qty,
                                    );
                                    if ($doip) {
                                        $smd_akey_protected_info['ip'] = $ip;
                                    }
                                    if ($extras) {
                                        $smd_akey_protected_info['extra'] = urldecode(join('/', $extras));
                                        foreach ($extras as $idx => $extra) {
                                            $smd_akey_protected_info['extra_'.intval($idx+1)] = urldecode($extra);
                                        }
                                    }
                                }
                            }

                        } else {
                            if ($thing == null) {
                                txp_die(gTxt('smd_akey_err_invalid_token'), 403);
                            } else {
                                $smd_access_error = 'smd_akey_err_invalid_token';
                                $smd_access_errcode = 403;
                            }
                        }

                    } else {
                        if ($thing == null) {
                            txp_die(gTxt('smd_akey_err_unauthorized'), 401);
                        } else {
                            $smd_access_error = 'smd_akey_err_unauthorized';
                            $smd_access_errcode = 401;
                        }
                    }

                } else {
                    if ($thing == null) {
                        txp_die(gTxt('smd_akey_err_missing_timestamp'), 403);
                    } else {
                        $smd_access_error = 'smd_akey_err_missing_timestamp';
                        $smd_access_errcode = 403;
                    }
                }

            } else {
                if ($thing == null) {
                    txp_die(gTxt('smd_akey_err_bad_token'), 403);
                } else {
                    $smd_access_error = 'smd_akey_err_bad_token';
                    $smd_access_errcode = 403;
                }
            }
        } else {
            // If we always want to forbid access to this page regardless if the trigger exists
            if ($force) {
                if ($thing == null) {
                    txp_die(gTxt('smd_akey_err_forbidden'), 401);
                } else {
                    $smd_access_error = 'smd_akey_err_forbidden';
                    $smd_access_errcode = 401;
                }
            } else {
                $ret = true;
            }
        }

        if ($smd_access_error || $smd_access_errcode) {
            trace_add('[smd_access_key error state: ' . $smd_access_errcode . '|' . $smd_access_error . ']');
        }

        // If we reach this point it's because we're using a container
        return parse(EvalElse($thing, $ret));
    } else {
        trigger_error(gTxt('smd_akey_tbl_not_installed'), E_USER_NOTICE);
    }
}

// Called just before a download is initiated
function smd_access_protect_download($evt, $stp)
{
    global $smd_akey_prefs, $id, $file_error;

    if (smd_akey_table_exist(1) && !isset($file_error)) {
        $fileid = intval($id);

        // In case the page was called with a bogus filename, get the "true" filename
        // from the database and make up the valid URL
        $real_file = safe_field("filename", "txp_file", "id=".doSlash($fileid));
        $page = filedownloadurl($fileid, $real_file);
        $secring = safe_field('page', SMD_AKEYS, "page='".doSlash($page)."'");

        // Only want to protect pages that we've generated tokens for
        if ($secring) {
            // Pass in a default expiry from the pref, but it can be overridden by the key's expiry
            return smd_access_protect(
                array(
                    'trigger' => 'file_download',
                    'force'   => '1',
                    'expires' => get_pref('smd_akey_file_download_expires', $smd_akey_prefs['smd_akey_file_download_expires']['default']),
                )
            );
        }
    }
    // remote download not done - leave to Txp to handle error or "local" file download
    return;
}

// Conditional tag for checking error status from smd_access_protect
function smd_if_access_error($atts, $thing = null)
{
    global $smd_access_error, $smd_access_errcode;

    extract(lAtts(array(
        'type'   => '',
        'code'   => '',
    ), $atts));

    $err = array();
    $codes = do_list($code);
    $types = do_list($type);

    if ($smd_access_error) {
        if ($code && $type) {
            $err['code'] = (in_array($smd_access_errcode, $codes)) ? true : false;
            $err['msg'] = (in_array($smd_access_error, $types)) ? true : false;
        } elseif ($code) {
            $err['code'] = (in_array($smd_access_errcode, $codes)) ? true : false;
        } elseif ($type) {
            $err['msg'] = (in_array($smd_access_error, $types)) ? true : false;
        } else {
            $err['msg'] = true;
        }
    }

    $out = in_array(false, $err) ? false : true; // AND logic

    return parse(EvalElse($thing, $out));
}

// Display access error information
function smd_access_error($atts, $thing = null)
{
    global $smd_access_error, $smd_access_errcode;

    extract(lAtts(array(
        'item'    => 'message',
        'message' => '',
        'wraptag'    => '',
        'class'      => '',
        'html_id'    => '',
        'break'      => '',
        'breakclass' => '',
    ), $atts));

    $out = array();
    $items = do_list($item);

    if ($smd_access_errcode && in_array('code', $items)) {
        $out[] = $smd_access_errcode;
    }

    if ($smd_access_error && in_array('message', $items)) {
        $out[] = ($message) ? $message : gTxt($smd_access_error);
    }

    if ($out) {
        return doWrap($out, $wraptag, $break, $class, $breakclass, '', '', $html_id);
    }

    return '';
}

// Display access information for custom formatted messages
function smd_access_info($atts, $thing = null)
{
    global $smd_akey_protected_info, $smd_akey_info;

    extract(lAtts(array(
        'item'       => 'page',
        'escape'     => 'html',
        'format'     => '%Y-%m-%d %H:%M:%S',
        'wraptag'    => '',
        'class'      => '',
        'html_id'    => '',
        'break'      => '',
        'breakclass' => '',
    ), $atts));

    $out = array();
    $items = do_list($item);

    foreach ($items as $idx) {
        $ak_idx = 'ak_'.$idx;

        if ($smd_akey_protected_info && array_key_exists($idx, $smd_akey_protected_info)) {
            $val = ($escape == 'html') ? htmlspecialchars($smd_akey_protected_info[$idx]) : $smd_akey_protected_info[$idx];
            if (in_array($idx, array('time', 'now', 'expires')) && $format) {
                $val = safe_strftime($format, $val);
            }
            $out[] = $val;
        }

        if ($smd_akey_info && array_key_exists($ak_idx, $smd_akey_info)) {
            $val = ($escape == 'html') ? htmlspecialchars($smd_akey_info[$ak_idx]) : $smd_akey_info[$ak_idx];
            if (in_array($idx, array('time', 'now', 'expires')) && $format) {
                $val = safe_strftime($format, $val);
            }
            $out[] = $val;
        }
    }

    if ($out) {
        return doWrap($out, $wraptag, $break, $class, $breakclass, '', '', $html_id);
    }

    return '';
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_access_keys

Permit access to downloads, pages or parts of pages for a certain configurable time period and/or for a defined number of access attempts. The access key is tamper-proof.

h2. Installation / uninstallation

p(important). Requires TXP 4.4.0+

Download the plugin from either "textpattern.org":http://textpattern.org/plugins/1222/smd_access_keys, or the "software page":http://stefdawson.com/sw, paste the code into the TXP _Admin->Plugins_ pane, install and enable the plugin. The table will be installed automatically unless you use the plugin from the cache directory; in which case, visit the _Extensions->Access keys_ tab and click the _Install table_ button before trying to use the plugin.

To uninstall the plugin, delete from the _Admin->Plugins_ page. The access keys table will be deleted automatically.

Visit the "forum thread":http://forum.textpattern.com/viewtopic.php?id=35996 for more info or to report on the success or otherwise of the plugin.

h2. Overview

The plugin allows you to create keys for any URL and you can then protect all or part of that URL by either:

a) wrapping a tag around the parts you want protecting
b) putting a protection tag at the top of the page to protect its entirety
c) flagging the URL as a file_download whereby it's automatically restricted to only those in possession of the key

An access key is a long URL that looks like this: @site.com/some/protected/url/<trigger>/<token>/<timestamps>.<max_accesses>@. Any deviation or alteration of the token results in rejection. The secret key used to protect the resources can be automatically generated by the plugin or supplied by you. A user-configurable salt is applied as well. For best results, let the plugin choose, because then no two secret keys/salts will ever be the same, even if they protect the exact same resource.

You can time-limit the access to the resource if you like -- from the moment the key is generated or from some arbitrary point in the future -- and/or you can limit the number of accesses (views/downloads) that resource can have. When the resource has expired, it's gone.

Keys can be generated from the admin side interface (_Extensions->Access keys_) or via a public tag. The latter allows you to offer self-key generation, perhaps in response to a mem_self_register request or zem_contact_reborn form submission, or from an admin-side dashboard.

h2. Tag: @<txp:smd_access_key />@

Generates an access token for a given URL. Configure it using the following attributes:

; %url%
: The URL of the resource you want to protect. Can be either a fully-qualified URL or relative.
: Default: the current page
; %trigger%
: This string is added to your url so the plugin knows it is protected content.
: Default: @smd_akey@
; %site_name%
: Whether to automatically add the site name (http://site.com) to the URL or not. Choose from:
:: 0: leave the site out
:: 1: add the site URL (but only if its not in the URL already)
: Default: 1
; %section_mode%
: If you are trying to protect a section landing page (i.e. not an article) then you will likely receive a 404 error when trying to access URLs that entirely use '/' as their separator. By setting @section_mode="1"@ you tell the plugin to separate the page URL from the access key information with a '?' instead.
: Note this means you cannot use the plugin with the messy mode URL structure, nor can you pass any traditional URL parameters to the page. You can, however, encode such parameters into the @extra@ area of the key.
: Default: 0
; %secret%
: A secret key used to unlock the page. Choose any text you wish here or leave blank to have the plugin choose a random number for you.
; %strength%
: The mechanism used to generate the salt, or the salt itself. In all cases, the salt length will be constrained by the plugin preference value. Choose from the following options:
: @SMD_SSL_RAND@: Use PHP's cryptographically-secure @openssl_random_pseudo_bytes()@ function, if available (requires PHP 5.3.0+). If it's not available, it will default to the SMD_MD5 mechanism. It is highly recommended to migrate to this scheme if at all possible.
: @SMD_MD5@: Use standard @md5()@ and @uniqid()@ functions. These are *not* cryptographically secure, and were the default for plugin versions prior to v0.20.
: @<your own salt>@: Use your own salt value. This option permits you to generate your own cryptographically-secure salt if you wish (e.g. to compute some salt prior to calling this tag and injecting it here). *Your salt MUST change every time* unless you really, really know what you are doing, and take suitable precautions. See "Example 4":#smd_akey_eg4.
; %start%
: An English-formatted date stamp from when you want the resource to become available. It is often best to use @YYYY-MMM-DD HH:MM:SS@ format as it is the least ambiguous.
: Default: empty (i.e. now)
; %expires%
: An English-formatted date stamp at which you wish the resource to expire, OR an offset beginning with '+', for example @expires="+24 hours"@
: Default: empty (i.e. no key expiry: get the expiry information from the protected resource itself)
; %max%
: If you wish to limit the number of times this resource can be requested, set its value here.
: Default: unset (i.e. unlimited)
; %extra%
: If you want to add any additional information to the URL (after the token) then specify it here. If set, a slash will be added to the URL and then your given content will be appended. It's up to you to ensure it is suitably encoded. Note that you can add multiple items, separating each with a slash character if you wish. Additional values added using this attribute are directly accessible using the @<txp:smd_access_info />@ tag

A few words about this tag:

# The clock starts ticking the moment the token is generated (or at the time given in the @start@ attribute)
# You can pass the returned access link around as often as you like to as many people as you like and it'll keep working until its expiry/access limit is met
# Each time you create a new token (even for the same page) a new access counter is generated; any previously created tokens that still have available access attempts will continue to function until the limit is reached or they expire
# Direct TXP file downloads can be protected too. Just supply @trigger="file_download"@ and @url="/file_download/<id>/<filename>"@. You will then not be permitted to access the site.com/file_download/<id> link without the access token being present. For this to be effective you should move your @/files@ directory out of a web-accessible location or employ an .htaccess file that forbids bypassing the @site.com/files/<filename>@ URL format. Note that TXP normally allows you to type anything after the @/id/@ as a filename and still retrieve the file; smd_access_key will not: the filename must match exactly
# If the @start@ date is mangled in any way, the key will be generated 'now'
# If the @expires@ is mangled in any way, no epxiry will be set
# Your secret key is salted using a randomly-generated salt, or one of your own choosing. The default length of the salt is 8 characters but you can "alter this via a pref":#smd_akey_prefs
# You may control the expiry time of _all_ of your direct file_download URLs by altering a "pref":#smd_akey_prefs

h3. Example: Creating a key

bc. <txp:smd_access_key
     url="/music/next-big-hit" trigger="demo" />

This might generate an access-controlled URL such as the following (newlines just for clarity):

bc. http://site.com/music/next-big-hit/demo/
     42c531d13423eecaaab73a2df43a8b7c337a360a/4d9d12a5

Send that link to your friend and she'll be able to listen to your cool new demo song.

h2. Tag: @<txp:smd_access_protect />@

Once you've generated a key, it's time to protect all or part of your chosen URL.

* To protect an entire page, put the @<txp:smd_access_protect>@ tag right at the top, above your DOCTYPE
* To only protect part of a page, wrap the protected content with the tag
* File downloads are automatically protected if you have generated a key for them

Configure the plugin with the following attributes:

; %trigger%
: The same trigger you specified in your @<txp:smd_access_key />@ tag. You may specify a partial trigger if utilising @trigger_mode@.
: Default: @smd_akey@
; %trigger_mode%
: Usually you'll want the trigger to match exactly, but you may wish to match some part of the trigger (for a practical example of this, see "example 3":#smd_akey_eg3). Choose from:
:: *exact* : (default) incoming token's trigger must exactly match the @trigger@ attribute
:: *begins* : incoming token's trigger must begin with @trigger@
:: *ends* : incoming token's trigger must end with @trigger@
:: *contains* : incoming token's trigger must contain the text given in @trigger@
: Default: @exact@
; %site_name%
: Add the site prefix to the URL when trying to figure out if the resource is protected. This functions identically to the attribute in the @<txp:smd_access_key />@ tag and, ideally, they should match.
: Default: 1 (yes)
; %section_mode%
: Works in an identical manner to the attribute in the @<txp:smd_access_key />@ tag and they should match.
: Default: 0 (no)
; %force%
: Controls content availability. This is one method of putting the token generation tag and the protected content on the same page. Choose from:
:: 0: permit the resource to be directly accessible
:: 1: the content is unavailable even if you _don't_ specify the access token in the URL (e.g. if you directly visit @/music/next-big-hit@)
: Default: 0
; %expires%
: The time, in seconds, after which the resource will cease to be available. Set to 0 for unlimited time.
: If you have set an expiry in the key itself, this attribute is ignored when that key is presented.
: Default: 3600 (1 hour)

(if you wish to log IP addresses for each access attempt you can set the "pref":#smd_akey_prefs)

In our music example you'll probably want to put the following tag at the top of the page:

bc. <txp:smd_access_protect expires="86400" trigger="demo" force="1" />

So nobody can visit that page unless they have been given a valid access token URL. The song will be available for listening for just one day from the moment the token is generated.

Alternatively, you might allow people to see the surrounding page and just wrap the mp3 itself with the tag:

bc. <txp:smd_access_protect expires="86400" trigger="demo">
  <object type="/audio/mpeg" data="/music/next-big-hit.mp3" />
</txp:smd_access_protect>

Note that you can protect as many parts of the same page as you like, differing only in the @trigger@. This allows you to unlock various parts of your pages to different people and set individual expiry times for each portion of the page, or set expiry times in the access tokens themselves.

Another option is is to elect to create the access key directly for the file_download itself. If you're feeling paranoid you could do both :-)

h2. Tag: @<txp:smd_if_access_error />@

Conditional tag that triggers the contained content if access was denied for some reason.  Supports @<txp:else />@. Attributes:

; %type%
: Comma-delimited list of one or more of the following access types. The corresponding HTTP error status code thrown is given in parentheses:
:: *smd_akey_err_bad_token* (403) : if the access token in the URL is too long/short
:: *smd_akey_err_expired* (410) :  if the resource has passed its expiry
:: *smd_akey_err_forbidden* (401) : triggered if the @force@ attribute is set and direct access has been requested
:: *smd_akey_err_invalid_token* (403) : the access key in the URL doesn't match the one used to protect the resource
:: *smd_akey_err_limit* (410) : the number of permitted views (specified via the @max@ attribute) has been exceeded
:: *smd_akey_err_missing_timestamp* (403) : the timestamp portion of the access key is missing from the URL
:: *smd_akey_err_unavailable* (410) : the resource isn't yet available (the start time hasn't been reached)
:: *smd_akey_err_unauthorized* (401) : the resource isn't one that has been protected by this access key
; %code%
: Comma-delimited list of one or more of the access codes given in the above list

Without any attributes, the tag triggers the contained content on any error. If you specify many @type@s then _any_ of the listed types that match will return true. The same goes for @code@s. If you use the two in tandem then the error must match one of the types AND one of the codes to return true.

h2. Tag: @<txp:smd_access_error />@

Display an access error message/code. Typically used inside @<txp:smd_if_access_error>@. Attributes:

; %item%
: Which error information to display. Comma-separate the values if you wish to use both. Choose from:
:: @message@: the error string
:: @code@: the status code
: Default: @message@
; %message%
: Display this message instead of the plugin default message.
; %wraptag%
: HTML tag (without angle brackets) to wrap around the output.
; %class%
: CSS class name to apply to the wraptag.
; %html_id%
: HTML ID to apply to the wraptag.
; %break%
: HTML tag (without angle brackets) to wrap around each item.
; %breakclass%
: CSS class name to apply to each break tag

h2. Tag: @<txp:smd_access_info />@

Display access information from the current protected page. Attributes:

; %item%
: One or more of the following items (comma-delimited):
:: *page* : the URL that matches the current access key
:: *trigger* : the trigger for the current key, as given in the URL
:: *maximum* : the maximum number of access attempts allowed for this key
:: *accesses* : the current number of access attempts that have been made for this key
:: *now* : the raw UNIX timestamp of the time the access attempt was made
:: *issued* : the raw UNIX timestamp of when the current key was created
:: *expires* : the raw UNIX timestamp of when the current key expires
:: *hextime* : the UNIX timestamp of when the current key was created, encoded as a hex string (as used in the URL)
:: *ip* : the IP address of the remote address making the request (if available)
:: *extra* : the full string as given in the @extra@ attribute
:: *extra_N* (where N is an integer beginning at 1) : if you have specified many values in your @extra@, each separated with a slash, this displays the given individual item
: Default: @page@
; %escape%
: Whether to escape HTML entities such as @<@, @>@ and @&@ in the item. Set @escape=""@ to turn this off.
: Defualt: @html@
; %format%
: If displaying a time-related item (now, issued, or expires) you can format it using the "strftime() formatting codes":http://php.net/manual/en/function.strftime.php.
: Default: @%Y-%m-%d %H:%M:%S@
; %wraptag%
: HTML tag (without angle brackets) to wrap around the output.
; %class% :
CSS class name to apply to the wraptag.
; %html_id%
: HTML ID to apply to the wraptag.
; %break%
: HTML tag (without angle brackets) to wrap around each item.
; %breakclass%
: CSS class name to apply to each break tag.

h2. Admin side interface

Under _Extensions->Access keys_ you will see a list of all keys that have been generated. You can sort the list (ascending or descending) by clicking the headings or you can filter them using the select list and text box at the top. The usual multi-edit tools/checkboxes are available to allow you to batch-delete keys.

If you want to manually create an access key, click _New key_ and fill in as many of the text boxes as you wish. The only one that is mandatory is the _Page_ box. Your access key will be generated and displayed in the message area for you to copy and paste.

A few things to note about the list:

* If you have elected to log IP addresses (see "prefs":#smd_akey_prefs) the IPs of all key-based access attempts are logged. Further, if you have TXP visitor logging enabled, the addresses in the 'IP' column are hyperlinked to the Visitor Logs to show you all accesses by the clicked IP. Although the plugin can potentially hold over 1600 IP addresses the interface might crumble before you reach this limit so it's best to try and limit the number of accesses per key to keep the number of attempts low and make the interface manageable
* The Page is hyperlinked to the protected URL but it does _not_ attempt to access it with the key. It is just a convenience for you to check that the page is protected. If you want to access the page via its key you can do so from the Visitor Logs, assuming someone has tried at least once to access the resource -- you can just click the relevant visitor log Page entry which contains the full access key URL
* The number of accesses may exceed the maximum permitted. This is because attempts are logged all the time the resource is available (i.e. has not expired). Rest assured if the maximum access limit has been reached, visitors with valid keys will NOT see the protected content, even though the counter will continue to log requests. You can use this information to check the TXP Visitor Logs for IP addresses that try to access a resource after the limit has been reached. As soon as the resource expires, access counting ceases
* The trigger for file downloads is empty. This is a side-effect of the way the plugin operates but it has a useful bonus: you can leave the trigger blank if your Page URL contains @/file_download@ (because the trigger itself is the presence of @file_download@ in the URL)

h2(#smd_akey_prefs). Preferences

The plugin exposes some global preference settings that govern its operation:

; %File download expiry time%
: Time, in seconds, after which your file downloads will expire, following generation of an access key.
: Default: 3600 (1 hour)
; %Salt length%
: Number of characters to use as a salt in your secret key. The default is usually fine but if you want to alter this for greater/reduced security then do so. IMPORTANT: if you change this value, all your existing access keys become instantly invalid.
: Default: 8
; %Log IP addresses%
: Whether you wish the plugin to log the IP address of each visitor who tries to access a protected resource
: Default: no

h2(#smd_akey_eg1). Example 1: Protect part of a page

bc. <txp:smd_access_protect
     trigger="leaflets" force="1" expires="86400">
   <p>Your leaflet contents goes here</p>
<txp:else />
   <txp:smd_if_access_error
     type="smd_akey_err_forbidden">
      <p>Before you can download this item, you'll need
     an access key.
     <a href="<txp:smd_access_key max="4"
        trigger="leaflets" />">Here's one</a></p>
   <txp:else />
      <txp:smd_access_error item="code, message"
          break="br" />
   </txp:smd_if_access_error>
</txp:smd_access_protect>

h2(#smd_akey_eg2). Example 2: Protect multiple resources with one tag

bc. <txp:smd_access_protect
     trigger="leaflet, book" expires="86400">
   <p>Your <txp:smd_access_info item="trigger" />
          contents goes here</p>
<txp:else />
   <txp:smd_if_access_error>
      <txp:smd_access_error item="code, message"
          break="br" />
   </txp:smd_if_access_error>
</txp:smd_access_protect>

You can use smd_if, or assign the output of @<txp:smd_access_info>@ to a @<txp:variable>@ and use @<txp:if_variable>@, to perform conditional display of access key information.

h2(#smd_akey_eg3). Example 3: Issue a registration activation code

In your registration e-mail form, you could put something like this:

bc. Thank you for registering with <txp:site_name />.
To activate your account, please visit the following
link within the next 24 hours:
<txp:smd_access_key url="/account/activate/" max="1"
     trigger='user_id.<txp:author title="0" />' />

Once the visitor has signed up they are e-mailed a unique access token to the @/account/activate@ page. You can protect it like this:

bc. <txp:smd_access_protect trigger="user_id."
     trigger_mode="begins" expires="86400" force="1">
   <txp:hide> Activation actions could go here </txp:hide>
   <p>Congratulations, your account is activated.</p>
<txp:else />
   <txp:smd_access_error />
</txp:smd_access_protect>

If necessary, you could employ @str_replace()@ (or @<txp:rah_replace />@) to remove the @user_id.@ and be left with the user name of the registered user name, suitable for display. Alternatively -- and perhaps easier -- you could pass the author as an @extra@ parameter when the key is generated:

bc. <txp:smd_access_key url="/account/activate/" max="1"
     trigger="user_id" extra='<txp:author title="0" />' />

This allows you to use the default @trigger_mode@ and has the benefit that you can directly access the additional piece of information using @<txp:smd_access_info item="extra_1" />@.

The @extra@ attribute is even more powerful if you pack more information into it: @extra='<txp:author title="0" />/<txp:section />'@. You can retrieve each piece individually:

* @<txp:smd_access_info item="extra" />@ => @sdawson/account@
* @<txp:smd_access_info item="extra_1" />@ => @sdawson@
* @<txp:smd_access_info item="extra_2" />@ => @account@

Note that you can only extract each piece individually if you use a slash as a separator in your @extra@ attribute.

h2(#smd_akey_eg4). Example 4: Use the same key

You will often use smd_access_key in response to a user action, such as paying for access to a resource. In this situation, you would normally email an access key upon successful payment. But sometimes you may wish to return control to your website and show the access key on-screen so there is no permanent record of it.

The trouble with this approach is that if the person refreshes the screen, they will get a different access key to the same resource, thereby effectively having the ability to generate unlimited keys. This is undesirable.

From v0.20 onwards, you may now specify your own salt, which can be used to create a static key. But it comes with some important security considerations:

* Your salt is included in the access key itself and is thus plainly visible. This is not normally a security concern because the secret key protects the token, but to make a fixed key you need a static secret key too.
* A static secret key *must* still be unique to the transaction. Never share a secret key between transactions or users or connections.
* You will also need to use a static @start@ date and time. In this situation, a good value to use would be the order time.

To understand this fully, it's important to grasp how an access token is generated. It consists of three parts:

* A secret key (random string of hex digits), which is usually generated at runtime and is unique.
* A salt (random string of hex digits), again, uniquely generated at runtime.
* A timestamp (YYYY-MM-DD HH:mm:ss) which defaults to 'now', i.e. the very instant the @<txp:smd_access_key />@ tag is rendered.

Assuming the URL of the resource doesn't change, if all three of the above components are identical on each page request, the same key will be generated. As this is our goal, here, let's design a system to do that:

* Secret key: a hash of the buyer's username and the transaction ID, plus a fixed secret string of your own choosing.
* Salt: a hash of the buyer's email address. The length of this must be greater than or equal to the value in the __Salt length__ preference, otherwise the token will not match.
* Timestamp: the time of the transaction.

So, for any given transaction, the above three items do not vary and will therefore generate the same key. But a transaction that occurs from someone else, even if it's at the exact same moment, will produce an entirely different, yet invariant, key.

So here's some not-very-super-secure example code, assuming that you have the order information populated in some PHP variables. You probably wouldn't use @md5()@ here, but something altogether more cryptographically secure:

bc.. <txp:variable name="secretKey"><txp:php>echo md5($username . $txnId . "I'mABadSecretKey");</txp:php></txp:variable>
<txp:variable name="salt"><txp:php>echo md5($email);</txp:php></txp:variable>
<txp:variable name="timestamp"><txp:php>echo $orderTime;</txp:php></txp:variable>

<txp:smd_access_key
   url='<txp:site_url /><txp:section />'
   trigger="my-trigger"
   secret='<txp:variable name="secretKey" />'
   strength='<txp:variable name="salt" />'
   start='<txp:variable name="timestamp" />'
   />

p. Ta da!

h2. Author

Written by "Stef Dawson":http://stefdawson.com/contact.
# --- END PLUGIN HELP ---
-->
<?php
}
?>