# /www/index.php
Your webserver should be configured to use `/www` as the root directory for requests and should rewrite app URLs to `/www/index.php?R0={original-route}'`

For example, `/widget/getAll` should be rewritten to `index.php?R0=/widet/getAll'` 

Rewrite rules are included in the IIS web.config files when starting with the framework scaffolding project.

```php
include_once('../vendor/autoload.php');

$projectRootDirectory = dirname(__FILE__).'/../';
$framework = new \gcgov\framework\framework( $projectRootDirectory  );
echo $framework->runApp();
```
