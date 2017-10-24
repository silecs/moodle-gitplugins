<?php

/**
 * @copyright  2017 Silecs {@link http://www.silecs.info/societe}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', true);
define('RETURN_OK', 0);
define('RETURN_ERROR', 1);

$plugins = require_once('gitplugins.conf');
$rootdir = dirname(dirname(__DIR__));   // assuming the script is in admin/cli
require($rootdir . '/config.php');        // global moodle config file.
require_once($CFG->libdir . '/clilib.php');      // cli only functions
// now get cli options
list($options, $unrecognized) = cli_get_params(
        ['help' => false, 'verb' => 1,
    'diag' => false, 'status' => false, 'check' => false,
    'install' => false, 'upgrade' => false, 'cleanup' => false], ['h' => 'help']);
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

$help = "Plugin installation or upgrade via Git, as declared in gitplugins.conf

Options:
-h, --help            Print out this help

--check             Check the consistency of 'gitplugins.conf'
--diag              Diagnostic of all declared plugins
--status            Git status on each declared plugin
--install           Install all plugins that are not already present
--upgrade           Upgrade all plugins already installed
--cleanup           Remove all plugins in an inconsistent state (by RENAMING them so restoration is possible)
    --verb=N        Verbosity (0 or 1), 1 by default
";


if (!empty($options['help'])) {
    echo $help;
    return 0;
}


$pCollection = plugin::initCollection($plugins, $options['verb']);
foreach ($pCollection as $plugin) {
    $plugin->diagnostic();
}


if ($options['diag']) {
    plugin::display_diagnostic($pCollection);
    return RETURN_OK;
}

if ($options['install']) {
    foreach ($pCollection as $plugin) {
        echo "\n" . term($plugin->name, 'bold') . "...\n";
        $plugin->install();
    }
    return RETURN_OK;
}

if ($options['upgrade']) {
    foreach ($pCollection as $plugin) {
        echo "\n" . term($plugin->name, 'bold') . "...\n";
        $plugin->upgrade();
    }
    return RETURN_OK;
}

if ($options['status']) {
    foreach ($pCollection as $plugin) {
        echo "\n" . term($plugin->name, 'bold') . "...\n";
        $plugin->status();
    }
    return RETURN_OK;
}

if ($options['check']) {
    foreach ($pCollection as $plugin) {
        echo "\n" . term($plugin->name, 'bold') . "...\n";
        echo $plugin->check() . "\n";
    }
    return RETURN_OK;
}

if ($options['cleanup']) {
    foreach ($pCollection as $plugin) {
        echo "\n" . term($plugin->name, 'bold') . "...\n";
        echo $plugin->cleanup(time()) . "\n";
    }
    return RETURN_OK;
}

class plugin {

    const DIAG_OK = 0;
    const DIAG_NOT_EXIST = 1;
    const DIAG_NOT_GIT = 2;
    const DIAG_MALFORMED = 3;

    public static $diagmessage = [
        self::DIAG_OK => 'OK: plugin directory exists, and is a git checkout',
        self::DIAG_NOT_EXIST => 'plugin directory DOES NOT exist',
        self::DIAG_NOT_GIT => 'plugin directory exists but IS NOT a git checkout',
        self::DIAG_MALFORMED => 'plugin declaration malformed in the config file',
    ];
    public $name;           // mandatory ; local name for the plugin, with slash separators eg. 'block/course_contents'
    public $repository;     // mandatory
    public $path;           // mandatory ; path from the moodle root
    public $plugin;         // optional ; as declared in plugin's version.php
    public $branch = null;  // optional
    public $hash = null;    // optional ; precise git hash, if it's convenient
    public $diagnostic;
    public $diagMsg = '';
    public $verb;  //verbosity

    /**
     * @param array $pluginArray directly from configuration file gitplugin.conf
     * @return array(plugin)
     */

    public static function initCollection($pluginsArray, $verb = 1) {
        foreach ($pluginsArray as $name => $plugin) {
            $newplugin = new self;
            // mandatory attributes:
            $newplugin->name = $name;
            $newplugin->repository = $plugin['repository'];
            $newplugin->path = $plugin['path'];
            // optional attributes:
            $newplugin->plugin = (isset($plugin['plugin']) ? $plugin['plugin'] : null);
            $newplugin->branch = (isset($plugin['branch']) ? $plugin['branch'] : null);
            $newplugin->hash = (isset($plugin['hash']) ? $plugin['hash'] : null);
            // other init
            $newplugin->verb = $verb;
            $res[] = $newplugin;
        }
        return $res;
    }

    public function diagnostic() {
        global $rootdir;

        if (empty($this->path)) {
            $this->diagnostic = self::DIAG_MALFORMED;
            return $this->diagnostic;
        }
        $dir = $rootdir . $this->path;
        if (file_exists($dir) && is_dir($dir)) {
            $gitdir = $dir . '/.git';
            if (file_exists($gitdir) && is_dir($gitdir)) {
                $this->diagnostic = self::DIAG_OK;
            } else {
                $this->diagnostic = self::DIAG_NOT_GIT;
            }
        } else {
            $this->diagnostic = self::DIAG_NOT_EXIST;
        }
//FIXME	$this->diagMsg = self::diagmessage[$this->diagnostic];
        return $this->diagnostic;
    }

    /**
     * @param $pCollection array of plugin
     */
    public static function display_diagnostic($pCollection) {
        foreach (self::$diagmessage as $error => $message) {
            echo $message . " : \n";
            foreach ($pCollection as $plugin) {
                if ($plugin->diagnostic == $error) {
                    echo "    * " . $plugin->name . " : " . $plugin->path . "\n";
                }
            }
            echo "\n";
        }
    }

    /**
     * get information with "git status"
     */
    public function status() {
        global $rootdir;

        $cd = chdir($rootdir . $this->path);
        if (!$cd) {
            printf("ERROR ! Unable to access %s\n", $this->path);
            return RETURN_ERROR;
        }

        exec('git status', $gitOutput, $gitReturn);
        $this->output($gitOutput, true);
        return $gitReturn;
    }

    /**
     * install the target plugins with "git clone"
     */
    public function install() {
        global $rootdir;
        if ($this->diagnostic != self::DIAG_NOT_EXIST) {
            printf("Warning ! Unable to install plugin %s ; already exists in %s.\n", $this->name, $this->path);
            return RETURN_ERROR;
        }

        $md = mkdir($rootdir . $this->path, 0755);
        if (!$md) {
            printf("ERROR ! Unable to create %s\n", $this->path);
            return RETURN_ERROR;
        }

        $cmdline = sprintf("git clone %s  --  %s %s", (!empty($this->branch) ? '-b ' . $this->branch : ''), $this->repository, $rootdir . $this->path
        );

        exec($cmdline, $gitOutput, $gitReturn);
        echo $cmdline . "\n";
        $this->output($gitOutput);
        return $gitReturn;
    }

    /**
     * upgrade the target plugins with "git checkout "
     */
    public function upgrade() {
        global $rootdir;
        if ($this->diagnostic != self::DIAG_OK) {
            printf("Warning, unable to update %s ! %s  %s.\n", $this->name, $this->path, $this->diagMsg);
            return RETURN_ERROR;
        }

        $cd = chdir($rootdir . $this->path);
        if (!$cd) {
            printf("ERROR ! Unable to access %s\n", $this->path);
            return RETURN_ERROR;
        }

        exec('git fetch', $gitOutput, $gitReturn);
        echo 'git fetch' . "\n";
        $this->output($gitOutput);

        if (!empty($this->branch)) {
            $cmdline = sprintf("git checkout %s", $this->branch);
        } elseif (!empty($this->hash)) {
            $cmdline = sprintf("git checkout %s", $this->hash);
        } else {
            $cmdline = 'git checkout';
        }
        exec($cmdline, $gitOutput, $gitReturn);
        echo $cmdline . "\n";
        $this->output($gitOutput);
        return $gitReturn;
    }

    /**
     * check the plugin configuration data
     * @return string  diagnostic message 
     */
    public function check() {
        global $rootdir;

        if (!preg_match('@^http(s?)://@', $this->repository)) {
            return 'Invalid URL for repository: ' . $this->repository;
        }

        $cmdline = sprintf('git ls-remote %s --exit-code', str_replace('://', '://FAKE:FAKE@', $this->repository)); //added fake credentials
        exec($cmdline, $gitOutput, $gitReturn);
        if ($gitReturn) {
            return 'Git repository does not exist or unreachable: ' . $this->repository;
        }

        $dir = dirname($rootdir . $this->path);
        if (!file_exists($dir) || !is_dir($dir)) {
            return 'Invalid path: ' . $this->path;
        }

        return 'OK';
    }

    /**
     * clean the plugin directories, renaming those which are not git working directories
     * @param integer $timestamp
     * @return string diagnostic message
     */
    public function cleanup($timestamp) {
        global $rootdir;

        if ($this->diagnostic != self::DIAG_NOT_GIT) {
            return '  unchanged';
        }

        $dir = $rootdir . $this->path;
        $res = rename($dir, $dir . ".back-$timestamp");
        return '  renamed to ' . $dir . ".back-$timestamp : $res";
    }

    /**
     * display the output on the terminal in an easily readable way (draft)
     * @param array $lines
     * @param boolean $always : display whatever this->verb
     */
    private function output($lines, $always = false) {
        if ($always || $this->verb > 0) {
            foreach ($lines as $line) {
                echo "  > " . $line . "\n";
            }
        }
    }

}

/**
 * @param string $text
 * @param string $style (one of the $escape keys)
 * @return string
 */
function term($text, $style) {
    $escape = [
        'bold' => ['start' => "\e[1m", 'stop' => "\e[21m"],
        'dim' => ['start' => "\e[2m", 'stop' => "\e[22m"],
        'under' => ['start' => "\e[4m", 'stop' => "\e[24m"],
        'blink' => ['start' => "\e[5m", 'stop' => "\e[25m"],
        'invert' => ['start' => "\e[7m", 'stop' => "\e[27m"],
        'hidden' => ['start' => "\e[8m", 'stop' => "\e[28m"],
    ];
    $fstyle = $escape[$style];
    return $fstyle['start'] . $text . $fstyle['stop'];
}
