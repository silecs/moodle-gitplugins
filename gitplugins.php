<?php

/**
 * gitplugins - A cli administration tool to help deploying Moodle plugins via Git
 * @copyright 2017 Silecs {@link http://www.silecs.info/societe}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @version   1.2 : 2017102501
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
    ['help' => false, 'verb' => 1, 'exclude' => false,
    'diag' => false, 'status' => false, 'check' => false,
    'install' => false, 'upgrade' => false, 'cleanup' => false],
    ['h' => 'help']);
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
--exclude           Generate a chunk of lines to insert in your .git/info/exclude file
    --verb=N        Verbosity (0 or 1), 1 by default
";


if (!empty($options['help'])) {
    echo $help;
    return 0;
}


$pCollection = new gitpCollection();
$pCollection->init($plugins, $options['verb']);
$pCollection->setDiagnostic();

if ($options['diag']) {
    return $pCollection->displayDiagnostic();
}

if ($options['check']) {
    return $pCollection->check();
}

if ($options['status']) {
    return $pCollection->status();
}

if ($options['install']) {
    return $pCollection->install();
}

if ($options['upgrade']) {
    return $pCollection->upgrade();
}

if ($options['cleanup']) {
    return $pCollection->cleanup();
}

if ($options['exclude']) {
    echo "You can insert the following lines `.git/info/exclude` file.\n";
    echo "\n" . $pCollection->generateExclude() . "\n";
    return RETURN_OK;
}

exit;



class gitpCollection {
    public $plugins = array();
    public $verbosity;
    public $log = '';

    /**
     * @param array $pluginArray directly from configuration file gitplugin.conf
     * @return array(plugin)
     */

    public function init($pluginsArray, $verb = 1) {
        foreach ($pluginsArray as $name => $plugin) {
            $newplugin = new gitpPlugin();
            // mandatory attributes:
            $newplugin->name = $name;
            $newplugin->path = $plugin['path'];
            $newplugin->repository = $plugin['gitrepository'];
            // optional attributes:
            $newplugin->plugin = (isset($plugin['plugin']) ? $plugin['plugin'] : null);
            $newplugin->branch = (isset($plugin['gitbranch']) ? $plugin['gitbranch'] : null);
            $newplugin->revision = (isset($plugin['gitrevision']) ? $plugin['gitrevision'] : null);
            // other init
            $newplugin->verb = $verb;
            $this->plugins[] = $newplugin;
        }
        return true;
    }


    public function check() {
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold') . "...\n";
            echo $plugin->check() . "\n";
        }
    return true;
    }

    public function status() {
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold') . "...\n";
            echo $plugin->status() . "\n";
        }
    return true;
    }

    public function setDiagnostic() {
        foreach ($this->plugins as $plugin) {
            $plugin->setDiagnostic();
        }
    return true;
    }

    public function displayDiagnostic() {
        foreach (gitpPlugin::$diagmessage as $error => $message) {
            echo $message . " : \n";
            foreach ($this->plugins as $plugin) {
                if ($plugin->diagnostic == $error) {
                    echo "    * " . $plugin->name . " : " . $plugin->path . "\n";
                }
            }
            echo "\n";
        }
    }


    public function install() {
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold') . "...\n";
            echo $plugin->install() . "\n";
        }
    return true;
    }

    public function upgrade() {
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold') . "...\n";
            echo $plugin->upgrade() . "\n";
        }
    return true;
    }

    public function cleanup() {
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold') . "...\n";
            echo $plugin->cleanup() . "\n";
        }
    return true;
    }

    public function generateExclude() {
        $excludes = [];
        $begin = ['## gitplugins BEGIN autogenerated exclude', 'admin/cli/gitplugins.conf', 'admin/cli/gitplugins.php'];
        $end = ['## gitplugins END'];
        foreach ($this->plugins as $plugin) {
            if ($plugin->diagnostic == gitpPlugin::DIAG_OK) {
                $excludes[] = $plugin->path;
            }
        }
        if ($excludes) {
            return join("\n", array_merge($begin, $excludes, $end)) . "\n";
        } else {
            return '';
        }
    }

}


class gitpPlugin {

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
    public $revision = null;    // optional ; precise git revision (hash or tag)
    public $diagnostic;
    public $diagMsg = '';
    public $verb;  //verbosity

    public function setDiagnostic() {
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
        $this->output('git status', $gitOutput, true);
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
        $this->output($cmdline, $gitOutput);
        return $gitReturn;
    }

    /**
     * upgrade the target plugins with git checkout / git rebase
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

        exec('git log -1 --oneline', $gitOutput, $gitReturn);
        $this->output(' git log -1 --oneline', $gitOutput);
        $gitOutput = [];
        exec('git fetch', $gitOutput, $gitReturn);
        $this->output('git fetch', $gitOutput);

        if (!empty($this->branch)) {
            $cmdline = sprintf("git checkout %s", $this->branch);
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput);
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput);
            return $gitReturn;
        } elseif (!empty($this->revision)) {
            $cmdline = sprintf("git checkout %s", $this->revision);
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput);
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput);
            return $gitReturn;
        } else {
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput);
            return $gitReturn;
        }
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

        $cmdline = sprintf('git ls-remote --exit-code  %s  %s',
                str_replace('://', '://FAKE:FAKE@', $this->repository), //fake user/pass to avoid fallback on interactive cli
                (!empty($this->branch) ? $this->branch: '') );
        exec($cmdline, $gitOutput, $gitReturn);
        if ($gitReturn) {
            return 'Git repository does not exist or unreachable or branch does not exist: ' . $this->repository . ' ' . $this->branch;
        }

        if (!empty($this->branch) && !empty($this->revision)) {
            return 'You must declare AT MOST one branch OR one revision';
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
     * @param string $cmdline "input" command line
     * @param array $lines output lines
     * @param boolean $always : display whatever this->verb
     */
    private function output($cmdline, $lines, $always = false) {
        if ($always || $this->verb > 0) {
            echo "  < " . $cmdline . "\n";
            foreach ($lines as $line) {
                echo "    > " . $line . "\n";
            }
        }
    }

}

/**
 * @param string $text
 * @param string $style (one of the $escape keys)
 * @return string
 */
function gitpTerm($text, $style) {
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
