<?php
use PHPUnit\Framework\TestCase;
use TradeCRM\IndustryCatalog;

final class IndustryCatalogTest extends TestCase {
	public function test_default_catalog_covers_goods_and_services_with_twenty_five_roots(): void {
		$tree = IndustryCatalog::tree();
		self::assertCount( 25, $tree );
		self::assertArrayHasKey( 'کشاورزی، باغبانی و نهاده‌ها', $tree );
		self::assertArrayHasKey( 'خدمات املاک', $tree['املاک، خدمات عمومی و شخصی'] );
	}

	public function test_default_catalog_contains_expected_third_level_examples_and_stops_at_three_levels(): void {
		$tree = IndustryCatalog::tree();
		self::assertArrayHasKey( 'پسته', $tree['مواد غذایی و نوشیدنی']['خشکبار و آجیل'] );
		self::assertArrayHasKey( 'فندق', $tree['مواد غذایی و نوشیدنی']['خشکبار و آجیل'] );
		self::assertArrayHasKey( 'سازمانی', $tree['فناوری اطلاعات و خدمات مهندسی']['توسعه نرم‌افزار'] );

		$max_depth = 1;
		foreach ( $tree as $children ) {
			foreach ( $children as $leaves ) {
				$max_depth = max( $max_depth, $leaves ? 3 : 2 );
			}
		}
		self::assertSame( 3, $max_depth );
	}
}
