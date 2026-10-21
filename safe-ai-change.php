<?php
/**
 * Plugin Name:       Safe AI Change
 * Description:       Lets a shop manager change one product short description from a written request, with a preview, store checks and an automatic rollback.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Nicolas Lecocq
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       safe-ai-change
 *
 * @package Safe_AI_Change
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-safe-ai-change.php';

( new Safe_AI_Change( __FILE__ ) )->register();
