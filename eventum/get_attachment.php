<?php
/* vim: set expandtab tabstop=4 shiftwidth=4: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003, 2004 MySQL AB                                    |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 59 Temple Place - Suite 330                                          |
// | Boston, MA 02111-1307, USA.                                          |
// +----------------------------------------------------------------------+
// | Authors: Jo�o Prado Maia <jpm@mysql.com>                             |
// +----------------------------------------------------------------------+
//
// @(#) $Id: s.get_attachment.php 1.5 03/09/30 18:07:03-00:00 jpradomaia $
//
include_once("config.inc.php");
include_once(APP_INC_PATH . "class.auth.php");
include_once(APP_INC_PATH . "class.support.php");
include_once(APP_INC_PATH . "class.mime_helper.php");
include_once(APP_INC_PATH . "db_access.php");

Auth::checkAuthentication(APP_COOKIE);

if (@$HTTP_GET_VARS['cat'] == 'blocked_email') {
    $email = Note::getBlockedMessage($HTTP_GET_VARS["note_id"]);
} else {
    $email = Support::getFullEmail($HTTP_GET_VARS["sup_id"]);
}

if (@$HTTP_GET_VARS["filename"] == 'Outlook.bmp') {
    list(, $data) = Mime_Helper::getAttachment($email, $HTTP_GET_VARS["filename"], $HTTP_GET_VARS["cid"]);
    header("Content-Type: image/bmp");
} else {
    list($mimetype, $data) = Mime_Helper::getAttachment($email, $HTTP_GET_VARS["filename"]);
    $parts = pathinfo($HTTP_GET_VARS["filename"]);
    $special_extensions = array(
        'err',
        'log',
        'cnf',
        'var',
        'ini'
    );
    // always force the browser to display the contents of these special files
    if (in_array(strtolower($parts["extension"]), $special_extensions)) {
        header('Content-Type: text/plain');
    } else {
        // output the real content-type from the email. maybe a bad idea?
        if (empty($mimetype)) {
            header("Content-Type: application/unknown");
        } else {
            header("Content-Type: " . urlencode($mimetype));
        }
        header("Content-Disposition: attachment; filename=" . urlencode($HTTP_GET_VARS["filename"]));
    }
}
header("Content-Length: " . strlen($data));
echo $data;
exit;
?>