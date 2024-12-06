<?php

class gitpCollection {
    public $plugins = [];
    public $verbosity;
    public $log = false;
    public $logfile = '';
    public $ascii = false;
    public $rootdir;

    const CONFIG_FILE = 'admin/cli/gitplugins.conf';

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
    public function __construct($ascii=0) {
        // depuis un .phar, __FILE__ et __DIR__ renvoient "phar:///path/to/my/file"
        // assuming the script is in admin/cli
        $this->rootdir = dirname(dirname(dirname(Phar::running(false))));
        $config = require_once($this->rootdir . '/' . self::CONFIG_FILE);

        $settings = $config['settings'];
        $this->verbosity = isset($settings['verbosity']) ? $settings['verbosity'] : 0;
        $this->ascii = $ascii;
        if (isset($settings['log'])) {
            if ($settings['log'] === true) {
                $this->logfile = $this->rootdir . "/admin/cli/gitplugins.log";
            } else {
                $this->logfile = $settings['log'];
                $this->log = true;
            }
        } else {
            $this->log = false;
        }
        $this->log = $config['settings']['log'];

        foreach ($config['plugins'] as $name => $plugin) {
            $newplugin = (new gitpPlugin())->init($name, $plugin, $this->rootdir, $this->verbosity, $this->logfile);
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
        $begin = [
            '## gitplugins BEGIN autogenerated exclude',
            self::CONFIG_FILE,
            'admin/cli/gitplugins.php',
            'admin/cli/gitp.phar',
            'admin/cli/gitplugins.log',
            '#'
        ];
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
        echo self::$configsample;
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