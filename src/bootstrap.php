<?php
/**
 * Versioned bootstrap.
 *
 * Only the winning copy's bootstrap is ever loaded, so everything below is
 * defined exactly once per request. This is where all the logic lives that the
 * frozen registry deliberately does not.
 *
 * Files are required explicitly rather than autoloaded. Composer's autoloader
 * resolves a class name to whichever copy it happened to map first, which on a
 * site with three bundled versions is not necessarily the one the registry
 * chose — an inconsistency Action Scheduler documents but cannot fix.
 *
 * @package UserTags
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Roles.php';
require_once __DIR__ . '/Taxonomy.php';
require_once __DIR__ . '/Catalogue.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Runtime.php';
require_once __DIR__ . '/Query.php';
require_once __DIR__ . '/Cleanup.php';
require_once __DIR__ . '/Csv.php';
require_once __DIR__ . '/Library.php';
require_once __DIR__ . '/functions.php';

UserTags\Library::boot( '1.1.0', __DIR__ );
