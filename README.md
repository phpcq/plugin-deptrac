# deptrac plugin for phpcq.

This plugin provides [deptrac](https://github.com/deptrac/deptrac) integration for phpcq.

Since deptrac no longer ships a PHAR, the plugin pulls in `deptrac/deptrac` (`^4.0`) as a
Composer requirement and runs the installed `vendor/bin/deptrac` through the PHP interpreter.
