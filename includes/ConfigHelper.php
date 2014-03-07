<?php
/*
Copyright 2014 Guillaume Boudreau

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

define('CONFIG_LOG_LEVEL', 'log_level');
define('CONFIG_DELETE_MOVES_TO_TRASH', 'delete_moves_to_trash');
define('CONFIG_LOG_MEMORY_USAGE', 'log_memory_usage');
define('CONFIG_CHECK_FOR_OPEN_FILES', 'check_for_open_files');
define('CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE', 'allow_multiple_sp_per_device');
define('CONFIG_STORAGE_POOL_DRIVE', 'storage_pool_drive');
define('CONFIG_MIN_FREE_SPACE_POOL_DRIVE', 'min_free_space_pool_drive');
define('CONFIG_STICKY_FILES', 'sticky_files');
define('CONFIG_STICK_INTO', 'stick_into');
define('CONFIG_FROZEN_DIRECTORY', 'frozen_directory');
define('CONFIG_MEMORY_LIMIT', 'memory_limit');
define('CONFIG_TIMEZONE', 'timezone');
define('CONFIG_DRIVE_SELECTION_GROUPS', 'drive_selection_groups');
define('CONFIG_DRIVE_SELECTION_ALGORITHM', 'drive_selection_algorithm');
define('CONFIG_IGNORED_FILES', 'ignored_files');
define('CONFIG_IGNORED_FOLDERS', 'ignored_folders');
define('CONFIG_NUM_COPIES', 'num_copies');
define('CONFIG_LANDING_ZONE', 'landing_zone');
define('CONFIG_MAX_QUEUED_TASKS', 'max_queued_tasks');
define('CONFIG_EXECUTED_TASKS_RETENTION', 'executed_tasks_retention');
define('CONFIG_GREYHOLE_LOG_FILE', 'greyhole_log_file');
define('CONFIG_GREYHOLE_ERROR_LOG_FILE', 'greyhole_error_log_file');
define('CONFIG_EMAIL_TO', 'email_to');
define('CONFIG_DF_CACHE_TIME', 'df_cache_time');
define('CONFIG_DB_HOST', 'db_host');
define('CONFIG_DB_USER', 'db_user');
define('CONFIG_DB_PASS', 'db_pass');
define('CONFIG_DB_NAME', 'db_name');
define('CONFIG_METASTORE_BACKUPS', 'metastore_backups');
define('CONFIG_TRASH_SHARE', '===trash_share===');

class ConfigHelper {
    static $config_file = '/etc/greyhole.conf';
    static $smb_config_file = '/etc/samba/smb.conf';
    static $trash_share_names = array('Greyhole Attic', 'Greyhole Trash', 'Greyhole Recycle Bin');
    static $df_command;

    public static function removeShare($share) {
        exec("/bin/sed -i 's/^.*num_copies\[".$share."\].*$//' " . escapeshellarg(static::$config_file));
    }

    public static function removeStoragePoolDrive($sp_drive) {
        $escaped_drive = str_replace('/', '\/', $sp_drive);
        exec("/bin/sed -i 's/^.*storage_pool_directory.*$escaped_drive.*$//' " . escapeshellarg(static::$config_file)); // Deprecated notation
        exec("/bin/sed -i 's/^.*storage_pool_drive.*$escaped_drive.*$//' " . escapeshellarg(static::$config_file));
    }

    public static function randomStoragePoolDrive() {
        $storage_pool_drives = (array) Config::storagePoolDrives();
        return $storage_pool_drives[array_rand($storage_pool_drives)];
    }

    public static function parse() {
        $deprecated_options = array(
            'delete_moves_to_attic' => CONFIG_DELETE_MOVES_TO_TRASH,
            'storage_pool_directory' => CONFIG_STORAGE_POOL_DRIVE,
            'dir_selection_groups' => CONFIG_DRIVE_SELECTION_GROUPS,
            'dir_selection_algorithm' => CONFIG_DRIVE_SELECTION_ALGORITHM,
        );

        $parsing_drive_selection_groups = FALSE;
        $config_text = recursive_include_parser(static::$config_file);

        foreach (explode("\n", $config_text) as $line) {
            if (preg_match("/^[ \t]*([^=\t]+)[ \t]*=[ \t]*([^#]+)/", $line, $regs)) {
                $name = trim($regs[1]);
                $value = trim($regs[2]);
                if ($name[0] == '#') {
                    continue;
                }

                foreach ($deprecated_options as $old_name => $new_name) {
                    if (string_contains($name, $old_name)) {
                        $fixed_name = str_replace($old_name, $new_name, $name);
                        Log::warn("Deprecated option found in greyhole.conf: $name. You should change that to: $fixed_name");
                        $name = $fixed_name;
                    }
                }

                $parsing_drive_selection_groups = FALSE;
                switch($name) {
                    // Log level
                    case CONFIG_LOG_LEVEL:
                        static::assert(defined("Log::$value"), "Invalid value for log_level: '$value'");
                        Config::set(CONFIG_LOG_LEVEL, constant("Log::$value"));
                        break;

                    // Booleans
                    case CONFIG_DELETE_MOVES_TO_TRASH:
                    case CONFIG_LOG_MEMORY_USAGE:
                    case CONFIG_CHECK_FOR_OPEN_FILES:
                    case CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE:
                        $bool = trim($value) === '1' || mb_stripos($value, 'yes') !== FALSE || mb_stripos($value, 'true') !== FALSE;
                        Config::set($name, $bool);
                        break;

                    // Storage pool drives
                    case CONFIG_STORAGE_POOL_DRIVE:
                        if (preg_match("/(.*) ?, ?min_free ?: ?([0-9]+) ?([gmk])b?/i", $value, $regs)) {
                            $sp_drive = '/' . trim(trim($regs[1]), '/');
                            Config::add(CONFIG_STORAGE_POOL_DRIVE, $sp_drive);

                            $units = strtolower($regs[3]);
                            if ($units == 'g') {
                                $value = (float) trim($regs[2]) * 1024.0 * 1024.0;
                            } else if ($units == 'm') {
                                $value = (float) trim($regs[2]) * 1024.0;
                            } else if ($units == 'k') {
                                $value = (float) trim($regs[2]);
                            }
                            Config::add(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $value, $sp_drive);
                        }
                        break;

                    // Sticky files
                    case CONFIG_STICKY_FILES:
                        $last_sticky_files_dir = trim($value, '/');
                        Config::add(CONFIG_STICKY_FILES, array(), $last_sticky_files_dir);
                        break;
                    case CONFIG_STICK_INTO:
                        $sticky_files = Config::get(CONFIG_STICKY_FILES);
                        $sticky_files[$last_sticky_files_dir][] = '/' . trim($value, '/');
                        Config::set(CONFIG_STICKY_FILES, $sticky_files);
                        break;

                    // Frozen directories
                    case CONFIG_FROZEN_DIRECTORY:
                        Config::add(CONFIG_FROZEN_DIRECTORY, trim($value, '/'));
                        break;

                    // Drive selection algorithms & groups
                    case CONFIG_DRIVE_SELECTION_GROUPS:
                        if (preg_match("/(.+):(.*)/", $value, $regs)) {
                            $group_name = trim($regs[1]);
                            $group_definition = array_map('trim', explode(',', $regs[2]));
                            Config::add(CONFIG_DRIVE_SELECTION_GROUPS, $group_definition, $group_name);
                            $parsing_drive_selection_groups = TRUE;
                        }
                        break;
                    case CONFIG_DRIVE_SELECTION_ALGORITHM:
                        Config::set(CONFIG_DRIVE_SELECTION_ALGORITHM, DriveSelection::parse($value, Config::get(CONFIG_DRIVE_SELECTION_GROUPS)));
                        break;

                    // Ignored files, folders
                    case CONFIG_IGNORED_FILES:
                        Config::add(CONFIG_IGNORED_FILES, $value);
                        break;
                    case CONFIG_IGNORED_FOLDERS:
                        Config::add(CONFIG_IGNORED_FOLDERS, $value);
                        break;

                    case CONFIG_MAX_QUEUED_TASKS:
                    case CONFIG_EXECUTED_TASKS_RETENTION:
                    case CONFIG_DF_CACHE_TIME:
                        if (is_numeric($value)) {
                            $value = (int) $value;
                        }
                        // Fall through

                    case CONFIG_DB_HOST:
                    case CONFIG_DB_USER:
                    case CONFIG_DB_PASS:
                    case CONFIG_DB_NAME:
                    case CONFIG_EMAIL_TO:
                    case CONFIG_GREYHOLE_LOG_FILE:
                    case CONFIG_GREYHOLE_ERROR_LOG_FILE:
                    case CONFIG_TIMEZONE:
                    case CONFIG_MEMORY_LIMIT:
                        Config::set($name, $value);
                        break;

                    default:
                        if (string_starts_with($name, CONFIG_NUM_COPIES)) {
                            $share = mb_substr($name, 11, mb_strlen($name)-12);
                            if (mb_stripos($value, 'max') === 0) {
                                $value = 9999;
                            }
                            SharesConfig::set($share, CONFIG_NUM_COPIES, (int) $value);
                        } else if (string_starts_with($name, CONFIG_DELETE_MOVES_TO_TRASH)) {
                            $share = mb_substr($name, 22, mb_strlen($name)-23);
                            $value = strtolower(trim($value));
                            $bool = trim($value) === '1' || mb_stripos($value, 'yes') !== FALSE || mb_stripos($value, 'true') !== FALSE;
                            SharesConfig::set($share, CONFIG_DELETE_MOVES_TO_TRASH, $bool);
                        } else if (string_starts_with($name, CONFIG_DRIVE_SELECTION_GROUPS)) {
                            $share = mb_substr($name, 23, mb_strlen($name)-24);
                            if (preg_match("/(.+):(.+)/", $value, $regs)) {
                                $group_name = trim($regs[1]);
                                $group_definition = array_map('trim', explode(',', $regs[2]));
                                SharesConfig::add($share, CONFIG_DRIVE_SELECTION_GROUPS, $group_definition, $group_name);
                                $parsing_drive_selection_groups = $share;
                            }
                        } else if (string_starts_with($name, CONFIG_DRIVE_SELECTION_ALGORITHM)) {
                            $share = mb_substr($name, 26, mb_strlen($name)-27);
                            if (SharesConfig::get($share, CONFIG_DRIVE_SELECTION_GROUPS) === FALSE) {
                                SharesConfig::set($share, CONFIG_DRIVE_SELECTION_GROUPS, Config::get(CONFIG_DRIVE_SELECTION_GROUPS));
                            }
                            SharesConfig::set($share, CONFIG_DRIVE_SELECTION_ALGORITHM, DriveSelection::parse($value, SharesConfig::get($share, CONFIG_DRIVE_SELECTION_GROUPS)));
                        } else {
                            if (is_numeric($value)) {
                                $value = (int) $value;
                            }
                            Config::set($name, $value);
                        }
                }
            } else if ($parsing_drive_selection_groups !== FALSE) {
                $value = trim($line);
                if (strlen($value) == 0 || $value[0] == '#') {
                    continue;
                }
                if (preg_match("/(.+):(.+)/", $value, $regs)) {
                    $group_name = trim($regs[1]);
                    $drives = array_map('trim', explode(',', $regs[2]));
                    if (is_string($parsing_drive_selection_groups)) {
                        $share = $parsing_drive_selection_groups;
                        SharesConfig::add($share, CONFIG_DRIVE_SELECTION_GROUPS, $drives, $group_name);
                    } else {
                        Config::add(CONFIG_DRIVE_SELECTION_GROUPS, $drives, $group_name);
                    }
                }
            }
        }

        Log::setLevel(Config::get(CONFIG_LOG_LEVEL));

        if (count(Config::storagePoolDrives()) == 0) {
            Log::error("You have no '" . CONFIG_STORAGE_POOL_DRIVE . "' defined. Greyhole can't run.");
            return FALSE;
        }

        static::$df_command = "df -k";
        foreach (Config::storagePoolDrives() as $sp_drive) {
            static::$df_command .= " " . escapeshellarg($sp_drive);
        }
        static::$df_command .= " 2>&1 | grep '%' | grep -v \"^df: .*: No such file or directory$\"";

        exec('testparm -s ' . escapeshellarg(static::$smb_config_file) . ' 2> /dev/null', $config_text);
        foreach ($config_text as $line) {
            $line = trim($line);
            if (mb_strlen($line) == 0) { continue; }
            if ($line[0] == '[' && preg_match('/\[([^\]]+)\]/', $line, $regs)) {
                $share_name = $regs[1];
            }
            if (isset($share_name) && !SharesConfig::exists($share_name) && !array_contains(static::$trash_share_names, $share_name)) { continue; }
            if (isset($share_name) && preg_match('/^\s*path[ \t]*=[ \t]*(.+)$/i', $line, $regs)) {
                SharesConfig::set($share_name, CONFIG_LANDING_ZONE, '/' . trim($regs[1], '/"'));
                SharesConfig::set($share_name, 'name', $share_name);
            }
        }

        $drive_selection_algorithm = Config::get(CONFIG_DRIVE_SELECTION_ALGORITHM);
        if (!empty($drive_selection_algorithm)) {
            foreach ($drive_selection_algorithm as $ds) {
                $ds->update();
            }
        } else {
            // Default drive_selection_algorithm
            $drive_selection_algorithm = DriveSelection::parse('most_available_space', null);
        }
        Config::set(CONFIG_DRIVE_SELECTION_ALGORITHM, $drive_selection_algorithm);

        foreach (SharesConfig::getShares() as $share_name => $share_options) {
            if (array_contains(static::$trash_share_names, $share_name)) {
                SharesConfig::set(CONFIG_TRASH_SHARE, 'name', $share_name);
                SharesConfig::set(CONFIG_TRASH_SHARE, CONFIG_LANDING_ZONE, SharesConfig::get($share_name, CONFIG_LANDING_ZONE));
                SharesConfig::removeShare($share_name);
                continue;
            }
            if ($share_options[CONFIG_NUM_COPIES] > count(Config::storagePoolDrives())) {
                SharesConfig::set($share_name, CONFIG_NUM_COPIES, count(Config::storagePoolDrives()));
            }
            if (!isset($share_options[CONFIG_LANDING_ZONE])) {
                Log::warn("Found a share ($share_name) defined in " . static::$config_file . " with no path in " . static::$smb_config_file . ". Either add this share in " . static::$smb_config_file . ", or remove it from " . static::$config_file . ", then restart Greyhole.");
                return FALSE;
            }
            if (!isset($share_options[CONFIG_DELETE_MOVES_TO_TRASH])) {
                SharesConfig::set($share_name, CONFIG_DELETE_MOVES_TO_TRASH, Config::get(CONFIG_DELETE_MOVES_TO_TRASH));
            }
            if (isset($share_options[CONFIG_DRIVE_SELECTION_ALGORITHM])) {
                foreach ($share_options[CONFIG_DRIVE_SELECTION_ALGORITHM] as $ds) {
                    $ds->update();
                }
            } else {
                SharesConfig::set($share_name, CONFIG_DRIVE_SELECTION_ALGORITHM, $drive_selection_algorithm);
            }
            if (isset($share_options[CONFIG_DRIVE_SELECTION_GROUPS])) {
                SharesConfig::remove($share_name, CONFIG_DRIVE_SELECTION_GROUPS);
            }

            // Validate that the landing zone is NOT a subdirectory of a storage pool drive, and that storage pool drives are not subdirectories of the landing zone!
            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (string_starts_with($share_options[CONFIG_LANDING_ZONE], $sp_drive)) {
                    Log::critical("Found a share ($share_name), with path " . $share_options[CONFIG_LANDING_ZONE] . ", which is INSIDE a storage pool drive ($sp_drive). Share directories should never be inside a directory that you have in your storage pool.\nFor your shares to use your storage pool, you just need them to have 'vfs objects = greyhole' in their (smb.conf) config; their location on your file system is irrelevant.");
                }
                if (string_starts_with($sp_drive, $share_options[CONFIG_LANDING_ZONE])) {
                    Log::critical("Found a storage pool drive ($sp_drive), which is INSIDE a share landing zone (" . $share_options[CONFIG_LANDING_ZONE] . "), for share $share_name. Storage pool drives should never be inside a directory that you use as a share landing zone ('path' in smb.conf).\nFor your shares to use your storage pool, you just need them to have 'vfs objects = greyhole' in their (smb.conf) config; their location on your file system is irrelevant.");
                }
            }
        }

        // Check that all drives are included in at least one $drive_selection_algorithm
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $found = FALSE;
            foreach (SharesConfig::getShares() as $share_name => $share_options) {
                foreach ($share_options[CONFIG_DRIVE_SELECTION_ALGORITHM] as $ds) {
                    if (array_contains($ds->drives, $sp_drive)) {
                        $found = TRUE;
                    }
                }
            }
            if (!$found) {
                Log::warn("The storage pool drive '$sp_drive' is not part of any drive_selection_algorithm definition, and will thus never be used to receive any files.");
            }
        }

        $memory_limit = Config::get(CONFIG_MEMORY_LIMIT);
        ini_set('memory_limit', $memory_limit);
        if (preg_match('/G$/i',$memory_limit)) {
            $memory_limit = preg_replace('/G$/i','',$memory_limit);
            $memory_limit = $memory_limit * 1024 * 1024 * 1024;
        } else if (preg_match('/M$/i',$memory_limit)) {
            $memory_limit = preg_replace('/M$/i','',$memory_limit);
            $memory_limit = $memory_limit * 1024 * 1024;
        } else if (preg_match('/K$/i',$memory_limit)) {
            $memory_limit = preg_replace('/K$/i','',$memory_limit);
            $memory_limit = $memory_limit * 1024;
        }
        Config::set(CONFIG_MEMORY_LIMIT, $memory_limit);

        $tz = Config::get(CONFIG_TIMEZONE);
        if (empty($tz)) {
            $tz = @date_default_timezone_get();
        }
        date_default_timezone_set($tz);

        $db_options = array(
            'engine' => 'mysql',
            'schema' => "/usr/share/greyhole/schema-mysql.sql",
            'host' => Config::get(CONFIG_DB_HOST),
            'user' => Config::get(CONFIG_DB_USER),
            'pass' => Config::get(CONFIG_DB_PASS),
            'name' => Config::get(CONFIG_DB_NAME),
        );

        DB::setOptions($db_options);

        if (strtolower(Config::get(CONFIG_GREYHOLE_LOG_FILE)) == 'syslog') {
            openlog("Greyhole", LOG_PID, LOG_USER);
        }

        return TRUE;
    }

    private static function assert($check, $error_message) {
        if ($check === FALSE) {
            Log::critical($error_message);
        }
    }
}

class Config {
    // Defaults
    public static $config = array(
        CONFIG_LOG_LEVEL                   => Log::DEBUG,
        CONFIG_DELETE_MOVES_TO_TRASH       => TRUE,
        CONFIG_LOG_MEMORY_USAGE            => FALSE,
        CONFIG_CHECK_FOR_OPEN_FILES        => TRUE,
        CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE => FALSE,
        CONFIG_STORAGE_POOL_DRIVE          => array(),
        CONFIG_MIN_FREE_SPACE_POOL_DRIVE   => array(),
        CONFIG_STICKY_FILES                => array(),
        CONFIG_FROZEN_DIRECTORY            => array(),
        CONFIG_MEMORY_LIMIT                => '128M',
        CONFIG_TIMEZONE                    => FALSE,
        CONFIG_DRIVE_SELECTION_GROUPS      => array(),
        CONFIG_IGNORED_FILES               => array(),
        CONFIG_IGNORED_FOLDERS             => array(),
        CONFIG_MAX_QUEUED_TASKS            => 10000000,
        CONFIG_EXECUTED_TASKS_RETENTION    => 60,
        CONFIG_GREYHOLE_LOG_FILE           => '/var/log/greyhole.log',
        CONFIG_GREYHOLE_ERROR_LOG_FILE     => FALSE,
        CONFIG_EMAIL_TO                    => 'root',
        CONFIG_DF_CACHE_TIME               => 15,
    );

    /**
     * @return mixed
     */
    public static function get($name, $index=NULL) {
        if ($index === NULL) {
            return isset(static::$config[$name]) ? static::$config[$name] : FALSE;
        } else {
            return isset(static::$config[$name][$index]) ? static::$config[$name][$index] : FALSE;
        }
    }

    /**
     * @return array
     */
    public static function storagePoolDrives() {
        return static::get(CONFIG_STORAGE_POOL_DRIVE);
    }

    public static function set($name, $value) {
        static::$config[$name] = $value;
    }

    public static function add($name, $value, $index=NULL) {
        if ($index === NULL) {
            static::$config[$name][] = $value;
        } else {
            static::$config[$name][$index] = $value;
        }
    }
}

class SharesConfig {
    private static $shares_config;

    private static function _getConfig($share) {
        if (!static::exists($share)) {
            static::$shares_config[$share] = array();
        }
        return static::$shares_config[$share];
    }

    public static function exists($share) {
        return isset(static::$shares_config[$share]);
    }

    public static function getShares() {
        $result = array();
        foreach (static::$shares_config as $share_name => $share_config) {
            if ($share_name != CONFIG_TRASH_SHARE) {
                $result[$share_name] = $share_config;
            }
        }
        return $result;
    }

    public static function getConfigForShare($share) {
        if (!static::exists($share)) {
            return FALSE;
        }
        return static::$shares_config[$share];
    }

    public static function removeShare($share) {
        unset(static::$shares_config[$share]);
    }

    public static function remove($share, $name) {
        unset(static::$shares_config[$share][$name]);
    }

    public static function get($share, $name, $index=NULL) {
        if (!static::exists($share)) {
            return FALSE;
        }
        $config = static::$shares_config[$share];
        if ($index === NULL) {
            return isset($config[$name]) ? $config[$name] : FALSE;
        } else {
            return isset($config[$name][$index]) ? $config[$name][$index] : FALSE;
        }
    }

    public static function set($share, $name, $value) {
        $config = static::_getConfig($share);
        $config[$name] = $value;
        static::$shares_config[$share] = $config;
    }

    public static function add($share, $name, $value, $index=NULL) {
        $config = static::_getConfig($share);
        if ($index === NULL) {
            $config[$name][] = $value;
        } else {
            $config[$name][$index] = $value;
        }
        static::$shares_config[$share] = $config;
    }
}

?>
