# gitplugins - A cli administration tool to help deploying Moodle plugins via Git

## Overview

This Moodle tool is a simple cli script to help deploying Moodle plugins via Git,
but without using submodules.

You need to install one single file in the /admin/cli directory, the `gitplugins.php` script.

## gitplugins.conf

The `gitplugins.conf` file has two sections : a short list of settings, and
a list of all the plugins you want to manage (install, upgrade...) following the format:
```
    'local/mailtest' => [
        'path' => '/local/mailtest', // mandatory ; path from the moodle root
        'gitrepository' => 'https://github.com/michael-milette/moodle-local_mailtest', // mandatory
        'gitbranch' => '', // optional ; git branch (incompatible with gitrevision)
        'gitrevision' => '', // optional ; precise git revision (hash or tag)  (incompatible with gitbranch)
    ],
```

You have to adjust the settings (verbosity level) and to fill the information on the wanted plugins.

## Execution

You can then launch `php gitplugins.php` with one of the options:

* `--gen-config` generates a sample gitplugins.conf file
* `--check` checks the consistency of 'gitplugins.conf'
* `--diag` displays a diagnostic of all declared plugins
* `--list` lists all declared plugins, without diagnostic
* `--status` launches a `git status` on each declared plugin
* `--install-all` installs all plugins that are not already present
* `--install=<name>` installs this plugin according to gitplugins.conf
* `--upgrade-all` upgrades all plugins already installed
* `--upgrade=<name>` upgrades this plugin according to gitplugins.conf
* `--cleanup` "removes" all plugins in an inconsistent state (by **renaming** them, so restoration is possible)
* `--gen-exclude` generates a chunk of lines to insert in your .git/info/exclude file

"Cleaned" repositories are renamed to `<orig-name>.back-<timestamp>`, so you can
easily find and restore them if needed.

## Installation 

The simplest way to install `gitplugins` is to download directly, with `wget` (or `curl`) :

``` 
wget https://raw.githubusercontent.com/silecs/moodle-gitplugins/master/gitplugins.php
```

Alternatively, you can easily copy-paste the entire code in a new file.
