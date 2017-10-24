# gitplugins - A cli administration tool to help deploying Moodle plugins via Git

This Moodle tool is a simple cli script to help deploying Moodle plugins via Git,
but without using submodules.

You need to install only 2 files in the /admin/cli directory:
- the `gitplugins.php` script
- the `gitplugins.conf` file ; you can use `gitplugins.conf.dist` as a basis

The `gitplugins.conf` file is a list of all the plugins you want to manage
(install, upgrade...) with the format:
```
    'local/mailtest' => [
        'path' => '/local/mailtest', // mandatory ; path from the moodle root
        'gitrepository' => 'https://github.com/michael-milette/moodle-local_mailtest', // mandatory
        'gitbranch' => '', // optional ; git branch (incompatible with gitrevision)
        'gitrevision' => '', // optional ; precise git revision (hash or tag)  (incompatible with gitbranch)
    ],
```

You can then launch `php gitplugins.php` with one of the options:

* `--check` checks the consistency of 'gitplugins.conf'
* `--diag` displays a diagnostic of all declared plugins
* `--status` launches a `git status` on each declared plugin
* `--install` installs all plugins that are not already present (`git clone`)
* `--upgrade` upgrades all plugins already installed (`git fetch` + `git checkout`)
* `--cleanup` "removes" all plugins in an inconsistent state (by **renaming** them, so restoration is possible)

"Cleaned" repositories are renamed to `<orig-name>.back-<timestam>`, so you can
easily find and restore them if needed.
