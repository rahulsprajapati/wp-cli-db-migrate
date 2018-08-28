<?php
/**
 * Class for rename db prefix.
 */

class Rename_DB_Prefix {

	public $old_prefix;
	public $new_prefix;
	public $is_dry_run;

	/**
	 * Rename_DB_Prefix constructor.
	 *
	 * @param string $old_prefix The old database prefix which need to be update.
	 * @param string $new_prefix The new database prefix.
	 * @param string $is_dry_run Flag to preview which data would be updated.
	 */
	function __construct( $old_prefix, $new_prefix, $is_dry_run ) {

		$this->old_prefix = $old_prefix;
		$this->new_prefix = $new_prefix;
		$this->is_dry_run = $is_dry_run;
	}

	/**
	 * Rename WordPress database prefix.
	 *
	 * @throws \WP_CLI\ExitException
	 */
	public function run() {

		try {
			$is_multisite = is_multisite();

			if ( $is_multisite ) {
				\WP_CLI::line( 'Running for Multisite:' );
			} else {
				\WP_CLI::line( 'Running for Single WP Site:' );
			}

			\WP_CLI::line( "Rename Table Prefix:" );
			$this->rename_wordpress_tables();

			\WP_CLI::line( "Update prefix in wp-config.php:" );
			$this->update_wp_config();

			\WP_CLI::line( "Update User Meta Prefix:" );
			$this->update_usermeta_table();

			\WP_CLI::line( "Update User Role Prefix:" );
			if ( $is_multisite ) {
				$this->update_blog_user_roles_options();
			} else {
				$this->update_user_roles_options();
			}

			\WP_CLI::success( 'Successfully renamed database prefix.' );
		} catch ( Exception $exception ) {
			\WP_CLI::error( $exception->getMessage(), false );
			\WP_CLI::error( "You should check your site to see if it's broken. If it is, you can fix it by restoring your `wp-config.php` file and your database from backups." );
		}
	}

	/**
	 * Rename all of WordPress' database tables
	 *
	 * @throws Exception
	 */
	protected function rename_wordpress_tables() {

		global $wpdb;

		$show_table_query = sprintf(
			'SHOW TABLES LIKE "%s%%";',
			$wpdb->esc_like( $this->old_prefix )
		);

		$tables = $wpdb->get_results( $show_table_query, ARRAY_N );

		if ( empty( $tables ) ) {
			throw new Exception( 'MySQL error: No tables Found to rename tables.');
		}

		foreach ( $tables as $table ) {
			$table = substr( $table[0], strlen( $this->old_prefix ) );

			$rename_query = sprintf(
				"RENAME TABLE `%s` TO `%s`;",
				$this->old_prefix . $table,
				$this->new_prefix . $table
			);

			\WP_CLI::debug( $rename_query );

			if ( $this->is_dry_run ) {
				\WP_CLI::line( "{$this->old_prefix}{$table} table will be rename with {$this->new_prefix}{$table}" );
				continue;
			}

			if ( false === $wpdb->query( $rename_query ) ) {
				throw new Exception( 'MySQL error: ' . $wpdb->last_error );
			}
		}

		$table_count = count( $tables );

		if ( $this->is_dry_run ) {
			\WP_CLI::line( "Total {$table_count} DB Tables prefix will be updated with {$this->new_prefix} prefix." );
		} else {
			\WP_CLI::line( "{$table_count} DB Tables prefix updated successfully." );
		}
	}

	/**
	 * Update the prefix in `wp-config.php`
	 *
	 * @throws Exception
	 */
	protected function update_wp_config() {

		if ( $this->is_dry_run ) {
			\WP_CLI::line( "wp-config.php database prefix will update." );
			return;
		}

		$wp_config_path     = \WP_CLI\Utils\locate_wp_config();

		$wp_config_contents = file_get_contents( $wp_config_path );

		$search_pattern     = '/(\$table_prefix\s*=\s*)([\'"]).+?\\2(\s*;)/';
		$replace_pattern    = "\${1}'{$this->new_prefix}'\${3}";

		$wp_config_contents = preg_replace( $search_pattern, $replace_pattern, $wp_config_contents, -1, $number_replacements );

		if ( 0 === $number_replacements ) {
			throw new Exception( "Failed to replace `{$this->new_prefix}` in `wp-config.php`." );
		}

		if ( ! file_put_contents( $wp_config_path, $wp_config_contents ) ) {
			throw new Exception( "Failed to update updated `wp-config.php` file." );
		}

		\WP_CLI::line( "wp-config.php database prefix updated successfully." );
	}

	/**
	 * Update rows in the `usermeta` tables.
	 */
	protected function update_usermeta_table() {
		global $wpdb;

		$old_prefix = $this->old_prefix;
		$new_prefix = $this->new_prefix;

		$rows = $wpdb->get_results( "SELECT meta_key FROM `{$new_prefix}usermeta` WHERE meta_key LIKE '{$old_prefix}%';" );

		if ( empty( $rows ) ) {

			\WP_CLI::line( "No usermeta found for {$new_prefix}usermeta table." );

			return;
		}

		foreach ( $rows as $row ) {

			$new_key = $new_prefix . substr( $row->meta_key, strlen( $old_prefix ) );

			$update_query = $wpdb->prepare(
				"UPDATE `{$new_prefix}usermeta` SET meta_key=%s WHERE meta_key=%s LIMIT 1;",
				$new_key,
				$row->meta_key
			);

			\WP_CLI::debug( $update_query );

			if ( $this->is_dry_run ) {
				\WP_CLI::line( "Usermeta key will update from {$row->meta_key} => {$new_key} in `{$new_prefix}usermeta` table." );
				continue;
			}

			if ( false === $wpdb->query( $update_query ) ) {
				\WP_CLI::line( "Usermeta key update failed from {$row->meta_key} => {$new_key} in `{$new_prefix}usermeta` table." );
				continue;
			}
		}

		if ( $this->is_dry_run ) {
			\WP_CLI::line( count( $rows ) . ": User's meta for {$new_prefix}usermeta table will be update." );
		} else {
			\WP_CLI::line( count( $rows ) . ": User's meta for {$new_prefix}usermeta table is successfully updated." );
		}

	}

	/**
	 * Update user_roles rows prefix in all of the site `options` tables
	 *
	 * @throws Exception
	 */
	protected function update_blog_user_roles_options() {

		global $wpdb;

		$sites = $wpdb->get_col( "SELECT blog_id FROM `{$this->new_prefix}blogs` ORDER BY blog_id ASC LIMIT 100;" );

		if ( empty( $sites ) ) {
			throw new Exception( 'No sites found for update user role options tables.' );
		}

		foreach ( $sites as $site_id ) {

			$new_prefix = $this->new_prefix;
			$old_prefix = $this->old_prefix;

			// Add blog id in prefix from 2nd blog/site.
			if ( (int) $site_id > 1 ) {
				$new_prefix .= $site_id . '_';
				$old_prefix .= $site_id . '_';
			}

			$update_query = $wpdb->prepare(
				"UPDATE `{$new_prefix}options` SET option_name = %s WHERE option_name = %s LIMIT 1;",
				$new_prefix . 'user_roles',
				$old_prefix . 'user_roles'
			);

			\WP_CLI::line( "Update User Role Prefix for site id: {$site_id}" );

			\WP_CLI::debug( $update_query );

			if ( $this->is_dry_run ) {
				continue;
			}

			$wpdb->query( $update_query );
		}

		\WP_CLI::line( "All site's option table keys checked and updated with respective db prefix." );
	}

	/**
	 * Update user_roles rows prefix in the `options` table
	 *
	 * @throws Exception
	 */
	protected function update_user_roles_options() {
		global $wpdb;

		$update_query = $wpdb->prepare(
			"UPDATE `{$this->new_prefix}options` SET option_name = %s WHERE option_name = %s LIMIT 1;",
			$this->new_prefix . 'user_roles',
			$this->old_prefix . 'user_roles'
		);

		\WP_CLI::debug( $update_query );

		if ( $this->is_dry_run ) {
			return;
		}

		if ( false === $wpdb->query( $update_query ) ) {
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}
	}
}
