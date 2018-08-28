<?php
/**
 * Class for merging two site's users table.
 */

class Merge_MU_User_Table {

	private $from_prefix;
	private $to_prefix;
	private $is_dry_run;

	private $from_users;
	private $from_usermeta;

	private $to_users;
	private $to_usermeta;

	private $is_multisite;

	private $migrate_site_id;

	/**
	 * Merge_MU_User_Table constructor.
	 *
	 * @param string $from_prefix The old database prefix which need to be update.
	 * @param string $to_prefix The new database prefix.
	 * @param string $is_dry_run Flag to preview which data would be updated.
	 */
	function __construct( $from_prefix, $to_prefix, $is_dry_run ) {

		$this->from_prefix = $from_prefix;
		$this->to_prefix   = $to_prefix;
		$this->is_dry_run  = $is_dry_run;

		$this->from_users    = $from_prefix . 'users';
		$this->from_usermeta = $from_prefix . 'usermeta';

		$this->to_users    = $to_prefix . 'users';
		$this->to_usermeta = $to_prefix . 'usermeta';
	}

	/**
	 * Merge users table.
	 *
	 * @throws \WP_CLI\ExitException
	 */
	public function run() {

		$is_multisite = is_multisite();

		if ( $is_multisite ) {
			\WP_CLI::line( 'Running for Multisite:' );

			$question = 'Provide site id which needed to be migrate. ( Enter "ALL" to run migration for all sites ): ';
			$site_id = readline( $question );

			if ( is_numeric( $site_id ) ) {
				$this->migrate_site_id = (int) $site_id;

				$sites = get_sites( [ 'ID' => $this->migrate_site_id ] );

				if ( empty( $sites ) ) {
					WP_CLI::error( 'Sorry, there is no site id/table found for site id ' . $site_id );
				}
			}

			if ( empty( $this->migrate_site_id ) ) {
				return;
			}
		} else {
			\WP_CLI::line( 'Running for Single WP Site:' );
		}

		$this->is_multisite = $is_multisite;

		try {

			\WP_CLI::line( 'Case 1: Migrate Common Users [Same: Id, email, user_login].' );
			$this->migrate_common_users();

			\WP_CLI::line( 'Case 2: Migrate Diff/New Users [Diff: Id, email, user_login].' );
			$this->migrate_new_users();

			// TODO: Step 3: Same email id and user_login users but diff id.

			\WP_CLI::success( 'Successfully Merged User table.' );
		} catch ( Exception $exception ) {
			\WP_CLI::error( $exception->getMessage(), false );
			\WP_CLI::error( "You should check your site to see if it's broken. If it is, you can fix it by restoring your database from backups." );
		}
	}

	/**
	 * Merge two user tables.
	 * Case 1: Migrate Same Users [same Id, email, user_login] Meta data.
	 */
	protected function migrate_common_users() {
		global $wpdb;

		// Get common user ids.
		$common_users = "SELECT u1.ID 
			FROM {$this->to_users} u1
			RIGHT JOIN {$this->from_users} u2
			ON ( u1.ID = u2.ID 
			AND u1.user_login = u2.user_login 
			AND u1.user_email = u2.user_email ) 
			WHERE u1.ID IS NOT NULL";

		// Get missing meta data of common users.
		$common_users_extra_meta_sql = "SELECT u4.user_id, u4.meta_key, u4.meta_value 
			FROM {$this->to_usermeta} u3 
			RIGHT JOIN {$this->from_usermeta} u4 
			ON ( u3.user_id = u4.user_id AND u3.meta_key = u4.meta_key ) 
			WHERE  u3.meta_key IS NULL
			AND u4.user_id IN ( {$common_users} )
			 order by u4.user_id";

		$rows = $wpdb->get_results( $common_users_extra_meta_sql );

		\WP_CLI::line( 'Total common user\'s meta migrate: ' . count( $rows ) );

		// Migrate Same Users [same Id, email, user_login] Meta data.
		$sql = "INSERT INTO {$this->to_usermeta} (user_id, meta_key, meta_value) ( {$common_users_extra_meta_sql} );";

		\WP_CLI::debug( $sql );

		if ( ( ! $this->is_dry_run ) && ( false === $wpdb->query( $sql ) ) ) {
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}

		\WP_CLI::success( 'Case 1' );
	}

	/**
	 * Merge two user tables.
	 * Case 2: Migrate Diff/New Users [diff Id, email, user_login]
	 */
	protected function migrate_new_users() {
		global $wpdb;

		$new_users_data_sql = "SELECT u2.user_login, u2.user_pass, u2.user_nicename, u2.user_email, u2.user_url, u2.user_registered, u2.user_activation_key, u2.user_status, u2.display_name, u2.id
 			FROM {$this->to_users} u1 
 			RIGHT JOIN {$this->from_users} u2 
 			ON ( u1.user_login = u2.user_login 
 			AND u1.ID = u2.ID 
 			AND u1.user_email = u2.user_email )
 			 WHERE u1.user_login IS NULL";

		$rows = $wpdb->get_results( $new_users_data_sql );

		if ( empty( count( $rows ) ) ) {
			\WP_CLI::line( "There is no new users to Migrate" );

			return;
		}

		\WP_CLI::line( "Total new users found: " . count( $rows ) );

		$alter_user_table_sql = "ALTER TABLE {$this->to_users} ADD COLUMN old_user_id bigint(20) unsigned;";

		\WP_CLI::debug( $alter_user_table_sql );

		if ( $this->is_dry_run ) {
			\WP_CLI::line( "Alter user table: added old_user_id columns." );
		} else {
			if ( false === $wpdb->query( $alter_user_table_sql ) ) {
				throw new Exception( 'MySQL error: ' . $wpdb->last_error );
			}
		}

		// Insert new users to user table.
		$sql = "INSERT INTO {$this->to_users} (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name, old_user_id) 
( {$new_users_data_sql} );";

		\WP_CLI::debug( $sql );

		if ( false === $wpdb->query( $sql ) ) {
			\WP_CLI::line( "Failed: Add new users." );
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}

		\WP_CLI::line( "Success: Added new users." );

		\WP_CLI::line( "Migrate new user's meta data." );

		$new_user_meta_sql = "SELECT u.id, m2.meta_key, m2.meta_value 
			FROM {$this->from_usermeta} AS m2
    		JOIN {$this->to_users} AS u 
    		ON m2.user_id = u.old_user_id";

		$rows = $wpdb->get_results( $new_user_meta_sql );

		if ( empty( count( $rows ) ) ) {
			\WP_CLI::line( "There is no new user's meta found to Migrate" );
		} else {
			\WP_CLI::line( "Total new user's meta: " . count( $rows ) );

			$insert_meta = "INSERT INTO {$this->to_usermeta} (user_id, meta_key, meta_value) ( {$new_user_meta_sql} );";

			\WP_CLI::debug( $insert_meta );

			if ( ( ! $this->is_dry_run ) && ( false === $wpdb->query( $insert_meta ) ) ) {
				\WP_CLI::line( "Failed: Insert User Meta." );
				throw new Exception( 'MySQL error: ' . $wpdb->last_error );
			}

			\WP_CLI::line( "Success: Insert User Meta." );
		}


		\WP_CLI::line( "Insert Old User id reference in meta as `old_site_user_id` meta key" );

		$insert_old_user_ref_meta = "INSERT INTO {$this->to_usermeta} (user_id, meta_key, meta_value)
  SELECT {$this->to_usermeta}.ID, 'old_site_user_id' as meta_key, {$this->to_usermeta}.old_user_id
  FROM {$this->to_usermeta};";

		\WP_CLI::debug( $insert_old_user_ref_meta );

		if ( ( ! $this->is_dry_run ) && ( false === $wpdb->query( $insert_old_user_ref_meta ) ) ) {
			\WP_CLI::line( "Failed: Add New Users Meta ref." );
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}

		\WP_CLI::line( "Success: Add New Users Meta ref." );

		if ( $this->is_multisite ) {
			$migrated_site = $this->migrate_site_id;
			$sites = get_sites();

			if ( empty( $sites ) ) {
				\WP_CLI::line( "No site found in multisite." );
				return;
			}

			if ( 'all' === strtolower( $migrated_site ) ) {
				foreach ( $sites as $site ) {
					if ( 1 === (int) $site->blog_id ) {
						\WP_CLI::line( "SKIP SITE ID." .  $site->blog_id );
						continue;
					}

					$this->migrate_new_user_to_site( (int) $site->blog_id );
				}
			} elseif ( (int) $migrated_site > 0 ) {
				$this->migrate_new_user_to_site( $migrated_site );
			}
		}

		$alter_user_table_sql = "ALTER TABLE {$this->to_users} DROP COLUMN old_user_id;";

		\WP_CLI::debug( $alter_user_table_sql );

		if ( $this->is_dry_run ) {
			\WP_CLI::line( "Alter user table: drop old_user_id columns." );
		} else {
			if ( false === $wpdb->query( $alter_user_table_sql ) ) {
				throw new Exception( 'MySQL error: ' . $wpdb->last_error );
			}
		}

		\WP_CLI::success( 'Case 2' );
	}

	/**
	 * Migrate new users to site.
	 *
	 * @param $site_id
	 *
	 * @throws Exception
	 */
	protected function migrate_new_user_to_site( $site_id ) {
		global $wpdb;

		\WP_CLI::line( "Migrate new users for SITE ID." .  $site_id );

		$to_prefix = $this->to_prefix;

		if ( $site_id > 1 ) {
			$to_prefix = $site_id . '_';
		}

		\WP_CLI::line( "Migrate Posts Author SITE ID." .  $site_id );

		$posts_author_sql = "UPDATE `{$to_prefix}posts` post, {$this->to_users} u1
    SET post.post_author = u1.id
    WHERE post.post_author = u1.old_user_id;";

		\WP_CLI::debug( $posts_author_sql );

		if ( ( ! $this->is_dry_run ) && ( false === $wpdb->query( $posts_author_sql ) ) ) {
			\WP_CLI::line( "Failed: Migrate Posts Authors." );
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}

		\WP_CLI::line( "Success: Migrate Posts Authors." );

		\WP_CLI::line( "Migrate Comment Authors SITE ID." .  $site_id );

		$comment_author_sql = "UPDATE `{$to_prefix}comments` comment, {$this->to_users} u1
SET comment.user_id = u1.id
WHERE comment.user_id = u1.old_user_id;";

		\WP_CLI::debug( $comment_author_sql );

		if ( ( ! $this->is_dry_run ) && ( false === $wpdb->query( $comment_author_sql ) ) ) {
			\WP_CLI::line( "Failed: Migrate Comment Authors" );
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}

		\WP_CLI::line( "Success: Migrate Comment Authors." );
	}
}