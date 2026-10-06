<?php

namespace gcgov\framework\models\config\services;


/**
 * Configuration for the user CRUD service. It has none: the role names it enforces
 * (User.Read / User.Write) are part of its contract, not a per-application setting.
 * The block exists so that `"userCrud": {}` can turn the service on.
 */
class userCrud extends \andrewsauder\jsonDeserialize\jsonDeserialize {

}
