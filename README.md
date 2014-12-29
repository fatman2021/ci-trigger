ci-trigger
==========

Script that restarts one or more builds for master on travis-ci.org and travis-ci.com. Use with cron to
restart your Travis CI builds on a schedule.

## Installation

To install, just run:

    $ composer install

Nothing else is needed.

## Running the script

To execute the script, you will need:

* A github user token that has read access to the repositories you want to restart builds for, and that travis knows about.
  Read [this](https://help.github.com/articles/creating-an-access-token-for-command-line-use/) to learn how to create a
  github user token. Then make sure to login to Travis (pro & normal).

To restart all of your builds, run:

    $ php main.php --github-token=...

To restart only some of your builds, you can supply a regex to the `--include` option. Every repo whose slug matches this
regex will be included in the list of builds to restart. Others will be skipped. Eg:

    $ php main.php --github-token=... --include="\\/plugin-"

The `--dry-run` option can be used for testing.