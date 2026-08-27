<?php

namespace gcgov\framework\models\config\services;


/**
 * Configuration for the OpenAPI documentation service. It has none: the route it serves
 * and the directories it scans are fixed by the framework's own layout. The block exists
 * so that `"documentation": {}` can turn the service on.
 */
class documentation extends \andrewsauder\jsonDeserialize\jsonDeserialize {

}
