<?php
namespace TradeCRM;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public function boot(): void {
		load_plugin_textdomain( 'trade-crm', false, dirname( plugin_basename( TRADE_CRM_FILE ) ) . '/languages' );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'init', array( App::instance(), 'register_rewrite_rules' ) );
		add_action( 'init', array( PWA::instance(), 'register_rewrite_rules' ) );
		add_action( 'template_redirect', array( $this, 'route_requests' ), 0 );
		add_action( 'login_init', array( new Login(), 'boot' ) );
		add_action( 'wp_ajax_trade_crm_session', array( App::instance(), 'session' ) );
		add_action( 'wp_ajax_nopriv_trade_crm_session', array( App::instance(), 'session' ) );
		add_action( 'wp_login', array( $this, 'refresh_issued_nonce_state' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		add_action( 'admin_notices', array( $this, 'catalog_notice' ) );
		add_action( 'rest_api_init', array( Rest::instance(), 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( Rest::instance(), 'no_store_private_responses' ), 10, 3 );
	}

	public static function activate(): void {
		$conflict = self::route_conflict();
		if ( $conflict ) {
			wp_die( esc_html( $conflict ) );
		}
		if ( ! Tables::install() ) {
			wp_die( esc_html__( 'آماده‌سازی جداول یا فهرست حوزه‌های CRM انجام نشد. پایگاه داده را بررسی کنید و دوباره افزونه را فعال کنید.', 'trade-crm' ) );
		}
		self::add_role_caps();
		App::add_rewrite_rules();
		PWA::add_rewrite_rules();
		// Only refresh WordPress's rule cache; never rewrite the site's .htaccess.
		flush_rewrite_rules( false );
		update_option( 'trade_crm_plugin_version', TRADE_CRM_VERSION, false );
	}

	public function maybe_upgrade(): void {
		if ( ! current_user_can( 'activate_plugins' ) || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$plugin_changed = TRADE_CRM_VERSION !== get_option( 'trade_crm_plugin_version' );
		$schema_changed = TRADE_CRM_DB_VERSION !== get_option( 'trade_crm_db_version' );
		if ( ! $plugin_changed && ! $schema_changed && IndustryCatalog::VERSION === (string) get_option( 'trade_crm_industry_catalog_version', '' ) ) {
			return;
		}
		$error = $plugin_changed || $schema_changed ? self::route_conflict() : '';
		if ( ! $error && $schema_changed && ! Tables::install() ) {
			$error = 'ارتقای جداول CRM انجام نشد. پشتیبان پایگاه داده و مجوز نوشتن کاربر وردپرس را بررسی کنید.';
		}
		if ( ! $error && ! IndustryCatalog::maybe_upgrade() ) {
			$error = IndustryCatalog::last_error();
		}
		if ( $error ) {
			add_action( 'admin_notices', static function () use ( $error ): void {
				echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
			} );
			return;
		}
		if ( $plugin_changed || $schema_changed ) {
			self::add_role_caps();
			flush_rewrite_rules( false );
			update_option( 'trade_crm_plugin_version', TRADE_CRM_VERSION, false );
		}
	}

	public function catalog_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$report = get_option( 'trade_crm_industry_catalog_report', array() );
		if ( is_array( $report ) && IndustryCatalog::VERSION === ( $report['version'] ?? '' ) && ! empty( $report['skipped_count'] ) ) {
			$message = sprintf( 'برای حفظ تغییرات قبلی شما، %d مورد از فهرست پیش‌فرض حوزه‌ها به‌دلیل حذف یا جابه‌جایی دسته‌ها ساخته نشد. در صورت نیاز، حوزهٔ موردنظر را از تنظیمات CRM اضافه کنید.', (int) $report['skipped_count'] );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	public static function deactivate(): void {
		global $wp_rewrite;
		// Registered init callbacks must not add these rules back during the flush.
		remove_action( 'init', array( App::instance(), 'register_rewrite_rules' ) );
		remove_action( 'init', array( PWA::instance(), 'register_rewrite_rules' ) );
		foreach ( array( 'extra_rules_top', 'extra_rules' ) as $property ) {
			foreach ( $wp_rewrite->$property as $pattern => $query ) {
				if ( self::is_own_rule( (string) $query ) ) {
					unset( $wp_rewrite->{$property}[ $pattern ] );
				}
			}
		}
		flush_rewrite_rules( false );
	}

	private static function is_own_rule( string $query ): bool {
		return in_array( $query, array( 'index.php?trade_crm_app=1', 'index.php?trade_crm_manifest=1', 'index.php?trade_crm_sw=1' ), true );
	}

	public static function add_rule( string $pattern, string $query ): void {
		global $wp_rewrite;
		// Preserve another plugin's identical pattern for the activation conflict check.
		foreach ( array( $wp_rewrite->extra_rules_top, $wp_rewrite->extra_rules ) as $rules ) {
			if ( isset( $rules[ $pattern ] ) && ! self::is_own_rule( (string) $rules[ $pattern ] ) ) {
				return;
			}
		}
		add_rewrite_rule( $pattern, $query, 'top' );
	}

	public static function route_conflict(): string {
		global $wp_rewrite;
		$original_rules = $wp_rewrite->rules;
		$without_crm = static function ( $rules ) {
			return is_array( $rules ) ? array_filter( $rules, static fn ( $query ): bool => ! self::is_own_rule( (string) $query ) ) : $rules;
		};
		// Existing CRM rules must not hide a real post when checking reactivation/upgrades.
		add_filter( 'option_rewrite_rules', $without_crm, PHP_INT_MAX );
		try {
			return self::find_route_conflict();
		} finally {
			remove_filter( 'option_rewrite_rules', $without_crm, PHP_INT_MAX );
			$wp_rewrite->rules = $original_rules;
		}
	}

	private static function find_route_conflict(): string {
		global $wp_rewrite;
		$paths = array( 'crm', 'crm/sw.js', 'trade-crm-manifest.webmanifest' );
		foreach ( $paths as $path ) {
			if ( get_page_by_path( $path ) || url_to_postid( home_url( '/' . $path . '/' ) ) ) {
				return 'فعال‌سازی CRM متوقف شد: مسیر /' . $path . ' از قبل متعلق به محتوای سایت است. برای نصب روی سایت اصلی ابتدا مسیر اختصاصی دیگری برای CRM در نظر بگیرید.';
			}
			foreach ( array_merge( $wp_rewrite->extra_rules_top, $wp_rewrite->extra_rules ) as $pattern => $query ) {
				if ( ! self::is_own_rule( (string) $query ) && ( preg_match( '#^' . str_replace( '#', '\\#', $pattern ) . '#', $path ) || preg_match( '#^' . str_replace( '#', '\\#', $pattern ) . '#', $path . '/' ) ) ) {
					return 'فعال‌سازی CRM متوقف شد: مسیر /' . $path . ' با مسیری که افزونه یا قالب دیگری ثبت کرده تداخل دارد.';
				}
			}
		}
		return '';
	}

	private static function add_role_caps(): void {
		$role = get_role( 'trade_crm_supervisor' );
		if ( ! $role ) {
			$role = add_role( 'trade_crm_supervisor', 'سرپرست CRM', array( 'read' => true ) );
		}
		if ( $role ) {
			if ( ! $role->has_cap( 'read' ) ) {
				$role->add_cap( 'read' );
			}
			if ( ! $role->has_cap( 'manage_trade_crm' ) ) {
				$role->add_cap( 'manage_trade_crm' );
			}
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			if ( ! $admin->has_cap( 'manage_trade_crm' ) ) {
				$admin->add_cap( 'manage_trade_crm' );
			}
		}
	}

	public function route_requests(): void {
		// Match only exact paths. Public query vars can change WP's static front-page query.
		$path = untrailingslashit( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
		if ( $path === untrailingslashit( (string) wp_parse_url( home_url( '/trade-crm-manifest.webmanifest' ), PHP_URL_PATH ) ) ) {
			PWA::instance()->serve_manifest();
		}
		if ( $path === untrailingslashit( (string) wp_parse_url( home_url( '/crm/sw.js' ), PHP_URL_PATH ) ) ) {
			PWA::instance()->serve_worker();
		}
		if ( $path === untrailingslashit( (string) wp_parse_url( App::url(), PHP_URL_PATH ) ) ) {
			App::instance()->render();
		}
	}

	public function refresh_issued_nonce_state( string $login, \WP_User $user ): void {
		unset( $login, $user );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'trade_crm_user_ids', 'trade_crm' );
		}
	}

	public function dependency_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = array();
		if ( ! class_exists( \libphonenumber\PhoneNumberUtil::class ) ) {
			$missing[] = 'کتابخانه اعتبارسنجی شماره تلفن';
		}
		if ( ! function_exists( 'mb_strtolower' ) ) {
			$missing[] = 'افزونه PHP به نام mbstring';
		}
		if ( ! $missing ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html( 'برای کارکرد کامل CRM، ' . implode( ' و ', $missing ) . ' را نصب کنید.' ) . '</p></div>';
	}

	public function admin_notice_for_missing_build(): void {
		if ( current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'فایل‌های ساخته‌شده برنامه CRM پیدا نشدند. راهنمای ساخت افزونه را دنبال کنید.', 'trade-crm' ) . '</p></div>';
		}
	}
}
