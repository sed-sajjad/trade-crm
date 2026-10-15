<?php
namespace TradeCRM;

defined( 'ABSPATH' ) || exit;

/** Immutable catalogue identities; content upgrades are independent of schema upgrades. */
final class IndustryCatalog {
	public const VERSION = '2';
	private const VERSION_OPTION = 'trade_crm_industry_catalog_version';
	private const KEYS_OPTION = 'trade_crm_industry_seeded_keys';
	private const REPORT_OPTION = 'trade_crm_industry_catalog_report';
	private const LOCK_OPTION = 'trade_crm_industry_catalog_lock';
	private static string $error = '';

	public static function definitions(): array {
		static $entries;
		if ( null === $entries ) {
			$path = dirname( __DIR__ ) . '/data/industry-catalog.json';
			$source = is_readable( $path ) ? file_get_contents( $path ) : false;
			if ( false === $source ) {
				throw new \RuntimeException( 'Missing industry catalogue data.' );
			}
			$data = json_decode( $source, true, 512, JSON_THROW_ON_ERROR );
			if ( ( $data['version'] ?? null ) !== self::VERSION || ! is_array( $data['entries'] ?? null ) ) {
				throw new \RuntimeException( 'Invalid industry catalogue version.' );
			}
			$entries = self::ordered( $data['entries'] );
		}
		return $entries;
	}

	public static function tree(): array {
		$children = array();
		foreach ( self::definitions() as $entry ) {
			$children[ $entry['parent_key'] ?? '' ][] = $entry;
		}
		$build = static function ( string $key ) use ( &$build, $children ): array {
			$result = array();
			foreach ( $children[ $key ] ?? array() as $entry ) {
				$result[ $entry['name'] ] = $build( $entry['key'] );
			}
			return $result;
		};
		return $build( '' );
	}

	/** Validate and topologically order records without deriving identities from order. */
	private static function ordered( array $entries ): array {
		$by_key = array(); $siblings = array();
		foreach ( $entries as $entry ) {
			$key = $entry['key'] ?? null;
			$parent = $entry['parent_key'] ?? null;
			$name = $entry['name'] ?? null;
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9:._-]{1,64}$/D', $key ) || isset( $by_key[ $key ] ) || ! array_key_exists( 'parent_key', $entry ) || ( null !== $parent && ! is_string( $parent ) ) || ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > 120 ) {
				throw new \RuntimeException( 'Invalid or duplicate industry definition.' );
			}
			$normalized = Normalizer::text( $name );
			if ( isset( $siblings[ $parent ?? '' ][ $normalized ] ) ) {
				throw new \RuntimeException( 'Duplicate sibling industry name.' );
			}
			$siblings[ $parent ?? '' ][ $normalized ] = true;
			$by_key[ $key ] = $entry;
		}
		$state = array(); $depths = array(); $ordered = array();
		$visit = static function ( string $key ) use ( &$visit, &$state, &$depths, &$ordered, $by_key ): void {
			if ( 2 === ( $state[ $key ] ?? 0 ) ) {
				return;
			}
			if ( 1 === ( $state[ $key ] ?? 0 ) || ! isset( $by_key[ $key ] ) ) {
				throw new \RuntimeException( 'Missing or cyclic industry parent.' );
			}
			$state[ $key ] = 1;
			$parent = $by_key[ $key ]['parent_key'];
			if ( null !== $parent ) {
				$visit( $parent );
			}
			$depths[ $key ] = null === $parent ? 1 : $depths[ $parent ] + 1;
			if ( $depths[ $key ] > 3 ) {
				throw new \RuntimeException( 'Industry definition exceeds three levels.' );
			}
			$state[ $key ] = 2;
			$ordered[] = $by_key[ $key ];
		};
		foreach ( array_keys( $by_key ) as $key ) {
			$visit( $key );
		}
		return $ordered;
	}

	public static function maybe_upgrade(): bool {
		return self::VERSION === (string) get_option( self::VERSION_OPTION, '' ) || self::seed( false );
	}

	public static function last_error(): string {
		return self::$error;
	}

	/** Reconcile defaults while preserving identities, tombstones and user customizations. */
	public static function seed( bool $force = true ): bool {
		global $wpdb;
		self::$error = '';
		$previous_suppression = $wpdb->suppress_errors( true );
		$transaction = false;
		try {
			$definitions = self::definitions();
			$table = Tables::names()['industries'];
			add_option( self::LOCK_OPTION, '1', '', false );
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Cannot begin catalogue transaction.' );
			}
			$transaction = true;
			$lock = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", self::LOCK_OPTION ) );
			if ( ! $lock ) {
				throw new \RuntimeException( 'Cannot lock industry catalogue.' );
			}
			$revision = self::read_option( self::VERSION_OPTION, $wpdb );
			if ( ! $force && self::VERSION === $revision ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( 'Cannot commit catalogue transaction.' );
				}
				wp_cache_delete( self::VERSION_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return true;
			}
			$stored = self::read_option( self::KEYS_OPTION, $wpdb );
			$processed = is_string( $stored ) ? maybe_unserialize( $stored ) : array();
			$processed = is_array( $processed ) ? $processed : array();
			$rows = $wpdb->get_results( "SELECT id,parent_id,name,name_normalized,seed_key,is_active FROM {$table}", ARRAY_A );
			if ( ! is_array( $rows ) || $wpdb->last_error ) {
				throw new \RuntimeException( 'Cannot read industry catalogue.' );
			}
			$by_id = array(); $by_seed = array(); $by_name = array();
			foreach ( $rows as $row ) {
				$id = (int) $row['id'];
				$by_id[ $id ] = $row;
				$by_name[ (int) $row['parent_id'] ][ $row['name_normalized'] ] = $id;
				if ( $row['seed_key'] ) {
					$by_seed[ $row['seed_key'] ] = $id;
				}
			}
			$resolved = array(); $skipped = array(); $added = 0; $reused = 0;
			foreach ( $definitions as $entry ) {
				$key = $entry['key'];
				if ( array_key_exists( $key, $processed ) ) {
					$id = (int) $processed[ $key ];
					$resolved[ $key ] = isset( $by_id[ $id ] ) ? $id : 0;
					if ( ! $resolved[ $key ] ) {
						$skipped[ $key ] = 'previously_removed';
					}
					continue;
				}
				if ( isset( $by_seed[ $key ] ) ) {
					$resolved[ $key ] = $processed[ $key ] = $by_seed[ $key ];
					$reused++;
					continue;
				}
				$parent_id = null === $entry['parent_key'] ? 0 : ( $resolved[ $entry['parent_key'] ] ?? 0 );
				if ( null !== $entry['parent_key'] && ! $parent_id ) {
					$resolved[ $key ] = $processed[ $key ] = 0;
					$skipped[ $key ] = 'missing_parent';
					continue;
				}
				$depth = self::actual_depth( $parent_id, $by_id );
				if ( null === $depth || $depth >= 3 ) {
					$resolved[ $key ] = $processed[ $key ] = 0;
					$skipped[ $key ] = null === $depth ? 'invalid_parent' : 'max_depth';
					continue;
				}
				$normalized = Normalizer::text( $entry['name'] );
				$id = $by_name[ $parent_id ][ $normalized ] ?? 0;
				if ( $id ) {
					if ( ! $by_id[ $id ]['seed_key'] ) {
						if ( false === $wpdb->update( $table, array( 'seed_key' => $key ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ) ) {
							throw new \RuntimeException( 'Cannot adopt existing industry.' );
						}
						$by_id[ $id ]['seed_key'] = $key;
						$by_seed[ $key ] = $id;
					}
					$reused++;
				} else {
					$row = array( 'parent_id' => $parent_id, 'name' => $entry['name'], 'name_normalized' => $normalized, 'seed_key' => $key, 'is_active' => 1, 'created_at' => Repository::now() );
					if ( false === $wpdb->insert( $table, $row ) || ! $wpdb->insert_id ) {
						throw new \RuntimeException( 'Cannot insert industry.' );
					}
					$id = (int) $wpdb->insert_id;
					$by_id[ $id ] = array_merge( $row, array( 'id' => $id ) );
					$by_seed[ $key ] = $id;
					$by_name[ $parent_id ][ $normalized ] = $id;
					$added++;
				}
				$resolved[ $key ] = $processed[ $key ] = $id;
			}
			// Historical keys may outlive definitions; never discard their tombstones.
			foreach ( $definitions as $entry ) {
				if ( ! array_key_exists( $entry['key'], $processed ) ) {
					throw new \RuntimeException( 'Incomplete catalogue reconciliation.' );
				}
			}
			$report = array( 'version' => self::VERSION, 'added' => $added, 'reused' => $reused, 'skipped_count' => count( $skipped ), 'skipped' => $skipped );
			self::write_option( self::KEYS_OPTION, maybe_serialize( $processed ), $wpdb );
			self::write_option( self::REPORT_OPTION, maybe_serialize( $report ), $wpdb );
			self::write_option( self::VERSION_OPTION, self::VERSION, $wpdb );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Cannot commit industry catalogue.' );
			}
			$transaction = false;
			foreach ( array( self::KEYS_OPTION, self::REPORT_OPTION, self::VERSION_OPTION ) as $option ) {
				wp_cache_delete( $option, 'options' );
			}
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return true;
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			self::$error = 'به‌روزرسانی فهرست حوزه‌های فعالیت انجام نشد. اتصال و مجوز نوشتن پایگاه داده را بررسی کنید؛ با بازکردن دوباره پیشخوان، تلاش مجدد انجام می‌شود.';
			return false;
		} finally {
			$wpdb->suppress_errors( $previous_suppression );
		}
	}

	private static function actual_depth( int $id, array $rows ): ?int {
		$seen = array(); $depth = 0;
		while ( $id ) {
			if ( isset( $seen[ $id ] ) || ! isset( $rows[ $id ] ) || $depth >= 3 ) {
				return null;
			}
			$seen[ $id ] = true;
			$depth++;
			$id = (int) $rows[ $id ]['parent_id'];
		}
		return $depth;
	}

	private static function read_option( string $name, object $wpdb ): ?string {
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot read catalogue metadata.' );
		}
		return $value;
	}

	private static function write_option( string $name, string $value, object $wpdb ): void {
		$sql = $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", $name, $value );
		if ( false === $wpdb->query( $sql ) ) {
			throw new \RuntimeException( 'Cannot write catalogue metadata.' );
		}
	}
}
