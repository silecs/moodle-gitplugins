<?php

class gitpPlugin {

    const DIAG_OK = 0;
    const DIAG_NOT_EXIST = 1;
    const DIAG_NOT_GIT = 2;
    const DIAG_MALFORMED = 3;
    const DIAG_INCONSISTENT_REMOTE = 4;

    const GIT = '/usr/bin/git';

    const DIAG_MESSAGE = [
        self::DIAG_OK => 'OK: plugin directory exists, and is a git checkout',
        self::DIAG_NOT_EXIST => 'plugin directory DOES NOT exist',
        self::DIAG_NOT_GIT => 'plugin directory exists but IS NOT a git checkout',
        self::DIAG_MALFORMED => 'plugin declaration malformed in the config file',
        self::DIAG_INCONSISTENT_REMOTE => 'inconsistent repositories (config vs git-remote)',
    ];

    public $name;           // mandatory ; local name for the plugin, with slash separators eg. 'block/course_contents'
    public $repository;     // mandatory
    public $path;           // mandatory ; path from the moodle root
    public $branch = null;  // optional

    public $revision = null;    // optional ; precise git revision (hash or tag)
    public $diagnostic;
    public $diagMsg = '';
    public $diagComplement = '';
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

    public function setDiagnostic(): void
    {
        [$diagCode, $diagComplement] = $this->doDiagnostic();
        $this->diagnostic = $diagCode;
        $this->diagMsg = self::DIAG_MESSAGE[$this->diagnostic];
        $this->diagComplement = $diagComplement;
    }

    /**
     *
     * @return array [int Diagnostic_Code, string Diagnostic_Complement]
     */
    private function doDiagnostic(): array
    {
        if (empty($this->path)) {
            return [self::DIAG_MALFORMED, $this->path];
        }
        $dir = $this->root . $this->path;
        if (!file_exists($dir) || !is_dir($dir)) {
            return [self::DIAG_NOT_EXIST, $dir];
        }
        $gitdir = $dir . '/.git';
        if (!file_exists($gitdir) || !is_dir($gitdir)) {
            return [self::DIAG_NOT_GIT, $gitdir];
        }
        chdir($this->root . $this->path);
        $this->gitExec('remote get-url origin', $gitOrigin, $gitOutput);
        if (empty($this->repository) || empty($gitOrigin[0])) {
            return [self::DIAG_INCONSISTENT_REMOTE, sprintf('config="%s" vs git-remote="%s"', $this->repository, $gitOrigin[0])];
        }
        $repoConfig = $this->getRemoteRadix($this->repository);
        $repoLocal = $this->getRemoteRadix($gitOrigin[0]);
        if ($repoConfig !== $repoLocal) {
            return [self::DIAG_INCONSISTENT_REMOTE, sprintf('config="%s" vs git-remote="%s"', $this->repository, $gitOrigin[0])];
        }
        return [self::DIAG_OK, ''] ;
    }

    /**
     *
     * @return string ex. https://example.com/path/to/repository.git > https://example.com/path/to/repository
     */
    private function getRemoteRadix($repo): string
    {
        if (preg_match('/^(.*)\.git$/', $repo, $m)) {
            return $m[1];
        }
        return $repo;
    }

    /**
     * get information with "git status"
     */
    public function status(): array
    {
        $countStatus = [];
        $dir = $this->root . $this->path;
        $cd = chdir($dir);
        if (!$cd) {
            $msg = sprintf("ERROR ! Unable to access %s\n", $this->path);
            $this->output("chdir $dir", [$msg], 0);
            return [RETURN_ERROR, ['ZZ' => 1]];
        }
        $this->gitExec('status', $gitOutput, $gitReturn);
        $this->gitExec('status --short | cut -c1-2', $statusShort, $trash);
        foreach ($statusShort as $flag) {
            $countStatus[$flag] = isset($countStatus[$flag]) ? $countStatus[$flag]+1 : 1;
        }
        $this->output('status', $gitOutput, 2);
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

        $this->gitExec('remote -v', $gitOutput, $gitReturn);
        $this->output('remote -v', $gitOutput, 1);
        unset($gitOuput);
        $this->gitExec('branch -v -a', $branchOutput, $gitReturn);
        $this->output('branch -v -a', $branchOutput, 1);
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

        $cmdline = sprintf("clone %s  --  %s %s",
                (!empty($this->branch) ? '-b ' . $this->branch : ''),
                $this->repository,
                $this->root . $this->path
        );
        $this->gitExec($cmdline, $gitOutput, $gitReturn, true);
        $this->output($cmdline, $gitOutput, 1);
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

        $this->gitExec('log -1 --oneline', $gitOutput, $gitReturn);
        $this->output('log -1 --oneline', $gitOutput);
        $gitOutput = [];
        $this->gitExec('fetch', $gitOutput, $gitReturn);
        $this->output('fetch', $gitOutput);

        if (!empty($this->branch)) {
            $cmdline = sprintf("checkout %s", $this->branch);
            $this->gitExec($cmdline, $gitOutput, $gitReturn, true);
            $this->output($cmdline, $gitOutput, 1);
            $cmdline = "rebase";
            $this->gitExec($cmdline, $gitOutput, $gitReturn, true);
            $this->output($cmdline, $gitOutput, 1);
            return $gitReturn;
        } elseif (!empty($this->revision)) {
            $cmdline = sprintf("checkout %s", $this->revision);
            $this->gitExec($cmdline, $gitOutput, $gitReturn, true);
            $this->output($cmdline, $gitOutput, 1);
            $cmdline = "git rebase";
            $this->gitExec($cmdline, $gitOutput, $gitReturn, true);
            $this->output($cmdline, $gitOutput, 1);
            return $gitReturn;
        } else {
            $cmdline = "git rebase";
            $this->gitExec($cmdline, $gitOutput, $gitReturn, true);
            $this->output($cmdline, $gitOutput, 1);
            return $gitReturn;
        }
    }

    /**
     * check the plugin configuration file
     * @return array of diagnostic messages
     */
    public function check_config(): array
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

        $cmdline = sprintf('ls-remote --exit-code  %s  %s',
            str_replace('://', '://FAKE:FAKE@', $this->repository), //fake user/pass to avoid fallback on interactive cli
            (!empty($this->branch) ? $this->branch: '') );
        $this->gitExec($cmdline, $gitOutput, $gitReturn);
        if ($gitReturn) {
            $alerts[] = sprintf('Git remote repository does not exist or unreachable or branch does not exist: "%s (%s)"' ,
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
     *
     * @return bool|string
     */
    private function gitExec(string $command, array &$output = null, int &$result = null, bool $log = false): mixed
    {
        $cmd = sprintf("%s %s", self::GIT, $command);
        $res = exec($cmd, $output, $result);

        if ($log) {
            file_put_contents($this->logfile, sprintf("  < %s\n", $cmd), FILE_APPEND);
            foreach ($output as $line) {
                file_put_contents($this->logfile, sprintf("    > %s\n", $line), FILE_APPEND);
            }
        }
        return $res;
    }

    /**
     * display the output on the terminal in an easily readable way (draft)
     * @param string $cmdline "input" command line
     * @param array $lines output lines
     * @param boolean $always : display whatever this->verbosity
     */
    private function output($cmdline, $lines, $verbmin = 1)
    {
        if ($this->verbosity < $verbmin) {
            return;
        }
        echo "  < git " . $cmdline . "\n";
        foreach ($lines as $line) {
            echo "    > " . $line . "\n";
        }
    }

}