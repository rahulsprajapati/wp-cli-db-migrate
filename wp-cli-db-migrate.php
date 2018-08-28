<?php
/**
 * Plugin Name:     WP CLI DB Migrate
 * Plugin URI:      https://github.com/rahulsprajapati/wp-cli-db-migrate
 * Description:     A WP-CLI command to run common migration commands. like: rename db prefix, merge two mu site users table.. etc.
 * Version:         0.1
 * Author:          Rahul Prajapati
 * Author URI:      http://rahulprajapati.me/
 * License:         GPL-2.0+
 * License URI:     http://www.gnu.org/licenses/gpl-2.0.txt
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

require_once __DIR__ . '/helper-class/class-merge-mu-user-table.php';
require_once __DIR__ . '/helper-class/class-rename-db-prefix.php';

/**
 * Performs database migration related tasks.
 *
 * Class WP_CLI_DB_Migrate
 */
class WP_CLI_DB_Migrate extends \WP_CLI_Command {

	public $is_dry_run = false;

	/**
	 * Rename WordPress's database prefix.
	 *
	 * ## OPTIONS
	 *
	 * <old_prefix>
	 * : The old database prefix
	 *
	 * <new_prefix>
	 * : The new database prefix
	 *
	 * [--dry-run]
	 * : Preview which data would be updated.
	 *
	 * ## EXAMPLES
	 *
	 * wp db-migrate rename_prefix old_prefix_ new_prefix_
	 * wp db-migrate rename_prefix wp23_ wp_
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	function rename_prefix( $args, $assoc_args ) {
		$old_prefix = $args[0];
		$new_prefix = $args[1];

		$is_dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$confirm_msg = sprintf(
			"\nAre you sure you want to rename %s's database prefix from `%s` to `%s`?",
			parse_url( site_url(), PHP_URL_HOST ),
			$old_prefix,
			$new_prefix
		);

		$this->confirm( $confirm_msg );

		$rename_db = new Rename_DB_Prefix( $old_prefix, $new_prefix, $is_dry_run );

		$rename_db->run();
	}

	/**
	 * Merge User tables.
	 *
	 * ## OPTIONS
	 *
	 * <from_user_table_prefix>
	 * : The database prefix which is going to be merge from.
	 *
	 * <to_user_table_prefix>
	 * : The database prefix in which user table will merged.
	 *
	 * [--dry-run]
	 * : Preview which data would be updated.
	 *
	 *
	 * ## EXAMPLES
	 *
	 * wp db-migrate merge_user_table from_user_table_prefix_ to_user_table_prefix_
	 * wp db-migrate merge_user_table wp_0_ wp_
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	function merge_user_table( $args, $assoc_args ) {

		$from_prefix = $args[0];
		$to_prefix   = $args[1];

		$is_dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$confirm_msg = sprintf(
			'Are you sure you want to merge user table from `%1$susers` to `%2$susers` and from `%1$susermeta` to `%2$susermeta`?',
			$from_prefix,
			$to_prefix
		);

		$this->confirm( $confirm_msg );

		$merge_user_tables = new Merge_MU_User_Table( $from_prefix, $to_prefix, $is_dry_run );

		$merge_user_tables->run();

	}

	/**
	 * Confirm that the user wants to rename the prefix
	 */
	protected function confirm( $confirm_msg = '' ) {

		if ( $this->is_dry_run ) {
			\WP_CLI::line( 'Running in dry run mode.' );
			return;
		}

		if ( empty( $confirm_msg ) ) {
			$confirm_msg = "Are you sure you want to continue?";
		}

		\WP_CLI::warning( "Use this at your own risk. If something goes wrong, it could break your site since it's db operation. Before running this, make sure to back up your db: run `wp db export`." );

		\WP_CLI::confirm( $confirm_msg );
	}
}

\WP_CLI::add_command( 'db-migrate', 'WP_CLI_DB_Migrate' );
