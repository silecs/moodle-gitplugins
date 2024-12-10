<?php

class gitpCollection {
    public $plugins = [];
    public $verbosity;
    public $log = false;
    public $logfile = '';
    public $rootdir;

    const CONFIG_FILE = '.gitplugins.conf';
    const LOG_FILE    = '.gitplugins.log';

    public static $configsample = <<<EOSAMPLE
<?php
return([
    'settings' => [
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
EOSAMPLE;

    /**
     * @param array $config raw content of configuration file (gitplugin.conf)
     */
    public function __construct(int $verbosity)
    {
        // assuming the script is in admin/cli
        // $this->rootdir = dirname(dirname(dirname(Phar::running(false))));
        if (!getenv('MOODLE_ROOT')) {
            die("You must define an environment variable MOODLE_ROOT, where lies the main config.php.\n\n");
        }
        if (!is_dir(getenv('MOODLE_ROOT'))) {
            die("Path must exist : " . getenv('MOODLE_ROOT') . "\n\n");
        }
        $this->rootdir = realpath(getenv('MOODLE_ROOT'));
        $configpath = $this->rootdir . '/' . self::CONFIG_FILE;
        if (!is_readable($configpath)) {
            die("Config file must exist and be readable: $configpath\n\n");
        }
        $config = require_once($configpath);

        $settings = $config['settings'];
        $this->verbosity = $verbosity;
        if (isset($settings['log'])) {
            if ($settings['log'] === true) {
                $this->logfile = $this->rootdir . '/' . self::LOG_FILE;
            } else {
                $this->logfile = $settings['log'];
                $this->log = true;
            }
        } else {
            $this->log = false;
        }
        $this->log = $config['settings']['log'];
        putenv("LANGUAGE=C");

        foreach ($config['plugins'] as $name => $plugin) {
            $newplugin = (new gitpPlugin())->init($name, $plugin, $this->rootdir, $this->verbosity, $this->logfile);
            $this->plugins[] = $newplugin;
        }
        return true;
    }

    /**
     * check consistency of the configuration file
     */
    public function check_config(): bool
    {
        foreach ($this->plugins as $plugin) {
            echo $this->pname($plugin->name);
            $alerts = $plugin->check_config();
            if ($alerts) {
                echo "\n" . join("\n", $alerts) . "\n";
            } else {
                echo "OK.\n";
            }
        }
        return true;
    }

    public function status_all(): bool
    {
        $summary = [];
        foreach ($this->plugins as $plugin) {
            echo $this->pname($plugin->name);
            list($diag, $status_short) = $plugin->status();
            $index = (int)($diag > 0); // 0 or 1
            if ($status_short) {
                $summary_short[$plugin->name] = $status_short;
            }
            $summary[$index][] = $plugin->name;
        }
        if (isset($summary[0])) {
             echo $this->vecho("\n\nSTATUS OK :\n" . join(' ', $summary[0]), 1);
        }
        if (isset($summary[1])) {
             echo $this->vecho("\n\nSTATUS ERRORS :\n" . join(' ', $summary[0]), 0);
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

    public function setDiagnostic()
    {
        foreach ($this->plugins as $plugin) {
            $plugin->setDiagnostic();
        }
        return true;
    }

    public function displayDiagnostic(): string
    {
        // Structuration
        $diagLines = [];
        foreach (gitpPlugin::DIAG_MESSAGE as $diagCode => $message) {
            if ($diagCode == gitpPlugin::DIAG_OK && $this->verbosity==0) {
                continue;
            }
            foreach ($this->plugins as $plugin) {
                if ($plugin->diagnostic == $diagCode) {
                    $diagLines[$diagCode][] = sprintf("%s [%s]\n", $plugin->name, $plugin->diagComplement);
                }
            }
        }
        // Affichage
        $out = "";
        foreach ($diagLines as $diagCode => $lines) {
            $out .= gitpPlugin::DIAG_MESSAGE[$diagCode] . sprintf(" (%d) :\n", count($lines));
            foreach ($lines as $i => $line) {
                $out .= sprintf("%3d. %s", $i+1, $line);
            }
            $out .= "\n";
        }
        return $out;
    }

    public function list(): string
    {
        $out = '';
        $i = 0;
        foreach ($this->plugins as $plugin) {
            $i++;
            $out .= sprintf("%3d. %-25s %-40s %-10s %-10s\n",
                $i,
                $plugin->name,
                $plugin->path,
                $plugin->revision,
                $plugin->branch
            );
        }
        return $out;
    }

    public function install_all(): bool
    {
        foreach ($this->plugins as $plugin) {
            echo $this->vecho("\nPLUGIN " . $plugin->name . "...\n", 0);
            $this->log_to_file($plugin->name);
            echo $plugin->install() . "\n";
        }
        return true;
    }

    public function install($pluginname): bool
    {
        $myplugin = $this->find_plugin($pluginname);
        echo $this->pname($myplugin->name);
        $this->log_to_file($pluginname);
        echo $myplugin->install() . "\n";
        return true;
    }

    public function detail($pluginname): bool
    {
        $myplugin = $this->find_plugin($pluginname);
        echo $this->pname($myplugin->name);
        echo $myplugin->detail() . "\n";
        return true;
    }

    public function upgrade_all(): bool
    {
        foreach ($this->plugins as $plugin) {
            echo $this->vecho("\n" . strtoupper($plugin->name) . "...\n", 0);
            $this->log_to_file($plugin->name);
            echo $plugin->upgrade() . "\n";
        }
        return true;
    }

    public function upgrade($pluginname): bool
    {
        $myplugin = $this->find_plugin($pluginname);
        echo $this->pname($myplugin->name);
        $this->log_to_file($pluginname);
        echo $myplugin->upgrade() . "\n";
        return true;
    }


    public function cleanup(): bool
    {
        foreach ($this->plugins as $plugin) {
            echo $this->vecho("\n" . strtoupper($plugin->name) . "...\n", 0);
            echo $plugin->cleanup() . "\n";
        }
        return true;
    }

    public function generateExclude(): string
    {
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

    public static function generateConfig(): string
    {
        echo self::$configsample;
    }


    private function find_plugin($pluginname): ?gitpPlugin
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->name == $pluginname) {
                return $plugin;
            }
        }
        die (sprintf("%s not listed in %s. You can use --list\n\n", $pluginname, self::CONFIG_FILE));
        return false;
    }

    private function log_to_file($pluginname)
    {
        if ($this->log) {
            file_put_contents(
                $this->logfile,
                sprintf("\n%s  %s\n", date(DateTime::ISO8601), $pluginname),
                FILE_APPEND
            );
        }
    }

    private function pname($name): string
    {
        return $this->vecho("\nPLUGIN " . $name . "...\n", 1);
    }

    private function vecho($text, $verbmin=1): string
    {
        if ($this->verbosity >= $verbmin) {
            return $text;
        } else {
            return '';
        }
    }

}