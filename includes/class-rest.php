<?php
namespace TradeCRM;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Rest {
	private static ?self $instance = null;
	private Repository $repository;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->repository = new Repository();
	}

	public function register_routes(): void {
		$namespace = 'trade-crm/v1';
		register_rest_route( $namespace, '/bootstrap', array( 'methods' => 'GET', 'callback' => array( $this, 'bootstrap' ), 'permission_callback' => array( $this, 'permission' ) ) );
		register_rest_route( $namespace, '/dashboard', array( 'methods' => 'GET', 'callback' => array( $this, 'dashboard' ), 'permission_callback' => array( $this, 'permission' ) ) );
		register_rest_route( $namespace, '/contacts', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'contacts' ), 'permission_callback' => array( $this, 'permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_contact' ), 'permission_callback' => array( $this, 'permission' ) ),
		) );
		register_rest_route( $namespace, '/contacts/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'contact' ), 'permission_callback' => array( $this, 'permission' ) ),
			array( 'methods' => 'PATCH', 'callback' => array( $this, 'update_contact' ), 'permission_callback' => array( $this, 'permission' ) ),
		) );
		register_rest_route( $namespace, '/contacts/(?P<id>\d+)/activities', array( 'methods' => 'GET', 'callback' => array( $this, 'activities' ), 'permission_callback' => array( $this, 'permission' ) ) );
		register_rest_route( $namespace, '/contacts/(?P<id>\d+)/notes', array( 'methods' => 'POST', 'callback' => array( $this, 'create_note' ), 'permission_callback' => array( $this, 'permission' ) ) );
		register_rest_route( $namespace, '/contacts/(?P<id>\d+)/followup/complete', array( 'methods' => 'POST', 'callback' => array( $this, 'complete_followup' ), 'permission_callback' => array( $this, 'permission' ) ) );
		register_rest_route( $namespace, '/contacts/(?P<id>\d+)/archive', array( 'methods' => 'POST', 'callback' => array( $this, 'archive_contact' ), 'permission_callback' => array( $this, 'permission' ) ) );
		register_rest_route( $namespace, '/contacts/(?P<id>\d+)/restore', array( 'methods' => 'POST', 'callback' => array( $this, 'restore_contact' ), 'permission_callback' => array( $this, 'permission' ) ) );
		register_rest_route( $namespace, '/industries', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'industries' ), 'permission_callback' => array( $this, 'permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_industry' ), 'permission_callback' => array( $this, 'permission' ) ),
		) );
		register_rest_route( $namespace, '/industries/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'industry' ), 'permission_callback' => array( $this, 'permission' ) ),
			array( 'methods' => 'PATCH', 'callback' => array( $this, 'update_industry' ), 'permission_callback' => array( $this, 'permission' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_industry' ), 'permission_callback' => array( $this, 'permission' ) ),
		) );
		register_rest_route( $namespace, '/settings', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'settings' ), 'permission_callback' => array( $this, 'permission' ) ),
			array( 'methods' => 'PATCH', 'callback' => array( $this, 'update_settings' ), 'permission_callback' => array( $this, 'permission' ) ),
		) );
	}

	public function permission(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'trade_crm_auth_required', 'برای استفاده از CRM وارد شوید.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_trade_crm' ) ) {
			return new WP_Error( 'trade_crm_forbidden', 'به اطلاعات CRM دسترسی ندارید.', array( 'status' => 403 ) );
		}
		return true;
	}

	public function no_store_private_responses( $response, $server, $request ) {
		unset( $server );
		if ( str_starts_with( (string) $request->get_route(), '/trade-crm/v1/' ) && $response instanceof WP_REST_Response ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'Trade CRM private API' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );
			$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Vary', 'Cookie', false );
		}
		return $response;
	}

	public function bootstrap(): array {
		$user = wp_get_current_user();
		return array(
			'user' => array( 'id' => (int) $user->ID, 'name' => $user->display_name ),
			'supervisors' => $this->supervisors(),
			'statuses' => Tables::status_labels(),
			'sources' => array( 'whatsapp' => 'واتساپ', 'instagram' => 'اینستاگرام', 'telegram' => 'تلگرام', 'phone' => 'تماس', 'referral' => 'معرفی', 'other' => 'سایر' ),
			'settings' => $this->settings_value(),
			'cities' => $this->repository->city_suggestions(),
			'phone_regions' => PhoneNumbers::supported_regions(),
		);
	}

	public function dashboard(): array {
		return $this->repository->dashboard();
	}

	public function contacts( WP_REST_Request $request ): array {
		$limit = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 30 ) ) );
		$archive = sanitize_key( (string) $request->get_param( 'archive' ) );
		if ( ! in_array( $archive, array( 'active', 'archived', 'all' ), true ) ) {
			$archive = 'active';
		}
		$followup = sanitize_key( (string) $request->get_param( 'followup' ) );
		if ( ! in_array( $followup, array( '', 'today', 'overdue', 'has', 'none' ), true ) ) {
			$followup = '';
		}
		return $this->repository->list_contacts( array(
			'limit' => $limit,
			'cursor' => sanitize_text_field( (string) $request->get_param( 'cursor' ) ),
			'archive' => $archive,
			'query' => mb_substr( sanitize_text_field( (string) $request->get_param( 'q' ) ), 0, 100 ),
			'status' => sanitize_key( (string) $request->get_param( 'status' ) ),
			'assigned_user_id' => absint( $request->get_param( 'assigned_user_id' ) ),
			'country' => strtoupper( sanitize_text_field( (string) $request->get_param( 'country' ) ) ),
			'city' => mb_substr( sanitize_text_field( (string) $request->get_param( 'city' ) ), 0, 100 ),
			'industry_id' => absint( $request->get_param( 'industry_id' ) ),
			'followup' => $followup,
		) );
	}

	public function contact( WP_REST_Request $request ): array|WP_Error {
		$contact = $this->repository->get_contact( absint( $request['id'] ) );
		if ( ! $contact ) {
			return $this->not_found();
		}
		return $contact;
	}

	public function activities( WP_REST_Request $request ): array|WP_Error {
		$id = absint( $request['id'] );
		if ( ! $this->repository->get_contact( $id ) ) {
			return $this->not_found();
		}
		$limit = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 30 ) ) );
		return array( 'items' => $this->repository->get_activities( $id, $limit, absint( $request->get_param( 'before_id' ) ) ) );
	}

	public function create_contact( WP_REST_Request $request ): array|WP_Error|WP_REST_Response {
		$input = $this->contact_input( $request, true );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$request_id = $this->request_id( $request );
		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}
		$user = wp_get_current_user();
		$hash = hash( 'sha256', wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$existing = $this->repository->activity_by_request( (int) $user->ID, $request_id );
		if ( $existing ) {
			return $this->replay_or_conflict( $existing, $hash, 'contact.created' );
		}
		$duplicate = $this->find_duplicate_input( $input['numbers'] );
		if ( $duplicate ) {
			return $this->duplicate_error( $duplicate );
		}
		$now = Repository::now();
		$user_name = $this->actor_name( $user );
		$this->repository->begin();
		$data = $input['contact'];
		$data += array(
			'assigned_user_id' => (int) $user->ID,
			'assigned_user_name' => $user_name,
			'created_by' => (int) $user->ID,
			'created_by_name' => $user_name,
			'updated_by' => (int) $user->ID,
			'updated_by_name' => $user_name,
			'version' => 1,
			'created_at' => $now,
			'updated_at' => $now,
		);
		if ( ! empty( $input['assigned_user_id'] ) ) {
			$assigned = get_userdata( $input['assigned_user_id'] );
			if ( ! $assigned || ! user_can( $assigned, 'manage_trade_crm' ) ) {
				$this->repository->rollback();
				return $this->field_error( 'assigned_user_id', 'مسئول انتخاب‌شده دسترسی فعال به CRM ندارد.' );
			}
			$data['assigned_user_id'] = (int) $assigned->ID;
			$data['assigned_user_name'] = $this->actor_name( $assigned );
		}
		$contact_id = $this->repository->insert_contact( $data );
		if ( ! $contact_id || ! $this->repository->save_numbers( $contact_id, $input['numbers'] ) || ! $this->repository->save_industries( $contact_id, $input['industry_ids'] ) ) {
			$this->repository->rollback();
			return $this->creation_failure( $input['numbers'], $user->ID, $request_id, $hash );
		}
		$created_event = array( 'name' => $data['name'], 'company' => $data['company'], 'status' => $data['status'] );
		if ( $data['followup_at'] ) {
			$created_event['followup_at'] = $data['followup_at'];
			$created_event['followup_topic'] = $data['followup_topic'];
		}
		if ( '' !== $data['initial_note'] ) {
			$created_event['initial_note'] = $data['initial_note'];
		}
		if ( $input['industry_ids'] ) {
			$created_event['industries'] = array_values( $this->repository->industry_labels( $input['industry_ids'] ) );
		}
		if ( ! $this->repository->insert_activity( $contact_id, 'contact.created', $created_event, (int) $user->ID, $user_name, $request_id, $hash ) ) {
			$this->repository->rollback();
			return $this->creation_failure( $input['numbers'], $user->ID, $request_id, $hash );
		}
		if ( ! $this->repository->commit() ) {
			return $this->database_error();
		}
		return new WP_REST_Response( array( 'contact' => $this->repository->get_contact( $contact_id ) ), 201 );
	}

	public function update_contact( WP_REST_Request $request ): array|WP_Error {
		$id = absint( $request['id'] );
		$input = $this->contact_input( $request, false );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$user = wp_get_current_user();
		$this->repository->begin();
		if ( ! $this->repository->lock_industry_catalog() ) {
			$this->repository->rollback();
			return $this->database_error();
		}
		$old = $this->repository->lock_contact( $id );
		if ( ! $old ) {
			$this->repository->rollback();
			return $this->not_found();
		}
		if ( $old['archived_at'] ) {
			$this->repository->rollback();
			return new WP_Error( 'trade_crm_archived', 'برای ویرایش، ابتدا مخاطب را از بایگانی بازیابی کنید.', array( 'status' => 409 ) );
		}
		$expected = absint( $request->get_param( 'version' ) );
		if ( ! $expected || (int) $old['version'] !== $expected ) {
			$this->repository->rollback();
			return $this->version_conflict( $id );
		}
		$old_numbers = array_values( array_unique( array_column( $this->repository->contact_numbers( $id ), 'number_e164' ) ) );
		$old_industries = $this->repository->industry_labels( array_column( $this->repository->contact_industries( $id ), 'id' ) );
		$assigned_id = (int) ( $old['assigned_user_id'] ?? 0 );
		$assigned_name = (string) $old['assigned_user_name'];
		if ( $input['assigned_user_provided'] ) {
			$requested_assigned_id = (int) $input['assigned_user_id'];
			$requested_assignee = $requested_assigned_id ? get_userdata( $requested_assigned_id ) : false;
			$preserve_inactive_assignee = $requested_assigned_id > 0
				&& $requested_assigned_id === $assigned_id
				&& ( ! $requested_assignee || ! user_can( $requested_assignee, 'manage_trade_crm' ) );
			if ( $preserve_inactive_assignee ) {
				// Keep historical ownership visible until a supervisor deliberately reassigns it.
			} elseif ( ! $requested_assignee || ! user_can( $requested_assignee, 'manage_trade_crm' ) ) {
				$this->repository->rollback();
				return $this->field_error( 'assigned_user_id', 'مسئول انتخاب‌شده دسترسی فعال به CRM ندارد.' );
			} else {
				$assigned_id = (int) $requested_assignee->ID;
				$assigned_name = $this->actor_name( $requested_assignee );
			}
		}
		if ( 'closed' === $input['contact']['status'] && ! empty( $old['followup_at'] ) ) {
			if ( ! $request->get_param( 'confirm_cancel_followup' ) ) {
				$this->repository->rollback();
				return new WP_Error( 'trade_crm_followup_confirmation', 'با بستن پرونده، پیگیری باز لغو می‌شود. این تغییر را تأیید کنید.', array( 'status' => 409, 'requires_confirmation' => true ) );
			}
			$input['contact']['followup_at'] = null;
			$input['contact']['followup_topic'] = '';
		}
		if ( ! empty( $input['contact']['followup_at'] ) && 'closed' === $input['contact']['status'] ) {
			$this->repository->rollback();
			return $this->field_error( 'followup_at', 'برای پیگیری جدید ابتدا وضعیت پرونده را فعال کنید.' );
		}
		$input['contact']['assigned_user_id'] = $assigned_id ?: null;
		$input['contact']['assigned_user_name'] = $assigned_name;
		if ( ! $request->has_param( 'website' ) ) {
			$input['contact']['website'] = $old['website'];
			$input['contact']['search_text'] = Normalizer::text( $input['contact']['search_text'] . ' ' . $old['website'] );
		}
		$input['contact']['updated_by'] = (int) $user->ID;
		$input['contact']['updated_by_name'] = $this->actor_name( $user );
		$input['contact']['updated_at'] = Repository::now();
		if ( false === $this->repository->update_contact( $id, $expected, $input['contact'] ) ) {
			$this->repository->rollback();
			return $this->database_error();
		}
		if ( ! $this->repository->save_numbers( $id, $input['numbers'] ) ) {
			$this->repository->rollback();
			$duplicate = $this->find_duplicate_input( $input['numbers'], $id );
			return $duplicate ? $this->duplicate_error( $duplicate ) : $this->database_error();
		}
		if ( ! $this->repository->save_industries( $id, $input['industry_ids'] ) ) {
			$this->repository->rollback();
			return $this->field_error( 'industry_ids', 'یکی از حوزه‌های فعالیت معتبر نیست.' );
		}
		$changes = $this->contact_changes( $old, $input['contact'] );
		$new_numbers = array_values( array_unique( array_column( $input['numbers'], 'number_e164' ) ) );
		sort( $old_numbers );
		sort( $new_numbers );
		if ( $old_numbers !== $new_numbers ) {
			$changes['numbers'] = array( 'label' => 'شماره‌ها', 'from' => implode( '، ', $old_numbers ), 'to' => implode( '، ', $new_numbers ) );
		}
		$new_industries = $this->repository->industry_labels( $input['industry_ids'] );
		if ( $old_industries !== $new_industries ) {
			$changes['industries'] = array( 'label' => 'حوزه‌ها', 'from' => implode( '، ', array_values( $old_industries ) ), 'to' => implode( '، ', array_values( $new_industries ) ) );
		}
		if ( $changes ) {
			$changes['version'] = array( 'from' => $expected, 'to' => $expected + 1 );
			if ( ! $this->repository->insert_activity( $id, 'contact.updated', $changes, (int) $user->ID, $this->actor_name( $user ) ) ) {
				$this->repository->rollback();
				return $this->database_error();
			}
		}
		if ( ! $this->repository->commit() ) {
			return $this->database_error();
		}
		return $this->repository->get_contact( $id );
	}

	public function create_note( WP_REST_Request $request ): array|WP_Error|WP_REST_Response {
		$id = absint( $request['id'] );
		$text = sanitize_textarea_field( (string) $request->get_param( 'text' ) );
		if ( '' === trim( $text ) || mb_strlen( $text ) > 5000 ) {
			return $this->field_error( 'text', 'یادداشت باید بین ۱ تا ۵۰۰۰ نویسه باشد.' );
		}
		$request_id = $this->request_id( $request );
		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}
		$user = wp_get_current_user();
		$hash = hash( 'sha256', wp_json_encode( array( 'contact_id' => $id, 'text' => $text ), JSON_UNESCAPED_UNICODE ) );
		$this->repository->begin();
		$existing = $this->repository->activity_by_request( (int) $user->ID, $request_id );
		if ( $existing ) {
			$this->repository->rollback();
			return $this->replay_or_conflict( $existing, $hash, 'note.created', $id );
		}
		if ( ! $this->repository->get_contact( $id ) ) {
			$this->repository->rollback();
			return $this->not_found();
		}
		if ( ! $this->repository->insert_activity( $id, 'note.created', array( 'text' => $text ), (int) $user->ID, $this->actor_name( $user ), $request_id, $hash ) ) {
			$this->repository->rollback();
			return $this->idempotent_failure( $user->ID, $request_id, $hash, 'note.created', $id );
		}
		if ( ! $this->repository->commit() ) {
			return $this->idempotent_failure( $user->ID, $request_id, $hash, 'note.created', $id );
		}
		return new WP_REST_Response( array( 'created' => true ), 201 );
	}

	public function complete_followup( WP_REST_Request $request ): array|WP_Error|WP_REST_Response {
		$id = absint( $request['id'] );
		$result = sanitize_textarea_field( (string) $request->get_param( 'result' ) );
		if ( '' === trim( $result ) || mb_strlen( $result ) > 3000 ) {
			return $this->field_error( 'result', 'نتیجه پیگیری را کوتاه ثبت کنید.' );
		}
		$request_id = $this->request_id( $request );
		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}
		$user = wp_get_current_user();
		$next_at = null;
		$next_topic = '';
		if ( $request->get_param( 'next_followup_at' ) ) {
			$next_at = $this->utc_datetime( (string) $request->get_param( 'next_followup_at' ), 'next_followup_at' );
			if ( is_wp_error( $next_at ) ) {
				return $next_at;
			}
			$next_topic = mb_substr( sanitize_text_field( (string) $request->get_param( 'next_followup_topic' ) ), 0, 190 );
			if ( '' === $next_topic ) {
				return $this->field_error( 'next_followup_topic', 'برای پیگیری بعدی موضوع کوتاه بنویسید.' );
			}
		}
		$hash = hash( 'sha256', wp_json_encode( array( 'contact_id' => $id, 'result' => $result, 'next_at' => $next_at, 'next_topic' => $next_topic ), JSON_UNESCAPED_UNICODE ) );
		$this->repository->begin();
		$existing_request = $this->repository->activity_by_request( (int) $user->ID, $request_id );
		if ( $existing_request ) {
			$this->repository->rollback();
			return $this->replay_or_conflict( $existing_request, $hash, 'followup.completed', $id );
		}
		$contact = $this->repository->lock_contact( $id );
		if ( ! $contact || $contact['archived_at'] ) {
			$this->repository->rollback();
			return $contact ? new WP_Error( 'trade_crm_archived', 'مخاطب بایگانی شده است.', array( 'status' => 409 ) ) : $this->not_found();
		}
		if ( (int) $contact['version'] !== absint( $request->get_param( 'version' ) ) ) {
			$this->repository->rollback();
			return $this->version_conflict( $id );
		}
		if ( empty( $contact['followup_at'] ) ) {
			$this->repository->rollback();
			return new WP_Error( 'trade_crm_no_followup', 'برای این مخاطب پیگیری فعالی ثبت نشده است.', array( 'status' => 409 ) );
		}
		$data = array( 'followup_at' => $next_at, 'followup_topic' => $next_topic, 'status' => 'following_up', 'updated_by' => (int) $user->ID, 'updated_by_name' => $this->actor_name( $user ), 'updated_at' => Repository::now() );
		if ( 'closed' === $contact['status'] ) {
			$this->repository->rollback();
			return new WP_Error( 'trade_crm_closed', 'برای پیگیری، ابتدا وضعیت پرونده را فعال کنید.', array( 'status' => 409 ) );
		}
		if ( false === $this->repository->update_contact( $id, (int) $contact['version'], $data ) ) {
			$this->repository->rollback();
			return $this->database_error();
		}
		$event = array( 'result' => $result, 'completed_followup' => array( 'at' => $contact['followup_at'], 'topic' => $contact['followup_topic'] ), 'next_followup' => $next_at ? array( 'at' => $next_at, 'topic' => $next_topic ) : null );
		if ( ! $this->repository->insert_activity( $id, 'followup.completed', $event, (int) $user->ID, $this->actor_name( $user ), $request_id, $hash ) ) {
			$this->repository->rollback();
			return $this->idempotent_failure( $user->ID, $request_id, $hash, 'followup.completed', $id );
		}
		if ( ! $this->repository->commit() ) {
			return $this->idempotent_failure( $user->ID, $request_id, $hash, 'followup.completed', $id );
		}
		return new WP_REST_Response( array( 'contact' => $this->repository->get_contact( $id ) ), 200 );
	}

	public function archive_contact( WP_REST_Request $request ): array|WP_Error {
		return $this->set_archived( absint( $request['id'] ), absint( $request->get_param( 'version' ) ), true );
	}

	public function restore_contact( WP_REST_Request $request ): array|WP_Error {
		return $this->set_archived( absint( $request['id'] ), absint( $request->get_param( 'version' ) ), false );
	}

	private function set_archived( int $id, int $version, bool $archive ): array|WP_Error {
		$user = wp_get_current_user();
		$this->repository->begin();
		$contact = $this->repository->lock_contact( $id );
		if ( ! $contact ) {
			$this->repository->rollback();
			return $this->not_found();
		}
		if ( (int) $contact['version'] !== $version ) {
			$this->repository->rollback();
			return $this->version_conflict( $id );
		}
		if ( (bool) $contact['archived_at'] === $archive ) {
			$this->repository->rollback();
			return new WP_Error( 'trade_crm_archive_state', $archive ? 'این مخاطب از قبل بایگانی شده است.' : 'این مخاطب از قبل فعال است.', array( 'status' => 409 ) );
		}
		$time = Repository::now();
		$changes = array( 'archived_at' => $archive ? $time : null, 'updated_by' => (int) $user->ID, 'updated_by_name' => $this->actor_name( $user ), 'updated_at' => $time );
		if ( false === $this->repository->update_contact( $id, $version, $changes ) || ! $this->repository->insert_activity( $id, $archive ? 'contact.archived' : 'contact.restored', array( 'at' => $time ), (int) $user->ID, $this->actor_name( $user ) ) ) {
			$this->repository->rollback();
			return $this->database_error();
		}
		if ( ! $this->repository->commit() ) {
			return $this->database_error();
		}
		return $this->repository->get_contact( $id );
	}

	public function industries( WP_REST_Request $request ): array {
		$query_params = $request->get_query_params();
		$parent_id = array_key_exists( 'parent_id', $query_params ) ? absint( $query_params['parent_id'] ) : null;
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		$limit = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 30 ) ) );
		return $this->repository->list_industries( array(
			'parent_id' => $parent_id,
			'selectable_only' => 'selectable' === $scope,
			'query' => mb_substr( sanitize_text_field( (string) $request->get_param( 'q' ) ), 0, 100 ),
			'after_id' => absint( $request->get_param( 'cursor' ) ),
			'limit' => $limit,
		) );
	}

	public function industry( WP_REST_Request $request ): array|WP_Error {
		return $this->repository->get_industry( absint( $request['id'] ) ) ?: $this->not_found();
	}

	public function create_industry( WP_REST_Request $request ): array|WP_Error|WP_REST_Response {
		$name = $this->industry_name( $request->get_param( 'name' ) );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$parent_id = absint( $request->get_param( 'parent_id' ) );
		$this->repository->begin();
		if ( ! $this->repository->lock_industry_catalog() ) {
			$this->repository->rollback();
			return $this->database_error();
		}
		if ( $parent_id && 0 === $this->repository->industry_depth( $parent_id ) ) {
			$this->repository->rollback();
			return $this->field_error( 'parent_id', 'دسته مادر پیدا نشد.' );
		}
		if ( $parent_id && $this->repository->industry_depth( $parent_id ) >= 3 ) {
			$this->repository->rollback();
			return $this->field_error( 'parent_id', 'بیشترین عمق دسته‌بندی سه سطح است.' );
		}
		$id = $this->repository->save_industry( $name, Normalizer::text( $name ), $parent_id );
		if ( false === $id ) {
			$this->repository->rollback();
			return new WP_Error( 'trade_crm_duplicate_industry', 'این حوزه قبلاً ثبت شده یا ذخیره نشد.', array( 'status' => 409 ) );
		}
		if ( ! $this->repository->commit() ) {
			return $this->database_error();
		}
		return new WP_REST_Response( array( 'item' => $this->repository->get_industry( $id ) ), 201 );
	}

	public function update_industry( WP_REST_Request $request ): array|WP_Error {
		$id = absint( $request['id'] );
		$this->repository->begin();
		if ( ! $this->repository->lock_industry_catalog() ) {
			$this->repository->rollback();
			return $this->database_error();
		}
		$old = $this->repository->get_industry( $id );
		if ( ! $old ) {
			$this->repository->rollback();
			return $this->not_found();
		}
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$name = array_key_exists( 'name', $payload ) ? $this->industry_name( $payload['name'] ) : $old['name'];
		if ( is_wp_error( $name ) ) {
			$this->repository->rollback();
			return $name;
		}
		$parent_id = array_key_exists( 'parent_id', $payload ) ? absint( $payload['parent_id'] ) : (int) $old['parent_id'];
		if ( $parent_id && ( 0 === $this->repository->industry_depth( $parent_id ) || $this->repository->industry_is_in_subtree( $parent_id, $id ) ) ) {
			$this->repository->rollback();
			return $this->field_error( 'parent_id', 'دسته مادر انتخاب‌شده معتبر نیست.' );
		}
		$new_depth = ( $parent_id ? $this->repository->industry_depth( $parent_id ) : 0 ) + 1;
		if ( $new_depth + $this->repository->industry_height( $id ) - 1 > 3 ) {
			$this->repository->rollback();
			return $this->field_error( 'parent_id', 'با این جابه‌جایی عمق دسته‌بندی از سه سطح بیشتر می‌شود.' );
		}
		$active = array_key_exists( 'is_active', $payload ) ? rest_sanitize_boolean( $payload['is_active'] ) : null;
		if ( false === $this->repository->save_industry( $name, Normalizer::text( $name ), $parent_id, $id, $active ) ) {
			$this->repository->rollback();
			return new WP_Error( 'trade_crm_duplicate_industry', 'عنوان حوزه تکراری است یا ذخیره نشد.', array( 'status' => 409 ) );
		}
		if ( ! $this->repository->commit() ) {
			return $this->database_error();
		}
		return $this->repository->get_industry( $id );
	}

	public function delete_industry( WP_REST_Request $request ): array|WP_Error|WP_REST_Response {
		$this->repository->begin();
		if ( ! $this->repository->lock_industry_catalog() ) {
			$this->repository->rollback();
			return $this->database_error();
		}
		$result = $this->repository->delete_industry( absint( $request['id'] ) );
		if ( 'missing' === $result ) {
			$this->repository->rollback();
			return $this->not_found();
		}
		if ( 'in_use' === $result ) {
			$this->repository->rollback();
			return new WP_Error( 'trade_crm_industry_in_use', 'این حوزه زیر‌دسته یا مخاطب دارد. می‌توانید آن را غیرفعال کنید.', array( 'status' => 409 ) );
		}
		if ( true !== $result || ! $this->repository->commit() ) {
			$this->repository->rollback();
			return $this->database_error();
		}
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public function settings(): array {
		return $this->settings_value();
	}

	public function update_settings( WP_REST_Request $request ): array|WP_Error {
		$settings = $this->settings_value();
		$limits = array( 'company_name' => 120, 'app_name' => 60, 'app_short_name' => 24 );
		foreach ( $limits as $key => $limit ) {
			if ( $request->has_param( $key ) ) {
				$value = trim( sanitize_text_field( (string) $request->get_param( $key ) ) );
				if ( '' === $value || mb_strlen( $value ) > $limit ) {
					return $this->field_error( $key, 'این مقدار الزامی است و باید کوتاه‌تر باشد.' );
				}
				$settings[ $key ] = $value;
			}
		}
		if ( $request->has_param( 'default_phone_country' ) ) {
			$country = strtoupper( sanitize_text_field( (string) $request->get_param( 'default_phone_country' ) ) );
			if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || ! in_array( $country, \libphonenumber\PhoneNumberUtil::getInstance()->getSupportedRegions(), true ) ) {
				return $this->field_error( 'default_phone_country', 'کشور پیش‌فرض معتبر نیست.' );
			}
			$settings['default_phone_country'] = $country;
		}
		update_option( 'trade_crm_settings', $settings, false );
		return $settings;
	}

	private function settings_value(): array {
		return wp_parse_args( (array) get_option( 'trade_crm_settings', array() ), Tables::default_settings() );
	}

	private function supervisors(): array {
		$users = array_merge( get_users( array( 'role' => 'trade_crm_supervisor', 'number' => 100 ) ), get_users( array( 'role' => 'administrator', 'number' => 100 ) ) );
		$unique = array();
		foreach ( $users as $user ) {
			if ( $user instanceof \WP_User && user_can( $user, 'manage_trade_crm' ) ) {
				$unique[ $user->ID ] = array( 'id' => (int) $user->ID, 'name' => $this->actor_name( $user ), 'active' => true );
			}
		}
		return array_values( $unique );
	}

	private function contact_input( WP_REST_Request $request, bool $creating ): array|WP_Error {
		$raw = $request->get_json_params();
		if ( ! is_array( $raw ) ) {
			$raw = $request->get_params();
		}
		$raw = is_array( $raw ) ? $raw : array();
		$first_name = trim( sanitize_text_field( (string) ( $raw['first_name'] ?? '' ) ) );
		$last_name  = trim( sanitize_text_field( (string) ( $raw['last_name'] ?? '' ) ) );
		if ( '' === $first_name ) {
			return $this->field_error( 'first_name', 'نام را وارد کنید.' );
		}
		if ( mb_strlen( $first_name ) > 100 ) {
			return $this->field_error( 'first_name', 'نام نمی‌تواند بیشتر از ۱۰۰ نویسه باشد.' );
		}
		if ( '' === $last_name ) {
			return $this->field_error( 'last_name', 'نام خانوادگی را وارد کنید.' );
		}
		if ( mb_strlen( $last_name ) > 100 ) {
			return $this->field_error( 'last_name', 'نام خانوادگی نمی‌تواند بیشتر از ۱۰۰ نویسه باشد.' );
		}
		$name = $first_name . ' ' . $last_name;
		if ( mb_strlen( $name ) > 190 ) {
			return $this->field_error( 'last_name', 'ترکیب نام و نام خانوادگی نباید بیشتر از ۱۹۰ نویسه باشد.' );
		}
		$country = strtoupper( sanitize_text_field( (string) ( $raw['country_code'] ?? $this->settings_value()['default_phone_country'] ) ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = 'IR';
		}
		$phone_raw = trim( (string) ( $raw['phone'] ?? '' ) );
		$whatsapp_raw = trim( (string) ( $raw['whatsapp'] ?? '' ) );
		$same = ! empty( $raw['whatsapp_same_as_phone'] );
		if ( $same && '' !== $phone_raw ) {
			$whatsapp_raw = $phone_raw;
		}
		if ( $creating && '' === $phone_raw && '' === $whatsapp_raw ) {
			return $this->field_error( 'phone', 'شماره تماس یا واتساپ را وارد کنید.' );
		}
		if ( ! $creating && '' === $phone_raw && '' === $whatsapp_raw ) {
			return $this->field_error( 'phone', 'حداقل یک شماره تماس یا واتساپ باید باقی بماند.' );
		}
		$numbers_by_e164 = array();
		foreach ( array( 'phone' => $phone_raw, 'whatsapp' => $whatsapp_raw ) as $kind => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$number = PhoneNumbers::normalize( $value, $country );
			if ( is_wp_error( $number ) ) {
				$data = $number->get_error_data();
				return $this->field_error( $kind, $number->get_error_message(), (int) ( $data['status'] ?? 400 ) );
			}
			$key = $number['number_e164'];
			$numbers_by_e164[ $key ] ??= array( 'number_e164' => $key, 'national_digits' => $number['national_digits'], 'is_phone' => false, 'is_whatsapp' => false );
			$numbers_by_e164[ $key ][ 'is_' . $kind ] = true;
		}
		$status = sanitize_key( (string) ( $raw['status'] ?? 'new' ) );
		if ( ! isset( Tables::status_labels()[ $status ] ) ) {
			return $this->field_error( 'status', 'وضعیت انتخاب‌شده معتبر نیست.' );
		}
		$source = sanitize_key( (string) ( $raw['source'] ?? '' ) );
		if ( ! in_array( $source, array( '', 'whatsapp', 'instagram', 'telegram', 'phone', 'referral', 'other' ), true ) ) {
			return $this->field_error( 'source', 'منبع آشنایی معتبر نیست.' );
		}
		$followup_at = null;
		$followup_topic = '';
		if ( ! empty( $raw['followup_at'] ) ) {
			$followup_at = $this->utc_datetime( (string) $raw['followup_at'], 'followup_at' );
			if ( is_wp_error( $followup_at ) ) {
				return $followup_at;
			}
			$followup_topic = mb_substr( sanitize_text_field( (string) ( $raw['followup_topic'] ?? '' ) ), 0, 190 );
			if ( '' === $followup_topic ) {
				return $this->field_error( 'followup_topic', 'موضوع پیگیری را وارد کنید.' );
			}
			if ( 'closed' === $status ) {
				return $this->field_error( 'followup_at', 'برای پرونده بسته‌شده پیگیری ثبت نمی‌شود.' );
			}
			if ( 'new' === $status ) {
				$status = 'following_up';
			}
		}
		$industries = $raw['industry_ids'] ?? array();
		if ( ! is_array( $industries ) || count( $industries ) > 100 ) {
			return $this->field_error( 'industry_ids', 'فهرست حوزه‌های فعالیت معتبر نیست.' );
		}
		$industry_ids = array_values( array_unique( array_filter( array_map( 'absint', $industries ) ) ) );
		if ( ! $industry_ids ) {
			return $this->field_error( 'industry_ids', 'برای ثبت مخاطب، حداقل یک حوزه فعالیت انتخاب کنید.' );
		}
		$email_raw = trim( (string) ( $raw['email'] ?? '' ) );
		$email = sanitize_email( $email_raw );
		if ( isset( $raw['website'] ) && ! is_string( $raw['website'] ) ) {
			return $this->field_error( 'website', 'آدرس سایت معتبر با http یا https وارد کنید.' );
		}
		$website = trim( $raw['website'] ?? '' );
		if ( '' !== $website ) {
			if ( ! preg_match( '~^[a-z][a-z0-9+.-]*:~i', $website ) ) {
				$website = 'https://' . ltrim( $website, '/' );
			}
			$parts = wp_parse_url( $website );
			if ( mb_strlen( $website ) > 2048 || preg_match( '/[\s<>"\x00-\x1f\x7f]/u', $website ) || ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return $this->field_error( 'website', 'آدرس سایت معتبر با http یا https وارد کنید.' );
			}
			$website = esc_url_raw( $website, array( 'http', 'https' ) );
			if ( '' === $website ) {
				return $this->field_error( 'website', 'آدرس سایت معتبر با http یا https وارد کنید.' );
			}
		}
		$fields = array(
			'name' => $name,
			'first_name' => $first_name,
			'last_name' => $last_name,
			'company' => mb_substr( sanitize_text_field( (string) ( $raw['company'] ?? '' ) ), 0, 190 ),
			'email' => $email,
			'website' => $website,
			'telegram' => $this->social_handle( (string) ( $raw['telegram'] ?? '' ) ),
			'instagram' => $this->social_handle( (string) ( $raw['instagram'] ?? '' ) ),
			'country_code' => $country,
			'city' => mb_substr( sanitize_text_field( (string) ( $raw['city'] ?? '' ) ), 0, 190 ),
			'city_normalized' => mb_substr( Normalizer::text( (string) ( $raw['city'] ?? '' ) ), 0, 190 ),
			'source' => $source,
			'initial_note' => sanitize_textarea_field( (string) ( $raw['initial_note'] ?? '' ) ),
			'status' => $status,
			'followup_at' => $followup_at,
			'followup_topic' => $followup_topic,
		);
		if ( mb_strlen( $fields['initial_note'] ) > 10000 ) {
			return $this->field_error( 'initial_note', 'توضیح اولیه بیشتر از حد مجاز است.' );
		}
		if ( '' !== $email_raw && ( $email !== $email_raw || ! is_email( $email ) ) ) {
			return $this->field_error( 'email', 'ایمیل معتبر وارد کنید.' );
		}
		$assignment_provided = array_key_exists( 'assigned_user_id', $raw );
		$assigned_id = $assignment_provided ? absint( $raw['assigned_user_id'] ) : 0;
		$search_values = array( $fields['name'], $fields['first_name'], $fields['last_name'], $fields['company'], $fields['email'], $fields['website'], $fields['telegram'], $fields['instagram'], $fields['city'] );
		$fields['search_text'] = Normalizer::text( implode( ' ', array_filter( $search_values ) ) );
		return array( 'contact' => $fields, 'numbers' => array_values( $numbers_by_e164 ), 'industry_ids' => $industry_ids, 'assigned_user_id' => $assigned_id, 'assigned_user_provided' => $assignment_provided );
	}

	private function social_handle( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		$value = preg_replace( '~^https?://(?:www\.)?(?:t\.me|telegram\.me|instagram\.com)/~i', '', $value ) ?? $value;
		$value = ltrim( $value, '@/' );
		return preg_match( '/^[\p{L}\p{N}._-]{1,190}$/u', $value ) ? $value : '';
	}

	private function utc_datetime( string $value, string $field ): string|WP_Error {
		try {
			$date = new DateTimeImmutable( $value );
			if ( ! preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/i', $value ) ) {
				return $this->field_error( $field, 'زمان باید منطقه زمانی مشخص داشته باشد.' );
			}
			return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Throwable $error ) {
			return $this->field_error( $field, 'تاریخ و ساعت معتبر وارد کنید.' );
		}
	}

	private function request_id( WP_REST_Request $request ): string|WP_Error {
		$value = $request->get_header( 'X-Trade-CRM-Request-ID' ) ?: $request->get_param( 'request_id' );
		$id = Normalizer::request_id( $value );
		return $id ?: new WP_Error( 'trade_crm_request_id_required', 'شناسه یکتای درخواست معتبر ارسال نشده است.', array( 'status' => 400 ) );
	}

	private function find_duplicate_input( array $numbers, int $except_contact = 0 ): ?array {
		foreach ( $numbers as $number ) {
			$duplicate = $this->repository->find_duplicate( $number['number_e164'] );
			if ( $duplicate && (int) $duplicate['id'] !== $except_contact ) {
				return $duplicate;
			}
		}
		return null;
	}

	private function duplicate_error( array $contact ): WP_Error {
		return new WP_Error( 'trade_crm_duplicate_phone', 'این شماره در پرونده دیگری ثبت شده است.', array( 'status' => 409, 'contact' => array( 'id' => (int) $contact['id'], 'name' => $contact['name'], 'company' => $contact['company'], 'archived' => ! empty( $contact['archived_at'] ), 'url' => add_query_arg( array( 'contact' => (int) $contact['id'] ), App::url() ) ) ) );
	}

	private function creation_failure( array $numbers, int $user_id, string $request_id, string $hash ): WP_Error|WP_REST_Response {
		$existing = $this->repository->activity_by_request( $user_id, $request_id );
		if ( $existing ) {
			return $this->replay_or_conflict( $existing, $hash, 'contact.created' );
		}
		$duplicate = $this->find_duplicate_input( $numbers );
		return $duplicate ? $this->duplicate_error( $duplicate ) : $this->database_error();
	}

	private function idempotent_failure( int $user_id, string $request_id, string $hash, string $type, int $contact_id ): WP_Error|WP_REST_Response {
		$existing = $this->repository->activity_by_request( $user_id, $request_id );
		if ( $existing ) {
			return $this->replay_or_conflict( $existing, $hash, $type, $contact_id );
		}
		return $this->database_error();
	}

	private function replay_or_conflict( array $existing, string $hash, string $type, ?int $contact_id = null ): WP_Error|WP_REST_Response {
		if ( $existing['event_type'] !== $type || ! hash_equals( (string) $existing['request_hash'], $hash ) || ( null !== $contact_id && (int) $existing['contact_id'] !== $contact_id ) ) {
			return new WP_Error( 'trade_crm_request_conflict', 'شناسه درخواست قبلاً با اطلاعات دیگری استفاده شده است.', array( 'status' => 409 ) );
		}
		if ( 'contact.created' === $type ) {
			return new WP_REST_Response( array( 'contact' => $this->repository->get_contact( (int) $existing['contact_id'] ), 'replayed' => true ), 200 );
		}
		return new WP_REST_Response( array( 'created' => true, 'replayed' => true ), 200 );
	}

	private function contact_changes( array $old, array $new ): array {
		$map = array( 'name' => 'نام مخاطب', 'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'company' => 'شرکت', 'email' => 'ایمیل', 'website' => 'آدرس سایت', 'telegram' => 'تلگرام', 'instagram' => 'اینستاگرام', 'source' => 'منبع آشنایی', 'status' => 'وضعیت', 'followup_at' => 'موعد پیگیری', 'followup_topic' => 'موضوع پیگیری', 'city' => 'شهر', 'country_code' => 'کشور', 'initial_note' => 'توضیح اولیه' );
		$changes = array();
		foreach ( $map as $key => $label ) {
			$before = $old[ $key ] ?? '';
			$after = $new[ $key ] ?? '';
			if ( (string) $before !== (string) $after ) {
				$changes[ $key ] = array( 'label' => $label, 'from' => (string) $before, 'to' => (string) $after );
			}
		}
		$old_assignee_id = (int) ( $old['assigned_user_id'] ?? 0 );
		$new_assignee_id = (int) ( $new['assigned_user_id'] ?? 0 );
		if ( $old_assignee_id !== $new_assignee_id ) {
			$changes['assigned_user_id'] = array( 'label' => 'مسئول پرونده', 'from' => (string) ( $old['assigned_user_name'] ?? '' ), 'to' => (string) ( $new['assigned_user_name'] ?? '' ) );
		} elseif ( (string) ( $old['assigned_user_name'] ?? '' ) !== (string) ( $new['assigned_user_name'] ?? '' ) ) {
			$changes['assigned_user_name'] = array( 'label' => 'نام مسئول', 'from' => (string) ( $old['assigned_user_name'] ?? '' ), 'to' => (string) ( $new['assigned_user_name'] ?? '' ) );
		}
		return $changes;
	}

	private function industry_name( $value ): string|WP_Error {
		$name = trim( sanitize_text_field( is_string( $value ) ? $value : '' ) );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			return $this->field_error( 'name', 'نام حوزه باید بین ۱ تا ۱۲۰ نویسه باشد.' );
		}
		return $name;
	}

	private function actor_name( \WP_User $user ): string {
		return mb_substr( sanitize_text_field( $user->display_name ?: $user->user_login ), 0, 190 );
	}

	private function field_error( string $field, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'trade_crm_invalid_field', $message, array( 'status' => $status, 'field' => $field ) );
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'trade_crm_not_found', 'مخاطب پیدا نشد.', array( 'status' => 404 ) );
	}

	private function database_error(): WP_Error {
		return new WP_Error( 'trade_crm_database_error', 'ذخیره اطلاعات کامل نشد. دوباره تلاش کنید.', array( 'status' => 500 ) );
	}

	private function version_conflict( int $id ): WP_Error {
		return new WP_Error( 'trade_crm_version_conflict', 'این پرونده در دستگاه یا نشست دیگری تغییر کرده است. اطلاعات تازه را بررسی کنید.', array( 'status' => 409, 'current' => $this->repository->get_contact( $id ) ) );
	}
}
