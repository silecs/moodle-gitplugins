<?php

/**
 * gitplugins - A cli administration tool to help deploying Moodle plugins via Git
 * @copyright 2017-2018 Silecs {@link http://www.silecs.info/societe}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @version   1.2.3 : 2018100401
 */
define('CLI_SCRIPT', true);
define('RETURN_OK', 0);
define('RETURN_ERROR', 1);

$rootdir = dirname(dirname(__DIR__));   // assuming the script is in admin/cli
require($rootdir . '/config.php');        // global moodle config file.
require_once($CFG->libdir . '/clilib.php');      // cli only functions
// now get cli options
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'exclude' => false,
        'diag' => false,
        'status' => false,
        'checkconfig' => false,
        'install' => false,
        'upgrade' => false,
        'cleanup' => false,
        'config' => false,
        'ascii' => false,
    ],
    ['h' => 'help']);
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

$help = "Plugin installation or upgrade via Git, as declared in gitplugins.conf

Options:
-h, --help          Print out this help
--config <file>     Read this configuration file instead of gitplugins.conf
--ascii             No formatting sequences (compatibility with exotic terminals)

--checkconfig       Check the consistency of the configuration file
--diag              Diagnostic of all declared plugins
--status            Git status on each declared plugin
--install           Install all plugins that are not already present
--upgrade           Upgrade all plugins already installed
--cleanup           Remove all plugins in an inconsistent state (by RENAMING them so restoration is possible)
--exclude           Generate a chunk of lines to insert in your .git/info/exclude file
";

if (!empty($options['help'])) {
    echo $help;
    return 0;
}

if (empty($options['config'])) {
    $config = require_once('gitplugins.conf');
} else {
    $config = require_once($options['config']);
}

$pCollection = new gitpCollection($config, $options['ascii']);
$pCollection->setDiagnostic();

if ($options['diag']) {
    return $pCollection->displayDiagnostic();
}

if ($options['checkconfig']) {
    return $pCollection->checkconfig();
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
    public $log = false;
    public $logfile = '';
    public $ascii = false;

    /**
     * @param array $config raw content of configuration file (gitplugin.conf)
     * @param integer $ascii
     * @return boolean
     */
    public function __construct($config, $ascii=0) {
        global $rootdir;
        $settings = $config['settings'];
        $this->verbosity = isset($settings['verbosity']) ? $settings['verbosity'] : 0;
        $this->ascii = $ascii;
        if (isset($settings['log'])) {
            if ($settings['log'] === true) {
                $this->logfile = $rootdir . "/admin/cli/gitplugins.log";
            } else {
                $this->logfile = $settings['log'];
                $this->log = true;
            }
        } else {
            $this->log = false;
        }
        $this->log = $config['settings']['log'];

        foreach ($config['plugins'] as $name => $plugin) {
            $newplugin = (new gitpPlugin())->init($name, $plugin, $this->verbosity);
            $this->plugins[] = $newplugin;
        }
        return true;
    }

    /**
     * check consistency of the configuration file
     * @return boolean
     */
    public function checkconfig() {
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold', $this->ascii) . "...\n";
            $alerts = $plugin->checkconfig();
            if ($alerts) {
                echo join("\n", $alerts) . "\n";
            } else {
                echo "OK.\n";
            }
        }
        return true;
    }

    public function status() {
        putenv("LANGUAGE=C");
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold', $this->ascii) . "...\n";
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
        foreach (gitpPlugin::DIAGMESSAGE as $error => $message) {
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
        putenv("LANGUAGE=C");
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold', $this->ascii) . "...\n";
            if ($this->log) {
                file_put_contents($this->logfile, sprintf("\n%s  %s\n", date(DateTime::ISO8601), $plugin->name), FILE_APPEND);
            }
            echo $plugin->install($this->logfile) . "\n";
        }
        return true;
    }

    public function upgrade() {
        putenv("LANGUAGE=C");
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold', $this->ascii) . "...\n";
            if ($this->log) {
                file_put_contents($this->logfile, sprintf("\n%s  %s\n", date(DateTime::ISO8601), $plugin->name), FILE_APPEND);
            }
            echo $plugin->upgrade($this->logfile) . "\n";
        }
        return true;
    }

    public function cleanup() {
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold', $this->ascii) . "...\n";
            echo $plugin->cleanup() . "\n";
        }
        return true;
    }

    public function generateExclude() {
        $excludes = [];
        $begin = ['## gitplugins BEGIN autogenerated exclude', 
            'admin/cli/gitplugins.conf', 'admin/cli/gitplugins.php', 'admin/cli/gitplugins.log'];
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

    const DIAGMESSAGE = [
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
    public $verbosity;

    public function init($name, $pluginconf, $verbosity) {
        $newplugin = new gitpPlugin();
        // mandatory attributes:
        $newplugin->name = $name;
        $newplugin->path = $pluginconf['path'];
        $newplugin->repository = $pluginconf['gitrepository'];
        // optional attributes:
        $newplugin->plugin = (isset($pluginconf['plugin']) ? $pluginconf['plugin'] : null);
        $newplugin->branch = (isset($pluginconf['gitbranch']) ? $pluginconf['gitbranch'] : null);
        $newplugin->revision = (isset($pluginconf['gitrevision']) ? $pluginconf['gitrevision'] : null);
        // other init
        $newplugin->verbosity = $verbosity;
        return $newplugin;
    }

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
        $this->diagMsg = self::DIAGMESSAGE[$this->diagnostic];
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
     * @param string $logfile (or false)
     */
    public function install($logfile) {
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

        $cmdline = sprintf("git clone %s  --  %s %s",
                (!empty($this->branch) ? '-b ' . $this->branch : ''),
                $this->repository,
                $rootdir . $this->path
        );
        exec($cmdline, $gitOutput, $gitReturn);
        $this->output($cmdline, $gitOutput, false, $logfile);
        return $gitReturn;
    }

    /**
     * upgrade the target plugins with git checkout / git rebase
     * @param string $logfile (or false)
     */
    public function upgrade($logfile) {
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
            $this->output($cmdline, $gitOutput, false, $logfile);
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, false, $logfile);
            return $gitReturn;
        } elseif (!empty($this->revision)) {
            $cmdline = sprintf("git checkout %s", $this->revision);
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, false, $logfile);
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, false, $logfile);
            return $gitReturn;
        } else {
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, false, $logfile);
            return $gitReturn;
        }
    }

    /**
     * check the plugin configuration file
     * @return string  diagnostic message 
     */
    public function checkconfig() {
        global $rootdir;
        $alerts = [];

        $dir = dirname($rootdir . $this->path);
        if (!file_exists($dir) || !is_dir($dir)) {
            $alerts[] = 'Invalid path: ' . $this->path;
        }

        if (!preg_match('@^http(s?)://@', $this->repository)) {
            $alerts[] = sprintf('Invalid URL for repository: "%s"', $this->repository);
        }

        $cmdline = sprintf('git ls-remote --exit-code  %s  %s',
            str_replace('://', '://FAKE:FAKE@', $this->repository), //fake user/pass to avoid fallback on interactive cli
            (!empty($this->branch) ? $this->branch: '') );
        exec($cmdline, $gitOutput, $gitReturn);
        if ($gitReturn) {
            $alerts[] = sprintf('Git repository does not exist or unreachable or branch does not exist: "%s (%s)"' ,
                    $this->repository, $this->branch);
        }

        if (!empty($this->branch) && !empty($this->revision)) {
            $alerts[] = 'You must declare AT MOST one branch OR one revision';
        }

        return $alerts;
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
     * @param boolean $always : display whatever this->verbosity
     * @param string $logfile (or FALSE)
     */
    private function output($cmdline, $lines, $always = false, $logfile = false) {
        if ($always || $this->verbosity > 0) {
            echo "  < " . $cmdline . "\n";
            foreach ($lines as $line) {
                echo "    > " . $line . "\n";
            }
        }
        if ($logfile) {
            file_put_contents($logfile, sprintf("  < %s\n", $cmdline), FILE_APPEND);
            foreach ($lines as $line) {
                file_put_contents($logfile, sprintf("    > %s\n", $line), FILE_APPEND);
            }
        }
    }

}

/**
 * @param string $text
 * @param string $style (one of the $escape keys)
 * @param bool $ascii : true => no escape sequence
 * @return string
 */
function gitpTerm($text, $style, $ascii) {
    $escape = [
        'bold' => ['start' => "\e[1m", 'stop' => "\e[21m"],
        'dim' => ['start' => "\e[2m", 'stop' => "\e[22m"],
        'under' => ['start' => "\e[4m", 'stop' => "\e[24m"],
        'blink' => ['start' => "\e[5m", 'stop' => "\e[25m"],
        'invert' => ['start' => "\e[7m", 'stop' => "\e[27m"],
        'hidden' => ['start' => "\e[8m", 'stop' => "\e[28m"],
    ];
    $fstyle = $escape[$style];
    return ($ascii ? $text : $fstyle['start'] . $text . $fstyle['stop']);
}
