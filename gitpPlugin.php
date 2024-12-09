<?php

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
    public $root; // Moodle root directory

    public function init($name, $pluginconf, $root, $verbosity, $logfile): gitpPlugin
    {
        $newplugin = new gitpPlugin();
        // mandatory attributes:
        $newplugin->name = $name;
        $newplugin->path = $pluginconf['path'];
        $newplugin->repository = $pluginconf['gitrepository'];
        // optional attributes:
        $newplugin->branch = (isset($pluginconf['gitbranch']) ? $pluginconf['gitbranch'] : null);
        $newplugin->revision = (isset($pluginconf['gitrevision']) ? $pluginconf['gitrevision'] : null);
        // other init
        $newplugin->root = $root;
        $newplugin->verbosity = $verbosity;
        $newplugin->logfile = $logfile;
        return $newplugin;
    }

    public function setDiagnostic(): string
    {
        if (empty($this->path)) {
            $this->diagnostic = self::DIAG_MALFORMED;
            return $this->diagnostic;
        }
        $dir = $this->root . $this->path;
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
     * @fixme return mixed devrait être strictem
     */
    public function status(): array
    {
        $countStatus = [];
        $cd = chdir($this->root . $this->path);
        if (!$cd) {
            $msg = sprintf("ERROR ! Unable to access %s\n", $this->path);
            $this->output('chdir', [$msg], 0);
            return [RETURN_ERROR, ['ZZ' => 1]];
        }
        exec('git remote get-url origin', $gitOrigin);
        if ($gitOrigin[0] !== $this->repository) {
            $msg = sprintf("ERROR ! inconsistent repositories (config) %s vs (local) %s", $gitOrigin[0], $this->repository);
            $this->output('git remote', [$msg], 0);
            return [RETURN_ERROR, ['ZX' => 1]];
        }
        exec('git status', $gitOutput, $gitReturn);
        exec('git status --short | cut -c1-2', $statusShort, $trash);
        foreach ($statusShort as $flag) {
            $countStatus[$flag] = isset($countStatus[$flag]) ? $countStatus[$flag]+1 : 1;
        }
        $this->output('git status', $gitOutput, 2);
        return [$gitReturn, $countStatus];
    }

    public function detail(): int
    {
        echo "Name: $this->name\n";
        echo "Repository: $this->repository\n";
        echo "Path: $this->path\n";
        echo "Branch: $this->branch\n";
        echo "Revision: $this->revision\n";
        $cd = chdir($this->root . $this->path);
        if (!$cd) {
            printf("ERROR ! Unable to access %s\n", $this->path);
            return RETURN_ERROR;
        }

        exec('git remote -v', $gitOutput, $gitReturn);
        $this->output('git remote -v', $gitOutput, 1);
        unset($gitOuput);
        exec('git branch -v -a', $branchOutput, $gitReturn);
        $this->output('git branch -v -a', $branchOutput, 1);
        return RETURN_OK;
    }

    /**
     * install the target plugins with "git clone"
     * @param string $logfile (or false)
     */
    public function install(): int
    {
        if ($this->diagnostic != self::DIAG_NOT_EXIST) {
            printf("Warning ! Unable to install plugin %s ; already exists in %s.\n", $this->name, $this->path);
            return RETURN_ERROR;
        }

        $md = mkdir($this->root . $this->path, 0755);
        if (!$md) {
            printf("ERROR ! Unable to create %s\n", $this->path);
            return RETURN_ERROR;
        }

        $cmdline = sprintf("git clone %s  --  %s %s",
                (!empty($this->branch) ? '-b ' . $this->branch : ''),
                $this->repository,
                $this->root . $this->path
        );
        exec($cmdline, $gitOutput, $gitReturn);
        $this->output($cmdline, $gitOutput, 1, true);
        return $gitReturn;
    }

    /**
     * upgrade the target plugins with git checkout / git rebase
     * @param string $logfile (or false)
     */
    public function upgrade(): int
    {
        if ($this->diagnostic != self::DIAG_OK) {
            printf("Warning, unable to update %s ! %s  %s.\n", $this->name, $this->path, $this->diagMsg);
            return RETURN_ERROR;
        }

        $cd = chdir($this->root . $this->path);
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
            $this->output($cmdline, $gitOutput, 1, true);
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, 1, true);
            return $gitReturn;
        } elseif (!empty($this->revision)) {
            $cmdline = sprintf("git checkout %s", $this->revision);
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, 1, true);
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, 1, true);
            return $gitReturn;
        } else {
            $cmdline = "git rebase";
            exec($cmdline, $gitOutput, $gitReturn);
            $this->output($cmdline, $gitOutput, 1, true);
            return $gitReturn;
        }
    }

    /**
     * check the plugin configuration file
     * @return string  diagnostic message
     */
    public function check_config(): string
    {
        $alerts = [];

        $dir = dirname($this->root . $this->path);
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
    public function cleanup(): string
    {
        if ($this->diagnostic != self::DIAG_NOT_GIT) {
            return '  unchanged';
        }

        $timestamp = time();
        $dir = $this->root . $this->path;
        $to = $dir . ".gpcleanup-$timestamp";
        $res = rename($dir, $to);
        return "  renamed to $to : $res";
    }

    /**
     * display the output on the terminal in an easily readable way (draft)
     * @param string $cmdline "input" command line
     * @param array $lines output lines
     * @param boolean $always : display whatever this->verbosity
     */
    private function output($cmdline, $lines, $verbmin = 1, $log = false)
    {
        if ($this->verbosity >= $verbmin) {
            echo "  < " . $cmdline . "\n";
            foreach ($lines as $line) {
                echo "    > " . $line . "\n";
            }
        }
        if ($log) {
            file_put_contents($this->logfile, sprintf("  < %s\n", $cmdline), FILE_APPEND);
            foreach ($lines as $line) {
                file_put_contents($this->logfile, sprintf("    > %s\n", $line), FILE_APPEND);
            }
        }
    }

}