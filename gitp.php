#!/usr/bin/env php
<?php
/**
 * gitplugins - A cli administration tool to help deploying Moodle plugins via Git
 *
 * @copyright 2017-2024 Silecs {@link http://www.silecs.info/societe}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @version   2.0.0 : 2024-12-07
 * @link      https://github.com/silecs/moodle-gitplugins
 * install with: wget https://raw.githubusercontent.com/silecs/moodle-gitplugins/master/gitplugins.php
 */
if (php_sapi_name() !== 'cli') {
    die ('CLI mode only');
}

require_once "gitpPlugin.php";
require_once "gitpCollection.php";

define('RETURN_OK', 0);
define('RETURN_ERROR', 1);

$longopts =
    [
        'help',
        'gen-exclude',
        'gen-config',
        'diag',
        'list',
        'detail::',
        'status-all',
        'checkconfig',
        'install-all',
        'install::',
        'upgrade-all',
        'upgrade::',
        'cleanup',
        'ascii',
    ];

$help = "Plugin installation or upgrade via Git, as declared in gitplugins.conf

Options:
--help              Print out this help
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

$options = getopt('', $longopts);
if (empty($options) || isset($options['help'])) {
    echo $help;
    return 0;
}

if (isset($options['gen-config'])) {
    printf("Writing %s ; to be completed.\n\n", gitpCollection::$configfile);
    return gitpCollection::generateConfig();
}

$pCollection = new gitpCollection(isset($options['ascii']));
$pCollection->setDiagnostic();


if (isset($options['diag'])) {
    return $pCollection->displayDiagnostic();
}

if (isset($options['list'])) {
    return $pCollection->list();
}

if (isset($options['detail'])) {
    if (empty($options['detail'])) {
        die ('--detail=<plugin_name>');
    }
    return $pCollection->detail($options['detail']);
}

if (isset($options['checkconfig'])) {
    return $pCollection->checkconfig();
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
