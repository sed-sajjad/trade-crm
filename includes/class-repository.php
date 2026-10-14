<?php
namespace TradeCRM;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

final class Repository {
	private object $wpdb;
	private array $tables;

	public function __construct() {
		global $wpdb;
		$this->wpdb   = $wpdb;
		$this->tables = Tables::names();
	}

	public static function now(): string {
		return current_time( 'mysql', true );
	}

	public function transaction( callable $work ) {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$result = $work();
			if ( is_wp_error( $result ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return $result;
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'trade_crm_database_error', 'ذخیره اطلاعات کامل نشد. دوباره تلاش کنید.', array( 'status' => 500 ) );
			}
			return $result;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			error_log( '[trade-crm] Transaction failed: ' . $error->getMessage() );
			return new \WP_Error( 'trade_crm_database_error', 'ذخیره اطلاعات کامل نشد. دوباره تلاش کنید.', array( 'status' => 500 ) );
		}
	}

	public function find_duplicate( string $e164 ): ?array {
		$sql = $this->wpdb->prepare(
			"SELECT c.id, c.name, c.company, c.archived_at FROM {$this->tables['numbers']} n JOIN {$this->tables['contacts']} c ON c.id=n.contact_id WHERE n.number_e164=%s LIMIT 1",
			$e164
		);
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function get_contact( int $id, bool $include_archived = true ): ?array {
		$archived = $include_archived ? '' : ' AND c.archived_at IS NULL';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT c.* FROM {$this->tables['contacts']} c WHERE c.id=%d{$archived} LIMIT 1", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['numbers']    = $this->contact_numbers( $id );
		$row['industries'] = $this->contact_industries( $id );
		return $this->present_contact( $row );
	}

	public function contact_numbers( int $contact_id ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT number_e164,national_digits,is_phone,is_whatsapp FROM {$this->tables['numbers']} WHERE contact_id=%d ORDER BY id", $contact_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function contact_industries( int $contact_id ): array {
		$t = $this->tables['industries'];
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT i.*,p.name AS parent_name,p.is_active AS parent_active,g.name AS grandparent_name,g.is_active AS grandparent_active FROM {$t} i JOIN {$this->tables['contact_industries']} ci ON ci.industry_id=i.id LEFT JOIN {$t} p ON p.id=i.parent_id LEFT JOIN {$t} g ON g.id=p.parent_id WHERE ci.contact_id=%d ORDER BY i.name_normalized", $contact_id ), ARRAY_A );
		return array_map( array( $this, 'format_industry' ), is_array( $rows ) ? $rows : array() );
	}

	public function present_contact( array $row ): array {
		foreach ( array( 'created_at', 'updated_at', 'followup_at', 'archived_at' ) as $date_key ) {
			if ( ! empty( $row[ $date_key ] ) ) {
				$row[ $date_key ] = $this->utc_iso( (string) $row[ $date_key ] );
			}
		}
		foreach ( array( 'id', 'created_by', 'updated_by', 'version' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		if ( null !== $row['assigned_user_id'] ) {
			$row['assigned_user_id'] = (int) $row['assigned_user_id'];
		}
		if ( isset( $row['numbers'] ) ) {
			foreach ( $row['numbers'] as &$number ) {
				$number['is_phone']    = (bool) $number['is_phone'];
				$number['is_whatsapp'] = (bool) $number['is_whatsapp'];
				unset( $number['national_digits'] );
			}
			unset( $number );
		}
		unset( $row['search_text'], $row['city_normalized'] );
		$row['archived'] = ! empty( $row['archived_at'] );
		return $row;
	}

	public function list_contacts( array $filters ): array {
		$clauses = array();
		$args    = array();
		if ( 'archived' === $filters['archive'] ) {
			$clauses[] = 'c.archived_at IS NOT NULL';
		} elseif ( 'all' !== $filters['archive'] ) {
			$clauses[] = 'c.archived_at IS NULL';
		}
		if ( ! empty( $filters['status'] ) && isset( Tables::status_labels()[ $filters['status'] ] ) ) {
			$clauses[] = 'c.status = %s';
			$args[]    = $filters['status'];
		}
		if ( ! empty( $filters['assigned_user_id'] ) ) {
			$clauses[] = 'c.assigned_user_id = %d';
			$args[]    = (int) $filters['assigned_user_id'];
		}
		if ( ! empty( $filters['country'] ) ) {
			$clauses[] = 'c.country_code = %s';
			$args[]    = $filters['country'];
		}
		if ( ! empty( $filters['city'] ) ) {
			$clauses[] = 'c.city_normalized LIKE %s';
			$args[]    = '%' . $this->wpdb->esc_like( Normalizer::text( $filters['city'] ) ) . '%';
		}
		if ( ! empty( $filters['industry_id'] ) ) {
			$clauses[] = "EXISTS (SELECT 1 FROM {$this->tables['contact_industries']} ci WHERE ci.contact_id=c.id AND ci.industry_id IN (SELECT child.id FROM {$this->tables['industries']} child WHERE child.id=%d OR child.parent_id=%d OR child.parent_id IN (SELECT grand.id FROM {$this->tables['industries']} grand WHERE grand.parent_id=%d)))";
			$args[]    = (int) $filters['industry_id'];
			$args[]    = (int) $filters['industry_id'];
			$args[]    = (int) $filters['industry_id'];
		}
		if ( 'today' === ( $filters['followup'] ?? '' ) || 'overdue' === ( $filters['followup'] ?? '' ) ) {
			$clauses[] = 'c.followup_at IS NOT NULL AND c.status <> %s';
			$args[] = 'closed';
			if ( 'today' === $filters['followup'] ) {
				$tehran = new DateTimeZone( 'Asia/Tehran' );
				$utc = new DateTimeZone( 'UTC' );
				$start = ( new DateTimeImmutable( 'today', $tehran ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
				$end = ( new DateTimeImmutable( 'tomorrow', $tehran ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
				$clauses[] = 'c.followup_at >= %s AND c.followup_at < %s';
				$args[] = $start;
				$args[] = $end;
			} else {
				$clauses[] = 'c.followup_at < %s';
				$args[] = self::now();
			}
		} elseif ( 'has' === ( $filters['followup'] ?? '' ) ) {
			$clauses[] = 'c.followup_at IS NOT NULL AND c.status <> %s';
			$args[] = 'closed';
		} elseif ( 'none' === ( $filters['followup'] ?? '' ) ) {
			$clauses[] = 'c.followup_at IS NULL';
		}
		if ( ! empty( $filters['query'] ) ) {
			$query = Normalizer::text( $filters['query'] );
			$like  = '%' . $this->wpdb->esc_like( $query ) . '%';
			$digits = preg_replace( '/\D+/', '', Normalizer::digits( $filters['query'] ) ) ?? '';
			$parts = array( 'c.search_text LIKE %s' );
			$search_args = array( $like );
			if ( strlen( $digits ) >= 3 ) {
				$parts[]      = "EXISTS (SELECT 1 FROM {$this->tables['numbers']} sn WHERE sn.contact_id=c.id AND (sn.national_digits LIKE %s OR sn.number_e164 LIKE %s))";
				$search_args[] = '%' . $this->wpdb->esc_like( $digits ) . '%';
				$search_args[] = '%' . $this->wpdb->esc_like( $digits ) . '%';
			}
			$clauses[] = '(' . implode( ' OR ', $parts ) . ')';
			$args      = array_merge( $args, $search_args );
		}
		$cursor = $this->decode_cursor( $filters['cursor'] );
		if ( $cursor ) {
			$clauses[] = '(c.created_at < %s OR (c.created_at = %s AND c.id < %d))';
			$args[]    = $cursor['created_at'];
			$args[]    = $cursor['created_at'];
			$args[]    = $cursor['id'];
		}
		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
		$limit = (int) $filters['limit'];
		$sql   = "SELECT c.* FROM {$this->tables['contacts']} c {$where} ORDER BY c.created_at DESC,c.id DESC LIMIT %d";
		$args[] = $limit + 1;
		$prepared = $this->wpdb->prepare( $sql, ...$args );
		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array( 'items' => array(), 'next_cursor' => null, 'has_more' => false );
		}
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}
		$ids = array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
		$number_map = array();
		$industry_map = array();
		if ( $ids ) {
			$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$number_rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT contact_id,number_e164,national_digits,is_phone,is_whatsapp FROM {$this->tables['numbers']} WHERE contact_id IN ({$marks}) ORDER BY id", ...$ids ), ARRAY_A );
			foreach ( is_array( $number_rows ) ? $number_rows : array() as $number ) {
				$number_map[ (int) $number['contact_id'] ][] = $number;
			}
			$it = $this->tables['industries'];
			$industry_rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT ci.contact_id,i.*,p.name AS parent_name,p.is_active AS parent_active,g.name AS grandparent_name,g.is_active AS grandparent_active FROM {$this->tables['contact_industries']} ci JOIN {$it} i ON i.id=ci.industry_id LEFT JOIN {$it} p ON p.id=i.parent_id LEFT JOIN {$it} g ON g.id=p.parent_id WHERE ci.contact_id IN ({$marks}) ORDER BY i.name_normalized", ...$ids ), ARRAY_A );
			foreach ( is_array( $industry_rows ) ? $industry_rows : array() as $industry ) {
				$contact_id = (int) $industry['contact_id'];
				unset( $industry['contact_id'] );
				$industry_map[ $contact_id ][] = $this->format_industry( $industry );
			}
		}
		$items = array();
		foreach ( $rows as $row ) {
			$id             = (int) $row['id'];
			$row['numbers'] = $number_map[ $id ] ?? array();
			$row['industries'] = $industry_map[ $id ] ?? array();
			$items[]        = $this->present_contact( $row );
		}
		$last = $rows ? end( $rows ) : null;
		return array(
			'items'       => $items,
			'has_more'    => $has_more,
			'next_cursor' => $has_more && $last ? $this->encode_cursor( $last['created_at'], (int) $last['id'] ) : null,
		);
	}

	public function dashboard(): array {
		$contacts = $this->tables['contacts'];
		$today_start = new DateTimeImmutable( 'today', new DateTimeZone( 'Asia/Tehran' ) );
		$today_end   = $today_start->modify( '+1 day' );
		$utc         = new DateTimeZone( 'UTC' );
		$start       = $today_start->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end         = $today_end->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$now         = self::now();
		$counts      = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT COUNT(*) AS total, SUM(status='new') AS new_count, SUM(followup_at >= %s AND followup_at < %s) AS today_count, SUM(followup_at IS NOT NULL AND followup_at < %s) AS overdue_count FROM {$contacts} WHERE archived_at IS NULL",
			$start, $end, $now
		), ARRAY_A );
		$today     = $this->due_contacts( $start, $end, 10 );
		$overdue   = $this->due_contacts( null, $now, 10 );
		return array(
			'counts' => array_map( 'intval', (array) $counts ),
			'today'  => $today,
			'overdue'=> $overdue,
		);
	}

	private function due_contacts( ?string $from, string $before, int $limit ): array {
		$t       = $this->tables['contacts'];
		$clauses = array( 'c.archived_at IS NULL', 'c.followup_at IS NOT NULL', 'c.status <> %s' );
		$args    = array( 'closed' );
		if ( null !== $from ) {
			$clauses[] = 'c.followup_at >= %s';
			$args[]    = $from;
		}
		$clauses[] = 'c.followup_at < %s';
		$args[]    = $before;
		$args[]    = $limit;
		$sql       = $this->wpdb->prepare( "SELECT c.* FROM {$t} c WHERE " . implode( ' AND ', $clauses ) . ' ORDER BY c.followup_at ASC,c.id ASC LIMIT %d', ...$args );
		$rows      = $this->wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( $this, 'present_contact' ), is_array( $rows ) ? $rows : array() );
	}

	public function list_industries( array $filters = array() ): array {
		$t = $this->tables['industries'];
		$clauses = array();
		$args = array();
		if ( isset( $filters['parent_id'] ) ) {
			$clauses[] = 'i.parent_id=%d';
			$args[] = (int) $filters['parent_id'];
		}
		if ( ! empty( $filters['selectable_only'] ) ) {
			$clauses[] = 'i.is_active=1 AND (p.id IS NULL OR p.is_active=1) AND (g.id IS NULL OR g.is_active=1)';
		}
		if ( ! empty( $filters['query'] ) ) {
			$like = '%' . $this->wpdb->esc_like( Normalizer::text( (string) $filters['query'] ) ) . '%';
			$clauses[] = '(i.name_normalized LIKE %s OR p.name_normalized LIKE %s OR g.name_normalized LIKE %s OR CONCAT_WS(\' / \',g.name_normalized,p.name_normalized,i.name_normalized) LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		if ( ! empty( $filters['after_id'] ) ) {
			$clauses[] = 'i.id>%d';
			$args[] = (int) $filters['after_id'];
		}
		$limit = min( 100, max( 1, (int) ( $filters['limit'] ?? 30 ) ) );
		$args[] = $limit + 1;
		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
		$sql = "SELECT i.*,p.name AS parent_name,p.is_active AS parent_active,g.name AS grandparent_name,g.is_active AS grandparent_active,(SELECT COUNT(*) FROM {$this->tables['contact_industries']} ci WHERE ci.industry_id=i.id) AS contact_count,(SELECT COUNT(*) FROM {$t} ch WHERE ch.parent_id=i.id) AS child_count FROM {$t} i LEFT JOIN {$t} p ON p.id=i.parent_id LEFT JOIN {$t} g ON g.id=p.parent_id {$where} ORDER BY i.id ASC LIMIT %d";
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}
		$items = array_map( array( $this, 'format_industry' ), $rows );
		$last = $items ? end( $items ) : null;
		return array( 'items' => $items, 'next_cursor' => $has_more && $last ? (string) $last['id'] : null, 'has_more' => $has_more );
	}

	public function city_suggestions(): array {
		$rows = $this->wpdb->get_results( "SELECT city_normalized,MAX(city) AS city,COUNT(*) AS uses FROM {$this->tables['contacts']} WHERE archived_at IS NULL AND city<>'' GROUP BY city_normalized ORDER BY uses DESC,city_normalized LIMIT 100", ARRAY_A );
		return array_values( array_filter( array_map( static fn ( array $row ): string => (string) $row['city'], is_array( $rows ) ? $rows : array() ) ) );
	}

	public function get_activities( int $contact_id, int $limit = 30, int $before_id = 0 ): array {
		$t = $this->tables['activities'];
		$sql = $before_id > 0
			? $this->wpdb->prepare( "SELECT * FROM {$t} WHERE contact_id=%d AND id < %d ORDER BY id DESC LIMIT %d", $contact_id, $before_id, $limit )
			: $this->wpdb->prepare( "SELECT * FROM {$t} WHERE contact_id=%d ORDER BY id DESC LIMIT %d", $contact_id, $limit );
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return array_map( static function ( array $row ): array {
			$data = json_decode( (string) $row['event_data'], true );
			return array(
				'id' => (int) $row['id'],
				'type' => $row['event_type'],
				'data' => is_array( $data ) ? $data : array(),
				'actor_user_id' => (int) $row['actor_user_id'],
				'actor_name' => $row['actor_name'],
				'created_at' => str_replace( ' ', 'T', (string) $row['created_at'] ) . 'Z',
			);
		}, is_array( $rows ) ? $rows : array() );
	}

	public function begin(): void {
		$this->wpdb->query( 'START TRANSACTION' );
	}

	public function commit(): bool {
		$result = false !== $this->wpdb->query( 'COMMIT' );
		if ( ! $result ) {
			$this->rollback();
		}
		return $result;
	}

	public function rollback(): void {
		$this->wpdb->query( 'ROLLBACK' );
	}

	public function lock_contact( int $id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->tables['contacts']} WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function insert_contact( array $data ): int {
		$result = $this->wpdb->insert( $this->tables['contacts'], $data );
		return false === $result ? 0 : (int) $this->wpdb->insert_id;
	}

	public function update_contact( int $id, int $version, array $data ): int|false {
		$data['version'] = $version + 1;
		return $this->wpdb->update( $this->tables['contacts'], $data, array( 'id' => $id, 'version' => $version ), null, array( '%d', '%d' ) );
	}

	public function save_numbers( int $contact_id, array $numbers ): bool {
		$table = $this->tables['numbers'];
		$old = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id,number_e164 FROM {$table} WHERE contact_id=%d FOR UPDATE", $contact_id ), ARRAY_A );
		$keep = array();
		foreach ( $numbers as $number ) {
			$keep[] = $number['number_e164'];
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$table} WHERE number_e164=%s FOR UPDATE", $number['number_e164'] ) );
			if ( $existing && ! in_array( (string) $number['number_e164'], array_column( $old, 'number_e164' ), true ) ) {
				return false;
			}
		}
		if ( ! empty( $old ) ) {
			$old_ids = array_map( 'intval', array_column( $old, 'id' ) );
			if ( $old_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%d' ) );
				$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$table} WHERE contact_id=%d AND id NOT IN ({$placeholders})", $contact_id, ...$old_ids ) );
			}
		}
		foreach ( $numbers as $number ) {
			$current = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$table} WHERE number_e164=%s AND contact_id=%d", $number['number_e164'], $contact_id ) );
			$data = array(
				'contact_id' => $contact_id,
				'number_e164' => $number['number_e164'],
				'national_digits' => $number['national_digits'],
				'is_phone' => $number['is_phone'] ? 1 : 0,
				'is_whatsapp' => $number['is_whatsapp'] ? 1 : 0,
			);
			$ok = $current
				? false !== $this->wpdb->update( $table, $data, array( 'id' => (int) $current ) )
				: false !== $this->wpdb->insert( $table, $data );
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	public function save_industries( int $contact_id, array $ids ): bool {
		$table = $this->tables['contact_industries'];
		if ( ! $this->lock_industry_catalog() ) {
			return false;
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$previous = array_map( 'intval', (array) $this->wpdb->get_col( $this->wpdb->prepare( "SELECT industry_id FROM {$table} WHERE contact_id=%d", $contact_id ) ) );
		foreach ( $ids as $id ) {
			$industry = $this->get_industry( $id );
			if ( ! $industry || ( ! $industry['is_selectable'] && ! in_array( $id, $previous, true ) ) ) {
				return false;
			}
		}
		if ( false === $this->wpdb->delete( $table, array( 'contact_id' => $contact_id ), array( '%d' ) ) ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( false === $this->wpdb->insert( $table, array( 'contact_id' => $contact_id, 'industry_id' => $id ) ) ) {
				return false;
			}
		}
		return true;
	}

	public function insert_activity( ?int $contact_id, string $type, array $data, int $user_id, string $user_name, ?string $request_id = null, ?string $request_hash = null ): int {
		$result = $this->wpdb->insert( $this->tables['activities'], array(
			'contact_id' => $contact_id,
			'event_type' => $type,
			'event_data' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '{}',
			'actor_user_id' => $user_id,
			'actor_name' => $user_name,
			'request_id' => $request_id,
			'request_hash' => $request_hash,
			'created_at' => self::now(),
		) );
		return false === $result ? 0 : (int) $this->wpdb->insert_id;
	}

	public function get_industry( int $id ): ?array {
		$t = $this->tables['industries'];
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT i.*,p.name AS parent_name,p.is_active AS parent_active,g.name AS grandparent_name,g.is_active AS grandparent_active,(SELECT COUNT(*) FROM {$this->tables['contact_industries']} ci WHERE ci.industry_id=i.id) AS contact_count,(SELECT COUNT(*) FROM {$t} ch WHERE ch.parent_id=i.id) AS child_count FROM {$t} i LEFT JOIN {$t} p ON p.id=i.parent_id LEFT JOIN {$t} g ON g.id=p.parent_id WHERE i.id=%d", $id ), ARRAY_A );
		return is_array( $row ) ? $this->format_industry( $row ) : null;
	}

	public function industry_labels( array $ids ): array {
		$labels = array();
		foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $id ) {
			$industry = $this->get_industry( $id );
			if ( $industry ) {
				$labels[ $id ] = $industry['path_label'];
			}
		}
		ksort( $labels );
		return $labels;
	}

	public function industry_depth( int $id ): int {
		$industry = $this->get_industry( $id );
		return $industry ? (int) $industry['depth'] : 0;
	}

	public function industry_height( int $id ): int {
		$t = $this->tables['industries'];
		$depth = $this->industry_depth( $id );
		if ( ! $depth ) {
			return 0;
		}
		$children = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE parent_id=%d", $id ) );
		if ( ! $children ) {
			return 1;
		}
		$grandchildren = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE parent_id IN (SELECT id FROM {$t} WHERE parent_id=%d)", $id ) );
		return $grandchildren ? 3 : 2;
	}

	public function industry_is_in_subtree( int $candidate_id, int $root_id ): bool {
		if ( $candidate_id === $root_id ) {
			return true;
		}
		$t = $this->tables['industries'];
		return (bool) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$t} WHERE id=%d AND (parent_id=%d OR parent_id IN (SELECT id FROM {$t} WHERE parent_id=%d))", $candidate_id, $root_id, $root_id ) );
	}

	private function format_industry( array $row ): array {
		$id = (int) $row['id'];
		$parent_id = (int) ( $row['parent_id'] ?? 0 );
		$parent_name = (string) ( $row['parent_name'] ?? '' );
		$grandparent_name = (string) ( $row['grandparent_name'] ?? '' );
		$path = array_values( array_filter( array( $grandparent_name, $parent_name, (string) $row['name'] ), static fn ( string $part ): bool => '' !== $part ) );
		$active = (bool) $row['is_active'];
		$selectable = $active && ( empty( $parent_id ) || ! empty( $row['parent_active'] ) ) && ( empty( $row['grandparent_name'] ) || ! empty( $row['grandparent_active'] ) );
		return array(
			'id' => $id,
			'parent_id' => $parent_id,
			'parent_name' => $parent_name,
			'name' => (string) $row['name'],
			'path' => $path,
			'path_label' => implode( ' / ', $path ),
			'depth' => count( $path ),
			'is_active' => $active,
			'is_selectable' => $selectable,
			'child_count' => (int) ( $row['child_count'] ?? 0 ),
			'contact_count' => (int) ( $row['contact_count'] ?? 0 ),
			'can_delete' => 0 === (int) ( $row['child_count'] ?? 0 ) && 0 === (int) ( $row['contact_count'] ?? 0 ),
		);
	}

	public function lock_industry_catalog(): bool {
		$option = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT option_id FROM {$this->wpdb->options} WHERE option_name=%s FOR UPDATE", 'trade_crm_industry_catalog_lock' ) );
		return (bool) $option;
	}

	public function save_industry( string $name, string $normalized, int $parent_id = 0, ?int $id = null, ?bool $active = null ): int|false {
		$table = $this->tables['industries'];
		if ( ! $this->lock_industry_catalog() ) {
			return false;
		}
		if ( $id ) {
			$data = array( 'name' => $name, 'name_normalized' => $normalized, 'parent_id' => $parent_id );
			if ( null !== $active ) {
				$data['is_active'] = $active ? 1 : 0;
			}
			return false === $this->wpdb->update( $table, $data, array( 'id' => $id ) ) ? false : $id;
		}
		if ( false === $this->wpdb->insert( $table, array( 'parent_id' => $parent_id, 'name' => $name, 'name_normalized' => $normalized, 'created_at' => self::now() ) ) ) {
			return false;
		}
		return (int) $this->wpdb->insert_id;
	}

	public function delete_industry( int $id ): string|bool {
		if ( ! $this->lock_industry_catalog() ) {
			return false;
		}
		$industry = $this->get_industry( $id );
		if ( ! $industry ) {
			return 'missing';
		}
		if ( $industry['child_count'] > 0 || $industry['contact_count'] > 0 ) {
			return 'in_use';
		}
		return false !== $this->wpdb->delete( $this->tables['industries'], array( 'id' => $id ), array( '%d' ) );
	}

	public function activity_by_request( int $user_id, string $request_id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT id,contact_id,request_hash,event_type FROM {$this->tables['activities']} WHERE actor_user_id=%d AND request_id=%s FOR UPDATE", $user_id, $request_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private function encode_cursor( string $created_at, int $id ): string {
		return rtrim( strtr( base64_encode( wp_json_encode( array( 'created_at' => $created_at, 'id' => $id ) ) ), '+/', '-_' ), '=' );
	}

	private function decode_cursor( string $cursor ): ?array {
		if ( '' === $cursor || strlen( $cursor ) > 300 ) {
			return null;
		}
		$decoded = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		$data = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) || empty( $data['created_at'] ) || empty( $data['id'] ) || ! ctype_digit( (string) $data['id'] ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $data['created_at'], new DateTimeZone( 'UTC' ) );
		return $date ? array( 'created_at' => $date->format( 'Y-m-d H:i:s' ), 'id' => (int) $data['id'] ) : null;
	}

	private function utc_iso( string $value ): string {
		return str_replace( ' ', 'T', $value ) . 'Z';
	}
}
