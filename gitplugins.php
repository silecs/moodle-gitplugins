#!/usr/bin/env php
<?php
/**
 * gitplugins - A cli administration tool to help deploying Moodle plugins via Git
 *
 * @copyright 2017-2024 Silecs {@link http://www.silecs.info/societe}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @version   1.5.6 : 2024-12-04
 * @link      https://github.com/silecs/moodle-gitplugins
 * install with: wget https://raw.githubusercontent.com/silecs/moodle-gitplugins/master/gitplugins.php
 */
if (php_sapi_name() !== 'cli') {
    die ('CLI mode only');
}

define('CLI_SCRIPT', true);
define('RETURN_OK', 0);
define('RETURN_ERROR', 1);

$rootdir = dirname(dirname(__DIR__));   // assuming the script is in admin/cli
require_once($rootdir.'/lib/clilib.php');      // cli only functions
// now get cli options
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'gen-exclude' => false,
        'gen-config' => false,
        'diag' => false,
        'list' => false,
        'detail' => '',
        'status-all' => false,
        'checkconfig' => false,
        'install-all' => false,
        'install' => '',
        'upgrade-all' => false,
        'upgrade' => '',
        'cleanup' => false,
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
--config=<file>     Read this configuration file instead of gitplugins.conf
--ascii             No formatting sequences (compatibility with exotic terminals)

--gen-config        Generate a sample gitplugins.conf file
--checkconfig       Check the consistency of the configuration file
--diag              Diagnostic of all declared plugins
--list              List all declared plugins (without diagnostic)
--detail=<name>     Display details about one plugin
--status-all        Git status on each declared plugin
--install-all       Install all plugins that are not already present
--install=<name>    Install this plugin according to gitplugins.conf
--upgrade-all       Upgrade all plugins already installed
--upgrade=<name>    Upgrade this plugin according to gitplugins.conf
--cleanup           Remove all plugins in an inconsistent state (by RENAMING them so restoration is possible)
--gen-exclude       Generate a chunk of lines to insert in your .git/info/exclude file
";

if (!empty($options['help'])) {
    echo $help;
    return 0;
}

if ($options['gen-config']) {
    printf("Writing %s ; to be completed.\n\n", gitpCollection::$configfile);
    return gitpCollection::generateConfig();
}

$config = require_once('gitplugins.conf');
$pCollection = new gitpCollection($config, $options['ascii']);
$pCollection->setDiagnostic();



if ($options['diag']) {
    return $pCollection->displayDiagnostic();
}

if ($options['list']) {
    return $pCollection->list();
}

if ($options['detail']) {
    if (! isset($options['detail'])) {
        die ('--detail=<plugin_name>');
    }
    return $pCollection->detail($options['detail']);
}

if ($options['checkconfig']) {
    return $pCollection->checkconfig();
}

if ($options['status-all']) {
    return $pCollection->status_all();
}

if ($options['install-all']) {
    return $pCollection->install_all();
}

if ($options['install']) {
    if (! isset($options['install'])) {
        die ('--install=<plugin_name> ; you can use --list or --diag to list the plugins, otherwise try --install-all');
    }
    return $pCollection->install($options['install']);
}

if ($options['upgrade-all']) {
    return $pCollection->upgrade_all();
}

if ($options['upgrade']) {
    if (! isset($options['upgrade'])) {
        die ('--upgrade=<plugin_name> ; you can use --diag to list the plugins, otherwise try --upgrade-all');
    }
    return $pCollection->upgrade($options['upgrade']);
}


if ($options['cleanup']) {
    return $pCollection->cleanup();
}

if ($options['gen-exclude']) {
    echo "You can insert the following lines in the file `.git/info/exclude`\n";
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
    public static $configfile = 'gitplugins.conf';

    public static $configsample = <<<'EOT'
<?php
return([
    'settings' => [
        'verbosity' => 1,  // 0 or 1
        'log' => false,    // FALSE, TRUE or an absolute path for the logfile
    ],
    'plugins' => [
        'PLUGIN/NAME' => [  // a label or identifier for the plugin
            'path' => '', // mandatory ; path from the moodle root
            'gitrepository' => '', // mandatory
            'gitbranch' => '', // optional ; git branch (incompatible with gitrevision)
            'gitrevision' => '', // optional ; precise git revision (hash or tag)  (incompatible with gitbranch)
        ],
    // Example plugin:
        'local/mailtest' => [ // a label or identifier for the plugin
            'path' => '/local/mailtest', // mandatory ; path from the moodle root
            'gitrepository' => 'https://github.com/michael-milette/moodle-local_mailtest', // mandatory
            'gitbranch' => '', // optional ; git branch (incompatible with gitrevision)
            'gitrevision' => '', // optional ; precise git revision (hash or tag)  (incompatible with gitbranch)
        ],
    ],
]);

EOT;

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
            $newplugin = (new gitpPlugin())->init($name, $plugin, $this->verbosity, $this->logfile);
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

    public function status_all() {
        $summary = [];
        putenv("LANGUAGE=C");
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'invert', $this->ascii) . "...\n";
            list($diag, $status_short) = $plugin->status();
            $index = (int)($diag > 0); // 0 or 1
            if ($status_short) {
                $summary_short[$plugin->name] = $status_short;
            }
            $summary[$index][] = $plugin->name;
        }
        if (isset($summary[0])) {
            echo "\n\n" . gitpTerm('Status OK :', 'invert', $this->ascii) . join(' ', $summary[0]);
        }
        if (isset($summary[1])) {
        echo "\n\n" . gitpTerm('Status errors :', 'invert', $this->ascii) . join(' ', $summary[1]);
        }
        echo "\n\n";
        if ($summary_short) {
            echo "According to git status, there are local modification:\n";
            foreach ($summary_short as $plugin => $count) {
                $output = implode(', ', array_map(
                    function ($v, $k) { return sprintf("%s=%d", $k, $v); },
                    $count, array_keys($count)));
                echo "$plugin : $output\n";
            }
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

    public function list() {
        $i = 0;
        foreach ($this->plugins as $plugin) {
            $i++;
            printf("%3d. %-25s %-40s %-10s %-10s\n", $i, $plugin->name, $plugin->path, 
                 $plugin->revision, $plugin->branch);
        }
    }

    public function install_all() {
        putenv("LANGUAGE=C");
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold', $this->ascii) . "...\n";
            $this->log_to_file($plugin->name);
            echo $plugin->install() . "\n";
        }
        return true;
    }

    public function install($pluginname) {
        putenv("LANGUAGE=C");
        $myplugin = $this->find_plugin($pluginname);
        echo "\n" . gitpTerm($myplugin->name, 'bold', $this->ascii) . "...\n";
        $this->log_to_file($pluginname);
        echo $myplugin->install() . "\n";
        return true;
    }

    public function detail($pluginname) {
        putenv("LANGUAGE=C");
        $myplugin = $this->find_plugin($pluginname);
        echo "\n" . gitpTerm($myplugin->name, 'bold', $this->ascii) . "...\n";
        echo $myplugin->detail() . "\n";
        return true;
    }

    public function upgrade_all() {
        putenv("LANGUAGE=C");
        foreach ($this->plugins as $plugin) {
            echo "\n" . gitpTerm($plugin->name, 'bold', $this->ascii) . "...\n";
            $this->log_to_file($plugin->name);
            echo $plugin->upgrade() . "\n";
        }
        return true;
    }

    public function upgrade($pluginname) {
        putenv("LANGUAGE=C");
        $myplugin = $this->find_plugin($pluginname);
        echo "\n" . gitpTerm($myplugin->name, 'bold', $this->ascii) . "...\n";
        $this->log_to_file($pluginname);
        echo $myplugin->upgrade() . "\n";
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
            'admin/cli/' . self::$configfile, 'admin/cli/gitplugins.php', 'admin/cli/gitplugins.log', '#'];
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

    public static function generateConfig() {
        if (file_exists(self::$configfile)) {
            echo self::$configsample;
        } else {
            file_put_contents(self::$configfile, self::$configsample);
        }
    }

    private function find_plugin($pluginname) {
        putenv("LANGUAGE=C");
        foreach ($this->plugins as $plugin) {
            if ($plugin->name == $pluginname) {
                return $plugin;
            }
        }
        die ($pluginname . " not listed in gitplugins.conf. You can use --diag\n\n");
        return false;
    }

    private function log_to_file($pluginname) {
        if ($this->log) {
            file_put_contents($this->logfile, sprintf("\n%s  %s\n", date(DateTime::ISO8601), $pluginname), FILE_APPEND);
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
    public $branch = null;  // optional
    public $revision = null;    // optional ; precise git revision (hash or tag)
    public $diagnostic;
    public $diagMsg = '';
    public $verbosity;
    public $logfile;

    public function init($name, $pluginconf, $verbosity, $logfile) {
        $newplugin = new gitpPlugin();
        // mandatory attributes:
        $newplugin->name = $name;
        $newplugin->path = $pluginconf['path'];
        $newplugin->repository = $pluginconf['gitrepository'];
        // optional attributes:
        $newplugin->branch = (isset($pluginconf['gitbranch']) ? $pluginconf['gitbranch'] : null);
        $newplugin->revision = (isset($pluginconf['gitrevision']) ? $pluginconf['gitrevision'] : null);
        // other init
        $newplugin->verbosity = $verbosity;
        $newplugin->logfile = $logfile;
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

        $countStatus = [];
        $cd = chdir($rootdir . $this->path);
        if (!$cd) {
            printf("ERROR ! Unable to access %s\n", $this->path);
            return RETURN_ERROR;
        }

        exec('git status', $gitOutput, $gitReturn);
        exec('git status --short | cut -c1-2', $statusShort, $trash);
        foreach ($statusShort as $flag) {
            $countStatus[$flag] = isset($countStatus[$flag]) ? $countStatus[$flag]+1 : 1;
        }
        $this->output('git status', $gitOutput, false);
        return [$gitReturn, $countStatus];
    }

    public function detail() {
        global $rootdir;
        echo "Name: $this->name\n";
        echo "Repository: $this->repository\n";
        echo "Path: $this->path\n";
        echo "Branch: $this->branch\n";
        echo "Revision: $this->revision\n";
        $cd = chdir($rootdir . $this->path);
        if (!$cd) {
            printf("ERROR ! Unable to access %s\n", $this->path);
            return RETURN_ERROR;
        }

        exec('git remote -v', $gitOutput, $gitReturn);
        $this->output('git remote -v', $gitOutput, true);
        unset($gitOuput);
        exec('git branch -v -a', $branchOutput, $gitReturn);
        $this->output('git branch -v -a', $branchOutput, true);
    }

    /**
     * install the target plugins with "git clone"
     * @param string $logfile (or false)
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

        $cmdline = sprintf("git clone %s  --  %s %s",
                (!empty($this->branch) ? '-b ' . $this->branch : ''),
                $this->repository,
                $rootdir . $this->path
        );
        exec($cmdline, $gitOutput, $gitReturn);
        $this->output($cmdline, $gitOutput, false, $this->logfile);
        return $gitReturn;
    }

    /**
     * upgrade the target plugins with git checkout / git rebase
     * @param string $logfile (or false)
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
            $this->output($cmdline, $gitOutput, false, $this->logfile);
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, false, $this->logfile);
            return $gitReturn;
        } elseif (!empty($this->revision)) {
            $cmdline = sprintf("git checkout %s", $this->revision);
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, false, $this->logfile);
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, false, $this->logfile);
            return $gitReturn;
        } else {
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, false, $this->logfile);
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

        if (!preg_match('@^http(s?)://@', $this->repository) 
            && !preg_match('&.*@.*:&', $this->repository)) { // accept git@github.com:/path/to/repo
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
    public function cleanup() {
        global $rootdir;

        if ($this->diagnostic != self::DIAG_NOT_GIT) {
            return '  unchanged';
        }

        $timestamp = time();
        $dir = $rootdir . $this->path;
        $to = $dir . ".gpcleanup-$timestamp";
        $res = rename($dir, $to);
        return "  renamed to $to : $res";
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

    if ($ascii) {
        return $text;
    } 
    return $fstyle['start'] . $text . $fstyle['stop'];
}
