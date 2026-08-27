<?php

namespace gcgov\framework\models\config\services\auth;


/**
 * Configuration for the Microsoft front-end token exchange. It has none of its own: the
 * Microsoft application credentials it needs are the top-level `microsoft` section, which
 * the OAuth provider reads too. The block exists so the provider can be named explicitly.
 */
class msFront extends \andrewsauder\jsonDeserialize\jsonDeserialize {

}
