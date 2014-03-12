#!/usr/bin/php -d open_basedir=/
<?php
/*
Copyright 2009-2014 Guillaume Boudreau

This file is part of Greyhole.

Greyhole is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Greyhole is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.
*/

include('includes/common.php');
ConfigHelper::parse();

$total_space = 0;
$total_free_space = 0;
foreach (Config::storagePoolDrives() as $sp_drive) {
	$response = explode(' ', exec("df -k ".escapeshellarg($sp_drive)." 2>/tmp/greyhole_df_error.log | tail -1 | awk '{print \$(NF-4),\$(NF-2)}'"));
	if (count($response) != 2) {
		continue;
	}
	$total_space += $response[0];
	$total_free_space += $response[1];
}

echo "$total_space $total_free_space 1024\n";
?>
