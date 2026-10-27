<?php
/**
 * Plugin Name: DigitalOwnership Publication Verification
 * Plugin URI: https://digitalownership.squaredant.com/
 * Description: Creates fixed, verifiable versions of WordPress posts and pages.
 * Version: 0.8.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: SquaredAnt GmbH
 * Author URI: https://squaredant.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: digitalownership-publication-verification
 */

defined('ABSPATH') || exit;

/*
 * Copyright (C) 2026 SquaredAnt GmbH
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

define('DOVP_VERSION', '0.8.0');
define('DOVP_FILE', __FILE__);
define('DOVP_DIR', plugin_dir_path(__FILE__));
define('DOVP_URL', plugin_dir_url(__FILE__));

require_once DOVP_DIR . 'includes/class-dovp-plugin.php';

register_activation_hook(__FILE__, array('DOVP_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('DOVP_Plugin', 'deactivate'));

DOVP_Plugin::instance();
