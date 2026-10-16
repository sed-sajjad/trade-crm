<?php
namespace TradeCRM;

defined( 'ABSPATH' ) || exit;

/** WordPress handles authentication; remembered CRM logins have a bounded lifetime. */
final class Login {
	public function boot(): void {
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_filter( 'login_message', array( $this, 'intro' ) );
		add_filter( 'login_display_language_dropdown', '__return_false' );
		add_filter( 'auth_cookie_expiration', array( $this, 'cookie_expiration' ), 10, 3 );
		add_action( 'login_form', array( $this, 'remember_hint' ) );
	}

	private function is_crm_login(): bool {
		$redirect = $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '';
		if ( ! is_string( $redirect ) ) {
			return false;
		}
		$target = wp_parse_url( wp_validate_redirect( wp_unslash( $redirect ), '' ) );
		$app = wp_parse_url( App::url() );
		if ( ! is_array( $target ) || ! is_array( $app ) || untrailingslashit( $target['path'] ?? '' ) !== untrailingslashit( $app['path'] ?? '' ) ) {
			return false;
		}
		if ( isset( $target['host'] ) && ( strtolower( $target['host'] ) !== strtolower( $app['host'] ?? '' ) || ( $target['port'] ?? null ) !== ( $app['port'] ?? null ) || ( $target['scheme'] ?? '' ) !== ( $app['scheme'] ?? '' ) ) ) {
			return false;
		}
		return true;
	}

	public function cookie_expiration( int $length, int $user_id, bool $remember ): int {
		return $remember && $this->is_crm_login() && user_can( $user_id, 'manage_trade_crm' ) ? 30 * DAY_IN_SECONDS : $length;
	}

	public function remember_hint(): void {
		if ( $this->is_crm_login() ) {
			echo '<p class="trade-crm-remember-hint">' . esc_html__( 'در دستگاه شخصی، «مرا به خاطر بسپار» را برای ماندن ورود تا ۳۰ روز انتخاب کنید. روی دستگاه مشترک این گزینه را فعال نکنید.', 'trade-crm' ) . '</p>';
		}
	}

	public function enqueue_styles(): void {
		$path = TRADE_CRM_DIR . 'assets/login.css';
		wp_enqueue_style( 'trade-crm-login', TRADE_CRM_URL . 'assets/login.css', array( 'login' ), (string) filemtime( $path ) );
		global $action;
		if ( 'login' === $action ) {
			$script = TRADE_CRM_DIR . 'assets/login.js';
			wp_enqueue_script( 'trade-crm-login', TRADE_CRM_URL . 'assets/login.js', array(), substr( hash_file( 'sha256', $script ), 0, 12 ), true );
		}
	}

	public function intro( string $message ): string {
		global $action;
		$description = '';
		switch ( $action ) {
			case 'lostpassword':
			case 'retrievepassword':
				$title = __( 'بازیابی رمز عبور', 'trade-crm' );
				break;
			case 'rp':
			case 'resetpass':
				$title = __( 'انتخاب رمز عبور جدید', 'trade-crm' );
				break;
			case 'register':
				$title = __( 'ساخت حساب کاربری', 'trade-crm' );
				break;
			case 'checkemail':
				$title = __( 'ایمیل خود را بررسی کنید', 'trade-crm' );
				break;
			case 'confirm_admin_email':
				$title = __( 'تأیید ایمیل مدیریت', 'trade-crm' );
				break;
			case 'login':
				$title = __( 'ورود به حساب کاربری', 'trade-crm' );
				$description = __( 'خوش آمدید. برای ادامه وارد حساب خود شوید.', 'trade-crm' );
				break;
			default:
				$title = get_bloginfo( 'name' );
		}

		$intro = '<div class="trade-crm-login-intro">';
		$intro .= '<span class="trade-crm-login-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg></span>';
		$intro .= '<p class="trade-crm-login-site">' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
		$intro .= '<h2>' . esc_html( $title ) . '</h2>';
		if ( $description ) {
			$intro .= '<p class="trade-crm-login-description">' . esc_html( $description ) . '</p>';
		}
		return $intro . '</div>' . $message;
	}
}
