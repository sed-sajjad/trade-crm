<?php
use PHPUnit\Framework\TestCase;
use TradeCRM\IndustryCatalog;
use TradeCRM\Normalizer;

final class IndustryCatalogTest extends TestCase {
	/** These snapshots freeze the approved paths and explicit identities, not display order. */
	private const APPROVED_ADDITIONS_HASH = '2857d2d4b4d7f7dccb493b619b2eff5777ba9f8a8cf989c48223fc56fb2a7d3f';
	private const VERSION_TWO_IDENTITIES_HASH = '97c99d85b2338c8f0e1f271fe6ece7e056dff828e44f78f5181bb4f9710e7dde';

	private function legacy_definitions(): array {
		$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/industry-catalog-v1.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '1', $fixture['version'] );
		self::assertCount( 257, $fixture['entries'] );
		return $fixture['entries'];
	}

	private function added_definitions(): array {
		$legacy_keys = array_fill_keys( array_column( $this->legacy_definitions(), 'key' ), true );
		return array_values( array_filter( IndustryCatalog::definitions(), static fn ( array $entry ): bool => ! isset( $legacy_keys[ $entry['key'] ] ) ) );
	}

	private function path_for( string $key, array $by_key ): array {
		$path = array();
		$seen = array();
		while ( null !== $key ) {
			self::assertArrayHasKey( $key, $by_key, 'Every parent must exist.' );
			self::assertArrayNotHasKey( $key, $seen, 'A catalogue path must not contain a cycle.' );
			$seen[ $key ] = true;
			array_unshift( $path, $by_key[ $key ]['name'] );
			$key = $by_key[ $key ]['parent_key'];
		}
		return $path;
	}

	public function test_catalogue_document_and_public_version_agree(): void {
		$document = json_decode( file_get_contents( dirname( __DIR__ ) . '/data/industry-catalog.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '2', IndustryCatalog::VERSION );
		self::assertSame( IndustryCatalog::VERSION, $document['version'] );
		self::assertSame( $document['entries'], IndustryCatalog::definitions() );
	}

	public function test_expanded_catalogue_has_547_entries_and_twenty_five_roots(): void {
		$definitions = IndustryCatalog::definitions();
		self::assertCount( 547, $definitions );
		self::assertCount( 25, array_filter( $definitions, static fn ( array $entry ): bool => null === $entry['parent_key'] ) );
		$tree = IndustryCatalog::tree();
		self::assertCount( 25, $tree );
		self::assertArrayHasKey( 'کشاورزی، باغبانی و نهاده‌ها', $tree );
		self::assertArrayHasKey( 'خدمات املاک', $tree['املاک، خدمات عمومی و شخصی'] );
	}

	public function test_all_257_released_keys_names_and_parent_keys_are_unchanged(): void {
		$current = array_column( IndustryCatalog::definitions(), null, 'key' );
		foreach ( $this->legacy_definitions() as $legacy ) {
			self::assertArrayHasKey( $legacy['key'], $current );
			self::assertSame( $legacy, $current[ $legacy['key'] ], 'Released identity changed: ' . $legacy['key'] );
		}
		self::assertSame( 'میوه و سبزی تازه', $current['catalog-1:03:01']['name'] );
		self::assertSame( 'پسته', $current['catalog-1:03:04:01']['name'] );
	}

	public function test_all_290_additions_are_third_level_leaves_across_all_roots(): void {
		$definitions = IndustryCatalog::definitions();
		$by_key = array_column( $definitions, null, 'key' );
		$parent_keys = array_fill_keys( array_filter( array_column( $definitions, 'parent_key' ) ), true );
		$added = $this->added_definitions();
		self::assertCount( 290, $added );
		$roots = array();
		foreach ( $added as $entry ) {
			$path = $this->path_for( $entry['key'], $by_key );
			self::assertCount( 3, $path, 'A new default must be a third-level leaf.' );
			self::assertArrayNotHasKey( $entry['key'], $parent_keys );
			self::assertStringStartsWith( 'catalog-2:', $entry['key'] );
			$roots[ $path[0] ] = true;
		}
		self::assertCount( 25, $roots );
	}

	public function test_every_approved_addition_keeps_its_exact_persian_name_and_path(): void {
		$by_key = array_column( IndustryCatalog::definitions(), null, 'key' );
		$paths = array_map( fn ( array $entry ): string => implode( ' / ', $this->path_for( $entry['key'], $by_key ) ), $this->added_definitions() );
		sort( $paths, SORT_STRING );
		self::assertSame( self::APPROVED_ADDITIONS_HASH, hash( 'sha256', json_encode( $paths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ), 'The 290 approved additions changed; compare against the approved catalogue before updating this snapshot.' );
	}

	public function test_explicit_identities_do_not_depend_on_definition_order(): void {
		$definitions = IndustryCatalog::definitions();
		foreach ( array( $definitions, array_reverse( $definitions ) ) as $ordered ) {
			$map = array_column( $ordered, null, 'key' );
			ksort( $map, SORT_STRING );
			self::assertSame( self::VERSION_TWO_IDENTITIES_HASH, hash( 'sha256', json_encode( $map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ), 'Changing display order must never change an explicit catalogue identity.' );
		}
	}

	public function test_keys_are_unique_bounded_and_parents_precede_children(): void {
		$seen = array();
		foreach ( IndustryCatalog::definitions() as $entry ) {
			self::assertSame( array( 'key', 'parent_key', 'name' ), array_keys( $entry ) );
			self::assertIsString( $entry['key'] );
			self::assertGreaterThan( 0, strlen( $entry['key'] ) );
			self::assertLessThanOrEqual( 64, strlen( $entry['key'] ) );
			self::assertMatchesRegularExpression( '/^[a-z0-9:_-]+$/D', $entry['key'] );
			self::assertArrayNotHasKey( $entry['key'], $seen, 'Each seed key must identify one node.' );
			if ( null !== $entry['parent_key'] ) {
				self::assertIsString( $entry['parent_key'] );
				self::assertArrayHasKey( $entry['parent_key'], $seen, 'Definitions must put the parent before its children.' );
			}
			self::assertNotSame( '', trim( $entry['name'] ) );
			self::assertLessThanOrEqual( 120, mb_strlen( $entry['name'] ) );
			$seen[ $entry['key'] ] = true;
		}
	}

	public function test_normalized_sibling_names_are_unique(): void {
		$seen = array();
		foreach ( IndustryCatalog::definitions() as $entry ) {
			$identity = ( $entry['parent_key'] ?? '' ) . "\0" . Normalizer::text( $entry['name'] );
			self::assertArrayNotHasKey( $identity, $seen, 'Duplicate normalized sibling: ' . $entry['name'] );
			$seen[ $identity ] = true;
		}
	}

	public function test_entire_tree_stops_at_three_levels_and_contains_every_definition(): void {
		$count = 0;
		$walk = function ( array $nodes, int $depth ) use ( &$walk, &$count ): void {
			self::assertLessThanOrEqual( 3, $depth );
			foreach ( $nodes as $name => $children ) {
				self::assertIsString( $name );
				self::assertIsArray( $children );
				$count++;
				if ( 3 === $depth ) {
					self::assertSame( array(), $children, 'Third-level nodes must not contain a fourth level.' );
				} elseif ( $children ) {
					$walk( $children, $depth + 1 );
				}
			}
		};
		$walk( IndustryCatalog::tree(), 1 );
		self::assertSame( 547, $count );
		$by_key = array_column( IndustryCatalog::definitions(), null, 'key' );
		foreach ( array_keys( $by_key ) as $key ) {
			self::assertLessThanOrEqual( 3, count( $this->path_for( $key, $by_key ) ) );
		}
	}

	public function test_kiwi_is_a_fresh_food_leaf_and_arabic_letters_normalize_to_it(): void {
		$tree = IndustryCatalog::tree();
		self::assertArrayHasKey( 'کیوی', $tree['مواد غذایی و نوشیدنی']['میوه و سبزی تازه'] );
		self::assertSame( array(), $tree['مواد غذایی و نوشیدنی']['میوه و سبزی تازه']['کیوی'] );
		self::assertSame( Normalizer::text( 'کیوی' ), Normalizer::text( 'كيوي' ) );
		$matches = array_filter( IndustryCatalog::definitions(), static fn ( array $entry ): bool => Normalizer::text( $entry['name'] ) === Normalizer::text( 'كيوي' ) );
		self::assertCount( 1, $matches );
		$kiwi = array_values( $matches )[0];
		self::assertSame( 'catalog-1:03:01', $kiwi['parent_key'] );
	}

	public function test_original_third_level_examples_remain_available(): void {
		$tree = IndustryCatalog::tree();
		self::assertArrayHasKey( 'پسته', $tree['مواد غذایی و نوشیدنی']['خشکبار و آجیل'] );
		self::assertArrayHasKey( 'فندق', $tree['مواد غذایی و نوشیدنی']['خشکبار و آجیل'] );
		self::assertArrayHasKey( 'سازمانی', $tree['فناوری اطلاعات و خدمات مهندسی']['توسعه نرم‌افزار'] );
	}
}
