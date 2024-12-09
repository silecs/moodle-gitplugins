# gitplugins - A cli administration tool to help deploying Moodle plugins via Git

## Overview

This Moodle tool is a cli tool (executable phar) to help deploying Moodle plugins via Git,
but without using submodules.

You need to install one single file, `gitp.phar`, where you want (inside or outside the Moodle tree).
You can use the same phar to manage several instances, a bit like `composer`.

## .gitplugins.conf

The `.gitplugins.conf` file has two sections :

* a short list of settings (logfile, verbosity),
* and a list of the plugins you want to manage (install, upgrade...)

This file follows a standard format described by `gitp.phar --gen-config`.
You have to adjust the settings (verbosity level) and to fill the information on the wanted plugins.

## Execution

You must define an environment variable `MOODLE_ROOT` with the root of the Moodle instance.
You can then launch `php gitp.phar` with one of the commands available with `php gitp.phar --help`. The most common are:

* `--diag` displays a diagnostic of all declared plugins
* `--install-all` installs all plugins that are not already present
* `--install=<name>` installs this plugin according to gitplugins.conf
* `--upgrade-all` upgrades all plugins already installed
* `--upgrade=<name>` upgrades this plugin according to gitplugins.conf
* `--cleanup` "removes" all plugins in an inconsistent state (by **renaming** them, so restoration is possible)

Please note that "cleaned" repositories are renamed to `<orig-name>.gpcleanup-<timestamp>`, so you can
easily find and restore them if needed.

## Installation 

The simplest way to install `gitp.phar` is to download the latest release directly from github :
<https://github.com/silecs/moodle-gitplugins/releases>
