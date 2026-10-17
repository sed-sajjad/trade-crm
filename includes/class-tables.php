<?php
namespace TradeCRM;

defined( 'ABSPATH' ) || exit;

final class Tables {
	public static function names(): array {
		global $wpdb;
		return array(
			'contacts'             => $wpdb->prefix . 'trade_crm_contacts',
			'numbers'              => $wpdb->prefix . 'trade_crm_contact_numbers',
			'industries'           => $wpdb->prefix . 'trade_crm_industries',
			'contact_industries'   => $wpdb->prefix . 'trade_crm_contact_industries',
			'activities'           => $wpdb->prefix . 'trade_crm_activities',
		);
	}

	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::names();
		$queries = array(
			"CREATE TABLE {$t['contacts']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				company varchar(190) NOT NULL DEFAULT '',
				search_text text NOT NULL,
				email varchar(190) NOT NULL DEFAULT '',
				website varchar(2048) NOT NULL DEFAULT '',
				telegram varchar(190) NOT NULL DEFAULT '',
				instagram varchar(190) NOT NULL DEFAULT '',
				country_code char(2) NOT NULL DEFAULT 'IR',
				city varchar(190) NOT NULL DEFAULT '',
				city_normalized varchar(190) NOT NULL DEFAULT '',
				source varchar(32) NOT NULL DEFAULT '',
				initial_note text NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'new',
				assigned_user_id bigint(20) unsigned NULL,
				assigned_user_name varchar(190) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL,
				created_by_name varchar(190) NOT NULL DEFAULT '',
				updated_by bigint(20) unsigned NOT NULL,
				updated_by_name varchar(190) NOT NULL DEFAULT '',
				followup_at datetime NULL,
				followup_topic varchar(190) NOT NULL DEFAULT '',
				archived_at datetime NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY active_created (archived_at, created_at, id),
				KEY active_status (archived_at, status, id),
				KEY active_assignee (archived_at, assigned_user_id, id),
				KEY active_followup (archived_at, followup_at, id),
				KEY city_country (country_code, city_normalized, id)
			) $charset ENGINE=InnoDB;",
			"CREATE TABLE {$t['numbers']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				contact_id bigint(20) unsigned NOT NULL,
				number_e164 varchar(32) NOT NULL,
				national_digits varchar(32) NOT NULL,
				is_phone tinyint(1) NOT NULL DEFAULT 0,
				is_whatsapp tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY unique_number (number_e164),
				KEY contact_lookup (contact_id),
				KEY national_lookup (national_digits)
			) $charset ENGINE=InnoDB;",
			"CREATE TABLE {$t['industries']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(120) NOT NULL,
				name_normalized varchar(120) NOT NULL,
				seed_key varchar(64) NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY unique_parent_name (parent_id, name_normalized),
				UNIQUE KEY unique_seed_key (seed_key),
				KEY parent_active (parent_id, is_active, id),
				KEY active_name (is_active, name_normalized)
			) $charset ENGINE=InnoDB;",
			"CREATE TABLE {$t['contact_industries']} (
				contact_id bigint(20) unsigned NOT NULL,
				industry_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (contact_id, industry_id),
				KEY industry_contacts (industry_id, contact_id)
			) $charset ENGINE=InnoDB;",
			"CREATE TABLE {$t['activities']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				contact_id bigint(20) unsigned NULL,
				event_type varchar(32) NOT NULL,
				event_data longtext NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				actor_name varchar(190) NOT NULL DEFAULT '',
				request_id char(36) NULL,
				request_hash char(64) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY actor_request (actor_user_id, request_id),
				KEY contact_activity (contact_id, id),
				KEY actor_activity (actor_user_id, id),
				KEY activity_created (created_at, id)
			) $charset ENGINE=InnoDB;",
		);

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}

		$industry_table = $t['industries'];
		$legacy_index  = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$industry_table} WHERE Key_name=%s", 'unique_name' ) );
		if ( $legacy_index ) {
			$wpdb->query( "ALTER TABLE {$industry_table} DROP INDEX unique_name" );
		}

		$ok = true;
		foreach ( $t as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				$ok = false;
			}
		}
		$contact_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$t['contacts']}", 0 );
		$ok = $ok && in_array( 'website', $contact_columns, true );
		$industry_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$industry_table}", 0 );
		$industry_indexes = $wpdb->get_results( "SHOW INDEX FROM {$industry_table}", ARRAY_A );
		$has_parent_index = false;
		$has_seed_index   = false;
		$has_legacy_index = false;
		foreach ( is_array( $industry_indexes ) ? $industry_indexes : array() as $index ) {
			if ( 'unique_parent_name' === $index['Key_name'] && 0 === (int) $index['Non_unique'] ) {
				$has_parent_index = true;
			}
			if ( 'unique_seed_key' === $index['Key_name'] && 0 === (int) $index['Non_unique'] ) {
				$has_seed_index = true;
			}
			if ( 'unique_name' === $index['Key_name'] ) {
				$has_legacy_index = true;
			}
		}
		$ok = $ok && in_array( 'parent_id', $industry_columns, true ) && in_array( 'seed_key', $industry_columns, true ) && $has_parent_index && $has_seed_index && ! $has_legacy_index;
		if ( $ok ) {
			add_option( 'trade_crm_industry_catalog_lock', '1', '', false );
			if ( ! IndustryCatalog::seed() ) {
				return false;
			}
			update_option( 'trade_crm_db_version', TRADE_CRM_DB_VERSION, false );
			if ( false === get_option( 'trade_crm_settings', false ) ) {
				add_option( 'trade_crm_settings', self::default_settings(), '', false );
			}
		}
		return $ok;
	}

	public static function default_settings(): array {
		return array(
			'company_name'            => 'CRM بازرگان',
			'app_name'                => 'CRM بازرگان',
			'app_short_name'          => 'بازرگان',
			'default_phone_country'   => 'IR',
			'theme_color'             => '#0F766E',
			'background_color'        => '#F8FAFC',
		);
	}

	public static function status_labels(): array {
		return array(
			'new'                => 'جدید',
			'following_up'       => 'در حال پیگیری',
			'potential_customer' => 'مشتری احتمالی',
			'customer'           => 'مشتری',
			'closed'             => 'بسته‌شده',
		);
	}
}
