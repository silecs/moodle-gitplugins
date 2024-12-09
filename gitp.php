#!/usr/bin/env php
<?php
/**
 * gitp.phar - A cli administration tool to help deploying Moodle plugins via Git
 *
 * @copyright 2017-2024 Silecs {@link http://www.silecs.info/societe}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link      https://github.com/silecs/moodle-gitplugins
 * download from https://github.com/silecs/moodle-gitplugins/releases
 */
const GITPLUGINS_VERSION = '2.1.0 2024-12-09';

if (php_sapi_name() !== 'cli') {
    die ('CLI mode only');
}

require_once __DIR__ . '/gitpPlugin.php';
require_once __DIR__ . '/gitpCollection.php';

define('RETURN_OK', 0);
define('RETURN_ERROR', 1);

$longopts = [
    'help',
    'ascii',
    'version',
    'gen-exclude',
    'gen-config',
    'diag',
    'list',
    'detail::',
    'status-all',
    'check-config',
    'install-all',
    'install::',
    'upgrade-all',
    'upgrade::',
    'cleanup',
];

$help = "Plugin installation or upgrade via Git, as declared in gitplugins.conf

Options:
--help              Print out this help
--ascii             No formatting sequences (compatibility with exotic terminals)
--version           Print version revision and build datetime

--gen-config        Generate a sample gitplugins.conf file
--check-config       Check the consistency of the configuration file
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

$options = getopt('', $longopts);
if (empty($options) || isset($options['help'])) {
    echo $help;
    return RETURN_OK;
}

if (isset($options['version'])) {
    printf("Gitplugins Version %s, Phar built @horodatage@ @git_commit_short@\n@copyright@\n\n", GITPLUGINS_VERSION);
    return RETURN_OK;
}

if (isset($options['gen-config'])) {
    printf("Config sample ; to be completed and redirected to %s.\n\n", gitpCollection::CONFIG_FILE);
    return gitpCollection::generateConfig();
}

$pCollection = new gitpCollection(isset($options['ascii']));
$pCollection->setDiagnostic();


if (isset($options['diag'])) {
    echo $pCollection->displayDiagnostic();
    return RETURN_OK;
}

if (isset($options['list'])) {
    echo $pCollection->list();
    return RETURN_OK;
}

if (isset($options['detail'])) {
    if (empty($options['detail'])) {
        die ('--detail=<plugin_name>');
    }
    return $pCollection->detail($options['detail']);
}

if (isset($options['check-config'])) {
    return $pCollection->check_config();
}

if (isset($options['status-all'])) {
    return $pCollection->status_all();
}

if (isset($options['install-all'])) {
    return $pCollection->install_all();
}

if (isset($options['install'])) {
    if (empty($options['install'])) {
        die ('--install=<plugin_name>. You can use --list to list the plugins ; otherwise try --install-all');
    }
    return $pCollection->install($options['install']);
}

if (isset($options['upgrade-all'])) {
    return $pCollection->upgrade_all();
}

if (isset($options['upgrade'])) {
    if (! isset($options['upgrade'])) {
        die ('--upgrade=<plugin_name> ; you can use --diag to list the plugins, otherwise try --upgrade-all');
    }
    return $pCollection->upgrade($options['upgrade']);
}

if (isset($options['cleanup'])) {
    return $pCollection->cleanup();
}

if (isset($options['gen-exclude'])) {
    echo "You can insert the following lines in the file `.git/info/exclude`\n";
    echo "\n" . $pCollection->generateExclude() . "\n";
    return RETURN_OK;
}

exit;

/**
 * @param string $text
 * @param string $style (one of the $escape keys)
 * @param bool $ascii : true => no escape sequence
 * @return string
 */
function gitpTerm($text, $style, $ascii)
{
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
