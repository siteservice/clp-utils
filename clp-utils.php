<?php

namespace SiteService;

// Bail if WP-CLI is not present.
if (! class_exists('WP_CLI')) {
	return;
}

use WP_CLI;

require_once __DIR__ . '/src/clp.php';

WP_CLI::add_command('clp-utils',	__NAMESPACE__ . '\\Clp');
