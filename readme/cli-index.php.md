# CLI entry

> **Preferred**: use the framework's `gf` tool — `vendor/bin/gf cli {url-path}` — which needs no
> per-app entry file or batch wrappers, adds route listing/completion (`gf cli:list`), Xdebug via
> `--debug`, and real exit codes for schedulers. See [gf.md](gf.md). The file below is the legacy
> entry and remains supported for existing apps.

# /app/cli/index.php (legacy)
CLI requests should point to `/app/cli/index.php`

```php
$projectRootDirectory = dirname(__FILE__).'/../../';
include_once($projectRootDirectory.'vendor/autoload.php');

$_SERVER[ 'REQUEST_METHOD' ] = 'CLI';
$_SERVER['REQUEST_URI'] = $argv[1];
$_SERVER[ 'REMOTE_ADDR' ] = '127.0.0.1';

$framework = new \gcgov\framework\framework();
echo $framework->runApp();
```

`$argv[1]` should contain the route path to execute (for example `/structure/cleanup`).

Create a Windows batch file to load the app (cli.bat)
`php.exe -c php.ini -f /app/cli/index.php %1`

Call the batch file to run the app via CLI
`> cli.bat /structure/cleanup`

The gf equivalent of both steps is simply:
`> vendor\bin\gf cli /structure/cleanup`
