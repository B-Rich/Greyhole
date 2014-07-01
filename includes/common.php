<?php
/*
Copyright 2009-2014 Guillaume Boudreau, Andrew Hopkinson

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

// Other helpers
require_once('includes/ConfigHelper.php');
require_once('includes/DB.php');
require_once('includes/Log.php');
require_once('includes/Settings.php');
require_once('includes/MigrationHelper.php');

$constarray = get_defined_constants(true);
foreach($constarray['user'] as $key => $val) {
    eval(sprintf('$_CONSTANTS[\'%s\'] = ' . (is_int($val) || is_float($val) ? '%s' : "'%s'") . ';', addslashes($key), addslashes($val)));
}

define('FSCK_TYPE_SHARE', 1);
define('FSCK_TYPE_STORAGE_POOL_DRIVE', 2);
define('FSCK_TYPE_METASTORE', 3);

set_error_handler("gh_error_handler");
register_shutdown_function("gh_shutdown");

umask(0);

setlocale(LC_COLLATE, "en_US.UTF-8");
setlocale(LC_CTYPE, "en_US.UTF-8");

if (!defined('PHP_VERSION_ID')) {
    $version = explode('.', PHP_VERSION);
    define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));
}

// Cached df results
$last_df_time = 0;
$last_dfs = array();
$sleep_before_task = array();

function recursive_include_parser($file) {
    
    $regex = '/^[ \t]*include[ \t]*=[ \t]*([^#\r\n]+)/im';
    $ok_to_execute = FALSE;

    if (is_array($file) && count($file) > 1) {
        $file = $file[1];
    }

    $file = trim($file);

    if (file_exists($file)) {
        if (is_executable($file)) {
            $perms = fileperms($file);

            // Not user-writable, or owned by root
            $ok_to_execute = !($perms & 0x0080) || fileowner($file) === 0;

            // Not group-writable, or group owner is root
            $ok_to_execute &= !($perms & 0x0010) || filegroup($file) === 0;

             // Not world-writable
            $ok_to_execute &= !($perms & 0x0002);

            if (!$ok_to_execute) {
                Log::warn("Config file '{$file}' is executable but file permissions are insecure, only the file's contents will be included.");
            }
        }

        $contents = $ok_to_execute ? shell_exec(escapeshellcmd($file)) : file_get_contents($file);
        
        return preg_replace_callback($regex, 'recursive_include_parser', $contents);
    } else {
        return false;
    }
}

function clean_dir($dir) {
    if (empty($dir)) {
        return $dir;
    }
    if ($dir[0] == '.' && $dir[1] == '/') {
        $dir = mb_substr($dir, 2);
    }
    while (string_contains($dir, '//')) {
        $dir = str_replace("//", "/", $dir);
    }
    $l = strlen($dir);
    if ($l >= 2 && $dir[$l-2] == '/' && $dir[$l-1] == '.') {
        $dir = mb_substr($dir, 0, $l-2);
    }
    $dir = str_replace("/./", "/", $dir);
    return $dir;
}

function explode_full_path($full_path) {
    return array(dirname($full_path), basename($full_path));
}

function gh_shutdown() {
    if ($err = error_get_last()) {
        Log::error("PHP Fatal Error: " . $err['message'] . "; BT: " . basename($err['file']) . '[L' . $err['line'] . '] ');
    }
}

function gh_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
    if(!($errno & error_reporting())) {
        // Ignored (@) warning
        return TRUE;
    }

    switch ($errno) {
    case E_ERROR:
    case E_PARSE:
    case E_CORE_ERROR:
    case E_COMPILE_ERROR:
        Log::critical("PHP Error [$errno]: $errstr in $errfile on line $errline");
        break;

    case E_WARNING:
    case E_COMPILE_WARNING:
    case E_CORE_WARNING:
    case E_NOTICE:
        $greyhole_log_file = Config::get(CONFIG_GREYHOLE_LOG_FILE);
        if ($errstr == "fopen($greyhole_log_file): failed to open stream: Permission denied") {
            // We want to ignore this warning. Happens when regular users try to use greyhole, and greyhole tries to log something.
            // What would have been logged will be echoed instead.
            return TRUE;
        }
        Log::warn("PHP Warning [$errno]: $errstr in $errfile on line $errline; BT: " . get_debug_bt());
        break;

    default:
        Log::warn("PHP Unknown Error [$errno]: $errstr in $errfile on line $errline");
        break;
    }

    // Don't execute PHP internal error handler
    return TRUE;
}

function get_debug_bt() {
    $bt = '';
    foreach (debug_backtrace() as $d) {
        if ($d['function'] == 'gh_error_handler' || $d['function'] == 'get_debug_bt') { continue; }
        if ($bt != '') {
            $bt = " => $bt";
        }
        $prefix = '';
        if (isset($d['file'])) {
            $prefix = basename($d['file']) . '[L' . $d['line'] . '] ';
        }
        if (!isset($d['args'])) {
            $d['args'] = array();
        }
        foreach ($d['args'] as $k => $v) {
            if (is_object($v)) {
                $d['args'][$k] = 'stdClass';
            }
            if (is_array($v)) {
                $d['args'][$k] = str_replace("\n", "", var_export($v, TRUE));
            }
        }
        $bt = $prefix . $d['function'] .'(' . implode(',', @$d['args']) . ')' . $bt;
    }
    return $bt;
}

function bytes_to_human($bytes, $html=TRUE) {
    $units = 'B';
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = 'KB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = 'MB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = 'GB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = 'TB';
    }
    $decimals = (abs($bytes) > 100 ? 0 : (abs($bytes) > 10 ? 1 : 2));
    if ($html) {
        return number_format($bytes, $decimals) . " <span class=\"i18n-$units\">$units</span>";
    } else {
        return number_format($bytes, $decimals) . $units;
    }
}

function duration_to_human($seconds) {
    $displayable_duration = '';
    if ($seconds > 60*60) {
        $hours = floor($seconds / (60*60));
        $displayable_duration .= $hours . 'h ';
        $seconds -= $hours * (60*60);
    }
    if ($seconds > 60) {
        $minutes = floor($seconds / 60);
        $displayable_duration .= $minutes . 'm ';
        $seconds -= $minutes * 60;
    }
    $displayable_duration .= $seconds . 's';
    return $displayable_duration;
}

function get_share_landing_zone($share) {
    $lz = SharesConfig::get($share, CONFIG_LANDING_ZONE);
    if ($lz !== FALSE) {
        return $lz;
    } else if (array_contains(ConfigHelper::$trash_share_names, $share)) {
        return SharesConfig::get(CONFIG_TRASH_SHARE, CONFIG_LANDING_ZONE);
    } else {
        Log::warn("  Found a share ($share) with no path in " . ConfigHelper::$smb_config_file . ", or missing it's num_copies[$share] config in " . ConfigHelper::$config_file . ". Skipping.");
        return FALSE;
    }
}

// Get CPU architecture (x86_64 or i386 or armv6l or armv5*)
$arch = exec('uname -m');
if ($arch != 'x86_64') {
    function gh_filesize($filename) {
        $result = exec("stat -c %s ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (float) $result;
    }
    
    function gh_fileowner($filename) {
        $result = exec("stat -c %u ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (int) $result;
    }
    
    function gh_filegroup($filename) {
        $result = exec("stat -c %g ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (int) $result;
    }

    function gh_fileperms($filename) {
        $result = exec("stat -c %a ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return "0" . $result;
    }

    function gh_is_file($filename) {
        exec('[ -f '.escapeshellarg($filename).' ]', $tmp, $result);
        return $result === 0;
    }

    function gh_fileinode($filename) {
        // This function returns deviceid_inode to make sure this value will be different for files on different devices.
        $result = exec("stat -c '%d_%i' ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (string) $result;
    }

    function gh_file_deviceid($filename) {
        $result = exec("stat -c '%d' ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (string) $result;
    }
    
    function gh_rename($filename, $target_filename) {
        exec("mv ".escapeshellarg($filename)." ".escapeshellarg($target_filename)." 2>/dev/null", $output, $result);
        return $result === 0;
    }
} else {
    function gh_filesize(&$filename) {
        $size = @filesize($filename);
        // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
        if ($size === FALSE) {
            // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
            $size = @filesize(normalize_utf8_characters($filename));
            if ($size !== FALSE) {
                // Bingo!
                $filename = normalize_utf8_characters($filename);
            }
        }
        return $size;
    }
    
    function gh_fileowner($filename) {
        return fileowner($filename);
    }

    function gh_filegroup($filename) {
        return filegroup($filename);
    }

    function gh_fileperms($filename) {
        return mb_substr(decoct(fileperms($filename)), -4);
    }

    function gh_is_file($filename) {
        return is_file($filename);
    }

    function gh_fileinode($filename) {
        // This function returns deviceid_inode to make sure this value will be different for files on different devices.
        $stat = @stat($filename);
        if ($stat === FALSE) {
            return FALSE;
        }
        return $stat['dev'] . '_' . $stat['ino'];
    }

    function gh_file_deviceid($filename) {
        $stat = @stat($filename);
        if ($stat === FALSE) {
            return FALSE;
        }
        return $stat['dev'];
    }

    function gh_rename($filename, $target_filename) {
        return @rename($filename, $target_filename);
    }
}

function gh_symlink($target, $link) {
    return symlink($target, $link);
    # Or, if you have issues with the above, comment it out, and de-comment this one:
    # exec("ln -s " . escapeshellarg($target) . " " . escapeshellarg($link)); return gh_is_file($link);
}

function memory_check() {
    $usage = memory_get_usage();
    $used = $usage / Config::get(CONFIG_MEMORY_LIMIT);
    $used = $used * 100;
    if ($used > 95) {
        Log::critical("$used% memory usage, exiting. Please increase '" . CONFIG_MEMORY_LIMIT . "' in /etc/greyhole.conf");
    }
}

class metafile_iterator implements Iterator {
    private $path;
    private $share;
    private $load_nok_metafiles;
    private $quiet;
    private $check_symlink;
    private $metafiles;
    private $metastores;
    private $dir_handle;

    public function __construct($share, $path, $load_nok_metafiles=FALSE, $quiet=FALSE, $check_symlink=TRUE) {
        $this->quiet = $quiet;
        $this->share = $share;
        $this->path = $path;
        $this->check_symlink = $check_symlink;
        $this->load_nok_metafiles = $load_nok_metafiles;
    }

    public function rewind() {
        $this->metastores = get_metastores();
        $this->directory_stack = array($this->path);
        $this->dir_handle = NULL;
        $this->metafiles = array();
        $this->next();
    }

    public function current() {
        return $this->metafiles;
    }

    public function key() {
        return count($this->metafiles);
    }

    public function next() {
        $this->metafiles = array();
        while(count($this->directory_stack)>0 && $this->directory_stack !== NULL) {
            $this->dir = array_pop($this->directory_stack);
            if (!$this->quiet) {
                Log::debug("Loading metadata files for (dir) " . clean_dir($this->share . (!empty($this->dir) ? "/" . $this->dir : "")) . " ...");
            }
            for( $i = 0; $i < count($this->metastores); $i++ ) {
                $metastore = $this->metastores[$i];
                $this->base = "$metastore/".$this->share."/";
                if(!file_exists($this->base.$this->dir)) {
                    continue;
                }    
                if($this->dir_handle = opendir($this->base.$this->dir)) {
                    while (false !== ($file = readdir($this->dir_handle))) {
                        memory_check();
                        if($file=='.' || $file=='..')
                            continue;
                        if(!empty($this->dir)) {
                            $full_filename = $this->dir . '/' . $file;
                        }else
                            $full_filename = $file;
                        if(is_dir($this->base.$full_filename))
                            $this->directory_stack[] = $full_filename;
                        else{
                            $full_filename = str_replace("$this->path/",'',$full_filename);
                            if(isset($this->metafiles[$full_filename])) {
                                continue;
                            }                        
                            $this->metafiles[$full_filename] = get_metafiles_for_file($this->share, "$this->dir", $file, $this->load_nok_metafiles, $this->quiet, $this->check_symlink);
                        }
                    }
                    closedir($this->dir_handle);
                    $this->directory_stack = array_unique($this->directory_stack);
                }
            }
            if(count($this->metafiles) > 0) {
                break;
            }
            
        }
        if (!$this->quiet) {
            Log::debug('Found ' . count($this->metafiles) . ' metadata files.');
        }
        return $this->metafiles;
    }
    
    public function valid() {
        return count($this->metafiles) > 0;
    }
}

function kshift(&$arr) {
    if (count($arr) == 0) {
        return FALSE;
    }
    foreach ($arr as $k => $v) {
        unset($arr[$k]);
        break;
    }
    return array($k, $v);
}

function kshuffle(&$array) {
    if (!is_array($array)) { return $array; }
    $keys = array_keys($array);
    shuffle($keys);
    $random = array();
    foreach ($keys as $key) {
        $random[$key] = $array[$key];
    }
    $array = $random;
}

class DriveSelection {
    var $num_drives_per_draft;
    var $selection_algorithm;
    var $drives;
    var $is_forced;
    
    var $sorted_target_drives;
    var $last_resort_sorted_target_drives;

    function __construct($num_drives_per_draft, $selection_algorithm, $drives, $is_forced) {
        $this->num_drives_per_draft = $num_drives_per_draft;
        $this->selection_algorithm = $selection_algorithm;
        $this->drives = $drives;
        $this->is_forced = $is_forced;
    }
    
    public function isForced() {
        return $this->is_forced;
    }

    function init(&$sorted_target_drives, &$last_resort_sorted_target_drives) {
        // Sort by used space (asc) for least_used_space, or by available space (desc) for most_available_space
        if ($this->selection_algorithm == 'least_used_space') {
            $sorted_target_drives = $sorted_target_drives['used_space'];
            $last_resort_sorted_target_drives = $last_resort_sorted_target_drives['used_space'];
            asort($sorted_target_drives);
            asort($last_resort_sorted_target_drives);
        } else if ($this->selection_algorithm == 'most_available_space') {
            $sorted_target_drives = $sorted_target_drives['available_space'];
            $last_resort_sorted_target_drives = $last_resort_sorted_target_drives['available_space'];
            arsort($sorted_target_drives);
            arsort($last_resort_sorted_target_drives);
        } else {
            Log::critical("Unknown '" . CONFIG_DRIVE_SELECTION_ALGORITHM . "' found: " . $this->selection_algorithm);
        }
        // Only keep drives that are in $this->drives
        $this->sorted_target_drives = array();
        foreach ($sorted_target_drives as $sp_drive => $space) {
            if (array_contains($this->drives, $sp_drive)) {
                $this->sorted_target_drives[$sp_drive] = $space;
            }
        }
        $this->last_resort_sorted_target_drives = array();
        foreach ($last_resort_sorted_target_drives as $sp_drive => $space) {
            if (array_contains($this->drives, $sp_drive)) {
                $this->last_resort_sorted_target_drives[$sp_drive] = $space;
            }
        }
    }
    
    function draft() {
        $drives = array();
        $drives_last_resort = array();
        
        while (count($drives)<$this->num_drives_per_draft) {
            $arr = kshift($this->sorted_target_drives);
            if ($arr === FALSE) {
                break;
            }
            list($sp_drive, $space) = $arr;
            if (!is_greyhole_owned_drive($sp_drive)) { continue; }
            $drives[$sp_drive] = $space;
        }
        while (count($drives)+count($drives_last_resort)<$this->num_drives_per_draft) {
            $arr = kshift($this->last_resort_sorted_target_drives);
            if ($arr === FALSE) {
                break;
            }
            list($sp_drive, $space) = $arr;
            if (!is_greyhole_owned_drive($sp_drive)) { continue; }
            $drives_last_resort[$sp_drive] = $space;
        }
        
        return array($drives, $drives_last_resort);
    }
    
    static function parse($config_string, $drive_selection_groups) {
        $ds = array();
        if ($config_string == 'least_used_space' || $config_string == 'most_available_space') {
            $ds[] = new DriveSelection(count(Config::storagePoolDrives()), $config_string, Config::storagePoolDrives(), FALSE);
            return $ds;
        }
        if (!preg_match('/forced ?\((.+)\) ?(least_used_space|most_available_space)/i', $config_string, $regs)) {
            Log::critical("Can't understand the '" . CONFIG_DRIVE_SELECTION_ALGORITHM . "' value: $config_string");
        }
        $selection_algorithm = $regs[2];
        $groups = array_map('trim', explode(',', $regs[1]));
        foreach ($groups as $group) {
            $group = explode(' ', preg_replace('/^([0-9]+)x/', '\\1 ', $group));
            $num_drives = trim($group[0]);
            $group_name = trim($group[1]);
            if (!isset($drive_selection_groups[$group_name])) {
                //Log::warn("Warning: drive selection group named '$group_name' is undefined.");
                continue;
            }
            if (stripos(trim($num_drives), 'all') === 0 || $num_drives > count($drive_selection_groups[$group_name])) {
                $num_drives = count($drive_selection_groups[$group_name]);
            }
            $ds[] = new DriveSelection($num_drives, $selection_algorithm, $drive_selection_groups[$group_name], TRUE);
        }
        return $ds;
    }

    function update() {
        // Make sure num_drives_per_draft and drives have been set, in case storage_pool_drive lines appear after drive_selection_algorithm line(s) in the config file
        if (!$this->is_forced && ($this->selection_algorithm == 'least_used_space' || $this->selection_algorithm == 'most_available_space')) {
            $this->num_drives_per_draft = count(Config::storagePoolDrives());
            $this->drives = Config::storagePoolDrives();
        }
    }
}

$greyhole_owned_drives = array();
function is_greyhole_owned_drive($sp_drive) {
    global $going_drive, $greyhole_owned_drives;
    if (isset($going_drive) && $sp_drive == $going_drive) {
        return FALSE;
    }
    $is_greyhole_owned_drive = isset($greyhole_owned_drives[$sp_drive]);
    if ($is_greyhole_owned_drive && $greyhole_owned_drives[$sp_drive] < time() - Config::get(CONFIG_DF_CACHE_TIME)) {
        unset($greyhole_owned_drives[$sp_drive]);
        $is_greyhole_owned_drive = FALSE;
    }
    if (!$is_greyhole_owned_drive) {
        $drives_definitions = Settings::get('sp_drives_definitions', TRUE);
        if (!$drives_definitions) {
            $drives_definitions = MigrationHelper::convertStoragePoolDrivesTagFiles();
        }
        $drive_uuid = gh_dir_uuid($sp_drive);
        $is_greyhole_owned_drive = @$drives_definitions[$sp_drive] === $drive_uuid && $drive_uuid !== FALSE;
        if (!$is_greyhole_owned_drive) {
            // Maybe this is a remote mount? Those don't have UUIDs, so we use the .greyhole_uses_this technique.
            $is_greyhole_owned_drive = file_exists("$sp_drive/.greyhole_uses_this");
            if ($is_greyhole_owned_drive && isset($drives_definitions[$sp_drive])) {
                // This remote drive was listed in MySQL; it shouldn't be. Let's remove it.
                unset($drives_definitions[$sp_drive]);
                Settings::set('sp_drives_definitions', $drives_definitions);
            }
        }
        if ($is_greyhole_owned_drive) {
            $greyhole_owned_drives[$sp_drive] = time();
        }
    }
    return $is_greyhole_owned_drive;
}

// Is it OK for a drive to be gone?
function gone_ok($sp_drive, $refresh=FALSE) {
    global $gone_ok_drives;
    if ($refresh || !isset($gone_ok_drives)) {
        $gone_ok_drives = get_gone_ok_drives();
    }
    if (isset($gone_ok_drives[$sp_drive])) {
        return TRUE;
    }
    return FALSE;
}

function get_gone_ok_drives() {
    global $gone_ok_drives;
    $gone_ok_drives = Settings::get('Gone-OK-Drives', TRUE);
    if (!$gone_ok_drives) {
        $gone_ok_drives = array();
        Settings::set('Gone-OK-Drives', $gone_ok_drives);
    }
    return $gone_ok_drives;
}

function mark_gone_ok($sp_drive, $action='add') {
    if (!array_contains(Config::storagePoolDrives(), $sp_drive)) {
        $sp_drive = '/' . trim($sp_drive, '/');
    }
    if (!array_contains(Config::storagePoolDrives(), $sp_drive)) {
        return FALSE;
    }

    global $gone_ok_drives;
    $gone_ok_drives = get_gone_ok_drives();
    if ($action == 'add') {
        $gone_ok_drives[$sp_drive] = TRUE;
    } else {
        unset($gone_ok_drives[$sp_drive]);
    }

    Settings::set('Gone-OK-Drives', $gone_ok_drives);
    return TRUE;
}

function gone_fscked($sp_drive, $refresh=FALSE) {
    global $fscked_gone_drives;
    if ($refresh || !isset($fscked_gone_drives)) {
        $fscked_gone_drives = get_fsck_gone_drives();
    }
    if (isset($fscked_gone_drives[$sp_drive])) {
        return TRUE;
    }
    return FALSE;
}

function get_fsck_gone_drives() {
    global $fscked_gone_drives;
    $fscked_gone_drives = Settings::get('Gone-FSCKed-Drives', TRUE);
    if (!$fscked_gone_drives) {
        $fscked_gone_drives = array();
        Settings::set('Gone-FSCKed-Drives', $fscked_gone_drives);
    }
    return $fscked_gone_drives;
}

function mark_gone_drive_fscked($sp_drive, $action='add') {
    global $fscked_gone_drives;
    $fscked_gone_drives = get_fsck_gone_drives();
    if ($action == 'add') {
        $fscked_gone_drives[$sp_drive] = TRUE;
    } else {
        unset($fscked_gone_drives[$sp_drive]);
    }

    Settings::set('Gone-FSCKed-Drives', $fscked_gone_drives);
}

function check_storage_pool_drives($skip_fsck=FALSE) {
    global $gone_ok_drives;
    $needs_fsck = FALSE;
    $drives_definitions = Settings::get('sp_drives_definitions', TRUE);
    $returned_drives = array();
    $missing_drives = array();
    $i = 0; $j = 0;
    foreach (Config::storagePoolDrives() as $sp_drive) {
        if (!is_greyhole_owned_drive($sp_drive) && !gone_fscked($sp_drive, $i++ == 0) && !file_exists("$sp_drive/.greyhole_used_this") && !empty($drives_definitions[$sp_drive])) {
            if($needs_fsck !== 2){    
                $needs_fsck = 1;
            }
            mark_gone_drive_fscked($sp_drive);
            $missing_drives[] = $sp_drive;
            Log::warn("Warning! It seems the partition UUID of $sp_drive changed. This probably means this mount is currently unmounted, or that you replaced this drive and didn't use 'greyhole --replace'. Because of that, Greyhole will NOT use this drive at this time.");
            Log::debug("Email sent for gone drive: $sp_drive");
            $gone_ok_drives[$sp_drive] = TRUE; // The upcoming fsck should not recreate missing copies just yet
        } else if ((gone_ok($sp_drive, $j++ == 0) || gone_fscked($sp_drive, $i++ == 0)) && is_greyhole_owned_drive($sp_drive) && !empty($drives_definitions[$sp_drive])) {
            // $sp_drive is now back
            $needs_fsck = 2;
            $returned_drives[] = $sp_drive;
            Log::debug("Email sent for revived drive: $sp_drive");

            mark_gone_ok($sp_drive, 'remove');
            mark_gone_drive_fscked($sp_drive, 'remove');
            $i = 0; $j = 0;
        }
    }
    if(count($returned_drives) > 0) {
        $body = "This is an automated email from Greyhole.\n\nOne (or more) of your storage pool drives came back:\n";
        foreach ($returned_drives as $sp_drive) {
              $body .= "$sp_drive was missing; it's now available again.\n";
        }
        if (!$skip_fsck) {
            $body .= "\nA fsck will now start, to fix the symlinks found in your shares, when possible.\nYou'll receive a report email once that fsck run completes.\n";
        }
        $drive_string = join(", ", $returned_drives);
        $subject = "Storage pool drive now online on " . exec ('hostname') . ": ";
        $subject = $subject . $drive_string;
        if (strlen($subject) > 255) {
            $subject = substr($subject, 0, 255);
        }
        mail(Config::get(CONFIG_EMAIL_TO), $subject, $body);
    }
    if(count($missing_drives) > 0) {
        $body = "This is an automated email from Greyhole.\n\nOne (or more) of your storage pool drives has disappeared:\n";

        foreach ($missing_drives as $sp_drive) {
            if (!is_dir($sp_drive)) {
                  $body .= "$sp_drive: directory doesn't exists\n";
            } else {
                $current_uuid = gh_dir_uuid($sp_drive);
                if (empty($current_uuid)) {
                    $current_uuid = 'N/A';
                }
                $body .= "$sp_drive: expected partition UUID: " . $drives_definitions[$sp_drive] . "; current partition UUID: $current_uuid\n";
            }
        }
        $sp_drive = $missing_drives[0];
        $body .= "\nThis either means this mount is currently unmounted, or you forgot to use 'greyhole --replace' when you changed this drive.\n\n";
        $body .= "Here are your options:\n\n";
        $body .= "- If you forgot to use 'greyhole --replace', you should do so now. Until you do, this drive will not be part of your storage pool.\n\n";
        $body .= "- If the drive is gone, you should either re-mount it manually (if possible), or remove it from your storage pool. To do so, use the following command:\n  greyhole --gone=" . escapeshellarg($sp_drive) . "\n  Note that the above command is REQUIRED for Greyhole to re-create missing file copies before the next fsck runs. Until either happens, missing file copies WILL NOT be re-created on other drives.\n\n";
        $body .= "- If you know this drive will come back soon, and do NOT want Greyhole to re-create missing file copies for this drive until it reappears, you should execute this command:\n  greyhole --wait-for=" . escapeshellarg($sp_drive) . "\n\n";
        if (!$skip_fsck) {
            $body .= "A fsck will now start, to fix the symlinks found in your shares, when possible.\nYou'll receive a report email once that fsck run completes.\n";
        }
        $subject = "Missing storage pool drives on " . exec('hostname') . ": ";
        $drive_string = join(",",$missing_drives);
        $subject = $subject . $drive_string;
        if (strlen($subject) > 255) {
            $subject = substr($subject, 0, 255);
        }
        mail(Config::get(CONFIG_EMAIL_TO), $subject, $body);
    }
    if ($needs_fsck !== FALSE) {
        set_metastore_backup();
        get_metastores(FALSE); // FALSE => Resets the metastores cache
        clearstatcache();

        if (!$skip_fsck) {
            initialize_fsck_report('All shares');
            if ($needs_fsck === 2) {
                foreach ($returned_drives as $drive) {
                    $metastores = get_metastores_from_storage_volume($drive);
                    Log::info("Starting fsck for metadata store on $drive which came back online.");
                    foreach ($metastores as $metastore) {
                        foreach (SharesConfig::getShares() as $share_name => $share_options) {
                            gh_fsck_metastore($metastore,"/$share_name", $share_name);
                        }
                    }
                    Log::info("fsck for returning drive $drive's metadata store completed.");
                }
                Log::info("Starting fsck for all shares - caused by missing drive that came back online.");
            } else {
                Log::info("Starting fsck for all shares - caused by missing drive. Will just recreate symlinks to existing copies when possible; won't create new copies just yet.");
                fix_all_symlinks();
            }
            schedule_fsck_all_shares(array('email'));
            Log::info("  fsck for all shares scheduled.");
        }

        // Refresh $gone_ok_drives to it's real value (from the DB)
        get_gone_ok_drives();
    }
}

class FSCKLogFile {
    const PATH = '/usr/share/greyhole';

    private $path;
    private $filename;
    private $lastEmailSentTime = 0;
    
    public function __construct($filename, $path=self::PATH) {
        $this->filename = $filename;
        $this->path = $path;
    }
    
    public function emailAsRequired() {
        $logfile = "$this->path/$this->filename";
        if (!file_exists($logfile)) { return; }

        $last_mod_date = filemtime($logfile);
        if ($last_mod_date > $this->getLastEmailSentTime()) {
            $email_to = Config::get(CONFIG_EMAIL_TO);
            Log::warn("Sending $logfile by email to $email_to");
            mail($email_to, $this->getSubject(), $this->getBody());

            $this->lastEmailSentTime = $last_mod_date;
            Settings::set("last_email_$this->filename", $this->lastEmailSentTime);
        }
    }

    private function getBody() {
        $logfile = "$this->path/$this->filename";
        if ($this->filename == 'fsck_checksums.log') {
            return file_get_contents($logfile) . "\nNote: You should manually delete the $logfile file once you're done with it.";
        } else if ($this->filename == 'fsck_files.log') {
            global $fsck_report;
            $fsck_report = unserialize(file_get_contents($logfile));
            unlink($logfile);
            return get_fsck_report() . "\nNote: This report is a complement to the last report you've received. It details possible errors with files for which the fsck was postponed.";
        } else {
            return '[empty]';
        }
    }
    
    private function getSubject() {
        if ($this->filename == 'fsck_checksums.log') {
            return 'Mismatched checksums in Greyhole file copies';
        } else if ($this->filename == 'fsck_files.log') {
            return 'fsck_files of Greyhole shares on ' . exec('hostname');
        } else {
            return 'Unknown FSCK report';
        }
    }
    
    private function getLastEmailSentTime() {
        if ($this->lastEmailSentTime == 0) {
            $setting = Settings::get("last_email_$this->filename");
            if ($setting) {
                $this->lastEmailSentTime = (int) $setting;
            }
        }
        return $this->lastEmailSentTime;
    }
    
    public static function loadFSCKReport($what) {
        $logfile = self::PATH . '/fsck_files.log';
        if (file_exists($logfile)) {
            global $fsck_report;
            $fsck_report = unserialize(file_get_contents($logfile));
        } else {
            initialize_fsck_report($what);
        }
    }

    public static function saveFSCKReport() {
        global $fsck_report;
        $logfile = self::PATH . '/fsck_files.log';
        file_put_contents($logfile, serialize($fsck_report));
    }
}

function gh_dir_uuid($dir) {
    $dev = exec('df ' . escapeshellarg($dir) . ' 2> /dev/null | grep \'/dev\' | awk \'{print $1}\'');
    if (!is_dir($dir)) {
        return FALSE;
    }
    if (empty($dev) || strpos($dev, '/dev/') !== 0) {
        // ZFS pool maybe?
        if (file_exists('/sbin/zpool')) {
            $dataset = exec('df ' . escapeshellarg($dir) . ' 2> /dev/null | awk \'{print $1}\'');
            if (strpos($dataset, '/') !== FALSE) {
                $is_zfs = exec('mount | grep ' . escapeshellarg("$dataset .*zfs") . ' 2> /dev/null | wc -l');
                if ($is_zfs == 1) {
                    $p = explode('/', $dataset);
                    $pool = $p[0];
                    $dev_name = exec('/sbin/zpool list -v ' . escapeshellarg($pool) . ' 2> /dev/null | awk \'{print $1}\' | tail -n 1');
                    if (!empty($dev_name)) {
                        $dev = exec("ls -l /dev/disk/*/$dev_name | awk '{print \$(NF-2)}'");
                        if (empty($dev) && file_exists("/dev/$dev_name")) {
                            $dev = '/dev/$dev_name';
                            Log::info("Found a ZFS pool ($pool) that uses a device name in /dev/ ($dev). That is a bad idea, since those can easily change, which would prevent this pool from mounting automatically. You should use any of the /dev/disk/*/ links instead. For example, you could do: zpool export $pool && zpool import -d /dev/disk/by-id/ $pool. More details at http://zfsonlinux.org/faq.html#WhatDevNamesShouldIUseWhenCreatingMyPool");
                        }
                    }
                    if (empty($dev)) {
                        Log::warn("Warning! Couldn't find the device used by your ZFS pool name $pool. That pool will never be used.");
                        return FALSE;
                    }
                }
            }
        }
        if (empty($dev)) {
            return 'remote';
        }
    }
    $uuid = trim(exec('/sbin/blkid '.$dev.' | awk -F\'UUID="\' \'{print $2}\' | awk -F\'"\' \'{print $1}\''));
    if (empty($uuid)) {
        return 'remote';
    }
    return $uuid;
}

function fix_all_symlinks() {
    foreach (SharesConfig::getShares() as $share_name => $share_options) {
        fix_symlinks_on_share($share_name);
    }
}

function fix_symlinks_on_share($share_name) {
    $share_options = SharesConfig::getConfigForShare($share_name);
    echo "Looking for broken symbolic links in the share '$share_name'...";
    chdir($share_options[CONFIG_LANDING_ZONE]);
    exec("find -L . -type l", $result);
    foreach ($result as $file_to_relink) {
        if (is_link($file_to_relink)) {
            $file_to_relink = substr($file_to_relink, 2);
            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (!is_greyhole_owned_drive($sp_drive)) { continue; }
                $new_link_target = clean_dir("$sp_drive/$share_name/$file_to_relink");
                if (gh_is_file($new_link_target)) {
                    unlink($file_to_relink);
                    gh_symlink($new_link_target, $file_to_relink);
                    break;
                }
            }
        }
    }
    echo " Done.\n";
}

function schedule_fsck_all_shares($fsck_options=array()) {
    foreach (SharesConfig::getShares() as $share_name => $share_options) {
        $query = "INSERT INTO tasks SET action = 'fsck', share = :full_path, additional_info = :fsck_options, complete = 'yes'";
        $params = array(
            'full_path' => $share_options[CONFIG_LANDING_ZONE],
            'fsck_options' => empty($fsck_options) ? NULL : implode('|', $fsck_options)
        );
        DB::insert($query, $params);
    }
}

function array_contains($haystack, $needle) {
    return array_search($needle, $haystack) !== FALSE;
}

function string_contains($haystack, $needle) {
    return mb_strpos($haystack, $needle) !== FALSE;
}

function string_starts_with($haystack, $needle) {
    return mb_strpos($haystack, $needle) === 0;
}

function is_amahi() {
    return file_exists('/usr/bin/hda-ctl');
}

function json_pretty_print($json) {
    if (!is_string($json)) {
        $json = json_encode($json);
    }
    $result = '';
    $level = 0;
    $in_quotes = FALSE;
    $in_escape = FALSE;
    $ends_line_level = NULL;
    $json_length = strlen( $json );
    for ($i = 0; $i < $json_length; $i++) {
        $char = $json[$i];
        $new_line_level = NULL;
        $post = "";
        if ($ends_line_level !== NULL) {
            $new_line_level = $ends_line_level;
            $ends_line_level = NULL;
        }
        if ($in_escape) {
            $in_escape = FALSE;
        } else if ($char === '"') {
            $in_quotes = !$in_quotes;
        } else if (!$in_quotes) {
            switch($char) {
                case '}':
                case ']':
                    $level--;
                    $ends_line_level = NULL;
                    $new_line_level = $level;
                    break;
                case '{':
                case '[':
                    $level++;
                case ',':
                    $ends_line_level = $level;
                    break;
                case ':':
                    $post = " ";
                    break;

                case " ":
                case "\t":
                case "\n":
                case "\r":
                    $char = "";
                    $ends_line_level = $new_line_level;
                    $new_line_level = NULL;
                    break;
            }
        } else if ($char === '\\') {
            $in_escape = true;
        }
        if ($new_line_level !== NULL) {
            $result .= "\n" . str_repeat("  ", $new_line_level);
        }
        $result .= $char . $post;
    }
    return $result;
}

?>
