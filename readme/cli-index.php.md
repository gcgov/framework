# /app/cli/index.php
CLI requests should point to `/app/cli/index.php`

```php
$projectRootDirectory = dirname(__FILE__).'/../../';
include_once($projectRootDirectory.'vendor/autoload.php');

$_SERVER[ 'REQUEST_METHOD' ] = 'CLI';
$_SERVER['REQUEST_URI'] = $argv[1];
$_SERVER[ 'REMOTE_ADDR' ] = '127.0.0.1';

$framework = new \gcgov\framework\framework( $projectRootDirectory );
echo $framework->runApp();
```

Create a Windows batch file to load the app (cli.bat)
`php.exe -c php.ini -f /app/cli/index.php %1`

Call the batch file to run the app via CLI
`> cli.bat /structure/cleanup`
