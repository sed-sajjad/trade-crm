<?php
namespace TradeCRM;

defined( 'ABSPATH' ) || exit;

/** Versioned default activity catalogue. Keep the seed order stable after release. */
final class IndustryCatalog {
	public static function tree(): array {
		return array(
			'کشاورزی، باغبانی و نهاده‌ها' => array( 'بذر و نهال' => array(), 'کود' => array(), 'سموم و حفاظت گیاه' => array(), 'گل و گیاه' => array(), 'تجهیزات گلخانه' => array(), 'آبیاری' => array(), 'خدمات کشاورزی' => array() ),
			'دام، طیور و شیلات' => array( 'دام زنده' => array(), 'طیور' => array(), 'آبزیان' => array(), 'خوراک و مکمل دام' => array(), 'تجهیزات پرورش' => array(), 'دامپزشکی' => array() ),
			'مواد غذایی و نوشیدنی' => array( 'میوه و سبزی تازه' => array(), 'برنج و غلات' => array(), 'حبوبات' => array(), 'خشکبار و آجیل' => array( 'پسته' => array(), 'بادام' => array(), 'گردو' => array(), 'فندق' => array(), 'بادام‌هندی' => array() ), 'میوه خشک' => array( 'خرما' => array(), 'کشمش' => array(), 'انجیر خشک' => array(), 'برگه و میوه خشک مخلوط' => array() ), 'گوشت و فرآورده‌های پروتئینی' => array(), 'لبنیات' => array(), 'روغن' => array(), 'ادویه و چاشنی' => array( 'زعفران' => array(), 'ادویه' => array(), 'رب و سس' => array(), 'ترشی و شور' => array() ), 'شیرینی و تنقلات' => array(), 'نوشیدنی' => array( 'چای' => array(), 'قهوه' => array(), 'دمنوش' => array(), 'آبمیوه' => array(), 'آب و نوشیدنی بسته‌بندی' => array() ), 'کنسرو و غذای آماده' => array() ),
			'نساجی، پوشاک و چرم' => array( 'الیاف و نخ' => array(), 'پارچه' => array(), 'پوشاک' => array( 'زنانه' => array(), 'مردانه' => array(), 'بچگانه' => array(), 'لباس کار' => array() ), 'کفش' => array(), 'کیف' => array(), 'چرم' => array(), 'منسوجات خانگی' => array(), 'ملزومات دوخت' => array() ),
			'خانه، آشپزخانه و مبلمان' => array( 'لوازم خانگی' => array(), 'ظروف و ابزار آشپزخانه' => array(), 'مبلمان' => array(), 'کالای خواب' => array(), 'فرش و کف‌پوش' => array(), 'دکوراسیون' => array(), 'روشنایی خانگی' => array() ),
			'ساختمان، عمران و تأسیسات' => array( 'سیمان و بتن' => array(), 'آجر و بلوک' => array(), 'سنگ ساختمانی' => array( 'مرمریت' => array(), 'تراورتن' => array(), 'گرانیت' => array(), 'مرمر' => array() ), 'کاشی و سرامیک' => array(), 'در و پنجره' => array(), 'لوله و اتصالات' => array(), 'شیرآلات' => array(), 'گرمایش و سرمایش' => array(), 'عایق' => array(), 'خدمات ساختمانی' => array() ),
			'معدن، فلزات و مواد معدنی' => array( 'مواد معدنی' => array(), 'فولاد و آهن' => array(), 'فلزات غیرآهنی' => array( 'مس' => array(), 'آلومینیوم' => array(), 'روی' => array(), 'سرب' => array() ), 'مقاطع و ورق' => array(), 'ریخته‌گری' => array(), 'فرآوری معدن' => array(), 'مصنوعات فلزی' => array() ),
			'نفت، گاز و مواد شیمیایی' => array( 'فرآورده‌های نفتی' => array(), 'گاز و تجهیزات' => array(), 'روانکار' => array(), 'مواد شیمیایی پایه' => array(), 'مواد شیمیایی تخصصی' => array(), 'رنگ و رزین' => array(), 'چسب و درزگیر' => array() ),
			'پلیمر، پلاستیک و لاستیک' => array( 'مواد اولیه پلیمری' => array(), 'گرانول' => array(), 'قطعات پلاستیکی' => array(), 'فیلم و ورق' => array(), 'قطعات لاستیکی' => array(), 'افزودنی‌های پلیمری' => array() ),
			'چوب، کاغذ، چاپ و بسته‌بندی' => array( 'چوب و فرآورده‌ها' => array(), 'کاغذ و مقوا' => array(), 'کارتن' => array(), 'ظروف و ملزومات بسته‌بندی' => array(), 'چاپ' => array(), 'برچسب و لیبل' => array(), 'تجهیزات چاپ و بسته‌بندی' => array() ),
			'ماشین‌آلات، ابزار و تجهیزات صنعتی' => array( 'ماشین‌آلات تولید' => array(), 'ابزار دستی' => array(), 'ابزار برقی' => array(), 'پمپ و کمپرسور' => array(), 'انتقال قدرت' => array(), 'هیدرولیک و پنوماتیک' => array(), 'جوش و برش' => array(), 'ایمنی صنعتی' => array(), 'تجهیزات اندازه‌گیری' => array() ),
			'برق، الکترونیک و انرژی' => array( 'سیم و کابل' => array(), 'تابلو و تجهیزات برق' => array(), 'قطعات الکترونیک' => array(), 'کنترل و اتوماسیون' => array(), 'باتری و ذخیره انرژی' => array(), 'انرژی خورشیدی' => array( 'پنل' => array(), 'اینورتر' => array(), 'سازه و متعلقات' => array() ), 'مولد و تجهیزات نیروگاهی' => array() ),
			'وسایل نقلیه و قطعات' => array( 'خودرو' => array(), 'قطعات خودرو' => array( 'موتور' => array(), 'جلوبندی و تعلیق' => array(), 'برق خودرو' => array(), 'بدنه' => array(), 'ترمز' => array() ), 'موتورسیکلت و دوچرخه' => array(), 'خودرو سنگین' => array(), 'ماشین‌آلات راه‌سازی' => array(), 'تجهیزات دریایی' => array(), 'تجهیزات ریلی' => array() ),
			'رایانه، ارتباطات و تجهیزات اداری' => array( 'رایانه و قطعات' => array(), 'موبایل و لوازم جانبی' => array(), 'شبکه و مخابرات' => array(), 'تجهیزات نظارتی و امنیتی' => array(), 'چاپگر و تجهیزات اداری' => array(), 'نوشت‌افزار' => array() ),
			'پزشکی، دارویی و آزمایشگاهی' => array( 'تجهیزات پزشکی' => array(), 'تجهیزات دندانپزشکی' => array(), 'تجهیزات آزمایشگاهی' => array(), 'مواد مصرفی پزشکی' => array( 'پانسمان' => array(), 'دستکش' => array(), 'سرنگ و تزریق' => array(), 'اقلام یک‌بارمصرف' => array() ), 'دارو و مواد اولیه' => array(), 'تجهیزات توان‌بخشی' => array(), 'خدمات سلامت' => array() ),
			'آرایشی، بهداشتی و شوینده' => array( 'مراقبت پوست' => array(), 'مراقبت مو' => array(), 'لوازم آرایش' => array(), 'عطر' => array(), 'بهداشت فردی' => array(), 'شوینده خانگی' => array(), 'شوینده صنعتی' => array() ),
			'کودک، اسباب‌بازی و ورزش' => array( 'سیسمونی' => array(), 'اسباب‌بازی' => array(), 'بازی و سرگرمی' => array(), 'تجهیزات ورزشی' => array(), 'کوهنوردی و کمپینگ' => array(), 'تجهیزات تفریحی' => array() ),
			'طلا، زیورآلات، صنایع‌دستی و هدیه' => array( 'طلا و جواهر' => array(), 'نقره' => array(), 'زیورآلات' => array(), 'ساعت' => array(), 'صنایع‌دستی' => array(), 'آثار هنری' => array(), 'هدایا و اقلام تبلیغاتی' => array() ),
			'محیط‌زیست و بازیافت' => array( 'ضایعات فلزی' => array(), 'ضایعات پلیمری' => array(), 'ضایعات کاغذ' => array(), 'بازیافت الکترونیک' => array(), 'تصفیه آب و فاضلاب' => array(), 'مدیریت پسماند' => array(), 'خدمات محیط‌زیست' => array() ),
			'حمل‌ونقل، انبارداری و خدمات تجاری' => array( 'حمل زمینی' => array( 'داخلی' => array(), 'بین‌المللی' => array() ), 'حمل دریایی' => array(), 'حمل هوایی' => array(), 'پست و ارسال' => array(), 'انبارداری' => array(), 'زنجیره سرد' => array(), 'ترخیص و امور گمرکی' => array(), 'بازرسی کالا' => array(), 'خدمات تأمین کالا' => array() ),
			'فناوری اطلاعات و خدمات مهندسی' => array( 'توسعه نرم‌افزار' => array( 'سازمانی' => array(), 'فروشگاهی' => array(), 'موبایل' => array() ), 'طراحی وب' => array(), 'خدمات شبکه' => array(), 'امنیت اطلاعات' => array(), 'داده و هوش مصنوعی' => array(), 'طراحی و مهندسی' => array(), 'نصب و نگهداری صنعتی' => array(), 'تعمیرات صنعتی' => array() ),
			'مالی، حقوقی، بیمه و خدمات کسب‌وکار' => array( 'حسابداری و حسابرسی' => array(), 'خدمات مالی' => array(), 'بیمه' => array(), 'خدمات حقوقی' => array(), 'ثبت و مجوزها' => array(), 'منابع انسانی' => array(), 'مشاوره مدیریت' => array(), 'ترجمه و خدمات اداری' => array() ),
			'تبلیغات، رسانه، فرهنگ و آموزش' => array( 'تبلیغات و بازاریابی' => array(), 'طراحی گرافیک' => array(), 'عکاسی و ویدئو' => array(), 'رسانه و تولید محتوا' => array(), 'نشر و کتاب' => array(), 'آموزش' => array(), 'نمایشگاه و رویداد' => array() ),
			'گردشگری، اقامت و پذیرایی' => array( 'خدمات سفر' => array(), 'هتل و اقامتگاه' => array(), 'رستوران و تهیه غذا' => array(), 'کافه' => array(), 'خدمات گردشگری' => array(), 'تجهیزات هتل و رستوران' => array() ),
			'املاک، خدمات عمومی و شخصی' => array( 'خدمات املاک' => array(), 'مدیریت ساختمان' => array(), 'نظافت' => array(), 'فضای سبز' => array(), 'تعمیرات لوازم خانگی' => array(), 'خدمات زیبایی' => array(), 'خدمات مراسم' => array() ),
		);
	}

	public static function seed(): bool {
		global $wpdb;
		$tables = Tables::names();
		$option = 'trade_crm_industry_seeded_keys';
		$lock   = 'trade_crm_industry_catalog_lock';
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$lock_id = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", $lock ) );
		if ( ! $lock_id ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $option ) );
		$processed = is_string( $stored ) ? maybe_unserialize( $stored ) : array();
		$processed = is_array( $processed ) ? $processed : array();
		$ids = array();
		$ordinal = 0;
		foreach ( self::tree() as $root_name => $children ) {
			$ordinal++;
			$root_key = sprintf( 'catalog-1:%02d', $ordinal );
			$root_id = self::seed_node( (string) $root_name, 0, $root_key, $processed, $tables['industries'], $wpdb );
			if ( $root_id > 0 ) {
				$ids[ $root_key ] = $root_id;
			}
			$child_number = 0;
			foreach ( $children as $child_name => $grandchildren ) {
				$child_number++;
				$child_key = $root_key . ':' . sprintf( '%02d', $child_number );
				if ( $root_id < 1 ) {
					self::remember_missing( $child_key, $processed );
					continue;
				}
				$child_id = self::seed_node( (string) $child_name, $root_id, $child_key, $processed, $tables['industries'], $wpdb );
				if ( $child_id > 0 ) {
					$ids[ $child_key ] = $child_id;
				}
				$leaf_number = 0;
				foreach ( $grandchildren as $leaf_name => $_unused ) {
					$leaf_number++;
					$leaf_key = $child_key . ':' . sprintf( '%02d', $leaf_number );
					if ( $child_id < 1 ) {
						self::remember_missing( $leaf_key, $processed );
						continue;
					}
					$leaf_id = self::seed_node( (string) $leaf_name, $child_id, $leaf_key, $processed, $tables['industries'], $wpdb );
					if ( $leaf_id > 0 ) {
						$ids[ $leaf_key ] = $leaf_id;
					}
				}
			}
		}
		if ( count( $processed ) !== self::seed_key_count() ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$value = maybe_serialize( $processed );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s", $option ) );
		$ok = $exists
			? false !== $wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $option ), array( '%s' ), array( '%s' ) )
			: false !== $wpdb->insert( $wpdb->options, array( 'option_name' => $option, 'option_value' => $value, 'autoload' => 'no' ), array( '%s', '%s', '%s' ) );
		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return true;
	}

	private static function seed_node( string $name, int $parent_id, string $key, array &$processed, string $table, object $wpdb ): int {
		if ( array_key_exists( $key, $processed ) ) {
			$id = (int) $processed[ $key ];
			return $id > 0 && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id=%d AND parent_id=%d", $id, $parent_id ) ) ? $id : 0;
		}
		$existing_seed = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE seed_key=%s", $key ) );
		if ( $existing_seed ) {
			$processed[ $key ] = (int) $existing_seed;
			return (int) $existing_seed;
		}
		$normalized = Normalizer::text( $name );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,seed_key FROM {$table} WHERE parent_id=%d AND name_normalized=%s", $parent_id, $normalized ), ARRAY_A );
		if ( is_array( $existing ) ) {
			if ( empty( $existing['seed_key'] ) ) {
				$wpdb->update( $table, array( 'seed_key' => $key ), array( 'id' => (int) $existing['id'] ), array( '%s' ), array( '%d' ) );
			}
			$processed[ $key ] = (int) $existing['id'];
			return (int) $existing['id'];
		}
		if ( false === $wpdb->insert( $table, array( 'parent_id' => $parent_id, 'name' => $name, 'name_normalized' => $normalized, 'seed_key' => $key, 'is_active' => 1, 'created_at' => Repository::now() ) ) ) {
			return 0;
		}
		$processed[ $key ] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	private static function remember_missing( string $key, array &$processed ): void {
		if ( ! array_key_exists( $key, $processed ) ) {
			$processed[ $key ] = 0;
		}
	}

	private static function seed_key_count(): int {
		$count = 0;
		foreach ( self::tree() as $children ) {
			$count++;
			foreach ( $children as $grandchildren ) {
				$count += 1 + count( $grandchildren );
			}
		}
		return $count;
	}
}
