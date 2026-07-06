<?php
/**
 * Break-glass account management: designating exempt usernames and
 * one-click creation of a new local administrator account for emergency
 * access when SSO/IdP is unavailable or misconfigured.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EDU_SAML_Breakglass {

	const ACTION_CREATE = 'edu_saml_create_breakglass';
	const NONCE_ACTION   = 'edu_saml_create_breakglass_action';

	/**
	 * Hook admin-post handler for creating a break-glass account.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_CREATE, array( __CLASS__, 'handle_create_request' ) );
	}

	/**
	 * Handle the "Create Break-Glass Admin Account" button submission.
	 */
	public static function handle_create_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'edu-saml-sp' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$result = self::create_breakglass_account();

		$redirect = add_query_arg(
			array(
				'page' => 'edu-saml-sp',
				'tab'  => 'breakglass',
			),
			admin_url( 'options-general.php' )
		);

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'edu_saml_bg_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			// Stash the one-time credentials in a short-lived transient keyed
			// to the current admin user, so we can display them exactly once
			// on the next page load without putting the password in the URL
			// or in any persistent storage.
			set_transient(
				'edu_saml_bg_credentials_' . get_current_user_id(),
				array(
					'username' => $result['username'],
					'password' => $result['password'],
				),
				60 // seconds; single render then gone.
			);
			$redirect = add_query_arg( 'edu_saml_bg_created', '1', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Create a new local administrator account, generate a strong random
	 * password, and register it in the break-glass exempt list.
	 *
	 * @return array|WP_Error array( 'username' => ..., 'password' => ... ) on success.
	 */
	public static function create_breakglass_account() {
		$settings = EDU_SAML_Settings::instance();

		$base     = 'breakglass_admin';
		$username = $base;
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			$suffix++;
			$username = $base . $suffix;
		}

		$password = wp_generate_password( 24, true, true );

		// Use a non-routable placeholder domain for the required unique
		// email; admins should update this to a real monitored address
		// after creation via normal WP user management.
		$email = $username . '@invalid.local';
		$suffix2 = 1;
		while ( email_exists( $email ) ) {
			$suffix2++;
			$email = $username . $suffix2 . '@invalid.local';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'role'         => 'administrator',
				'display_name' => 'Break-Glass Administrator',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, '_edu_saml_breakglass', '1' );

		$settings->add_breakglass_username( $username );

		return array(
			'username' => $username,
			'password' => $password,
		);
	}

	/**
	 * Retrieve and immediately delete the one-time credentials transient
	 * for the current admin, if present.
	 *
	 * @return array|null
	 */
	public static function consume_pending_credentials() {
		$key   = 'edu_saml_bg_credentials_' . get_current_user_id();
		$value = get_transient( $key );
		if ( false !== $value ) {
			delete_transient( $key );
			return $value;
		}
		return null;
	}

	/**
	 * Is the currently attempting login username exempt from forced SSO?
	 *
	 * @param string $username
	 * @return bool
	 */
	public static function is_exempt( $username ) {
		$settings = EDU_SAML_Settings::instance();
		return $settings->is_breakglass_username( $username );
	}
}
