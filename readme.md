WP CLI DB Migrate
===

A WP-CLI command to run common migration commands. like: rename db prefix, merge two mu site users table.. etc.

#### ⚠️ Note: Take database backup before running this commands. If some error occurs it might break your site. 

## Commands

### Rename Database Tables :

Command will do following things internally:

1. Rename prefix of all database tables
2. Update prefix into wp-config.php
3. Update all user meta key prefix. i.e wp_capabilities, wp_user_level keys.
4. Update prefix into option table for user roles.
  

```bash
wp db-migrate rename_prefix <old_prefix> <new_prefix>
```
Example: 
```bash
wp db-migrate rename_prefix wp_old_ wp_new_
```

### Merge user tables.

Requirements:

1. Rename existing users table prefix ( other then the WP site have currently ). i.e wp_old_users, wp_old_usermeta. 
2. Export users table from other site from which we want to merge user tables and import it into your database. ( Update the prefix of this users table with current table if it's not same already. Also, run rename prefix command for live site db first to update prefix in users table. ) i.e wp_users, wp_usermeta

Command will do following things internally:

1. Ask you for site id which is migrated and need to be update the authors id in that site. There is option to run it for all sites.
2. Case 1: Migrate Same Users [same Id, email, user_login] Meta data.
3. Case 2: Migrate Diff/New Users [Diff: Id, email, user_login]. Add new user and usermeta.
4. Update post and comment table authors id as per migrated data.

```bash
wp db-migrate merge_user_table <from_user_table_prefix> <to_user_table_prefix>
```
Example: 
```bash
wp db-migrate rename_prefix wp_old_ wp_new_
```
