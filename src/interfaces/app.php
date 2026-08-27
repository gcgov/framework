<?php

namespace gcgov\framework\interfaces;


/**
 * The application entry class — \app\app.
 *
 * It declares no methods of its own beyond the lifecycle hooks, but it is not optional:
 * \gcgov\framework\config derives every path in the framework by reflecting on this
 * class's file location, so an application without it cannot resolve its own root.
 *
 * Framework Services used to be registered here, by returning their namespaces from
 * registerFrameworkServiceNamespaces(). They are now declared in the `services` section
 * of config.json, so that activation and configuration are one statement rather than two.
 */
interface app extends lifecycle\before, lifecycle\after {

}
