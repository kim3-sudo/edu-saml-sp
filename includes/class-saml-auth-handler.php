<?php
/**
 * Wires up the SAML endpoints (metadata, login, ACS, SLO) and the
 * Force-SSO login redirect logic (with break-glass exemption).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EDU_SAML_Auth_Handler {

	/** @var EDU_SAML_Auth_Handler|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Public (nopriv) + logged-in endpoints via admin-post.php.
		add_action( 'admin_post_nopriv_edu_saml_metadata', array( $this, 'handle_metadata' ) );
		add_action( 'admin_post_edu_saml_metadata', array( $this, 'handle_metadata' ) );

		add_action( 'admin_post_nopriv_edu_saml_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_edu_saml_login', array( $this, 'handle_login' ) );

		add_action( 'admin_post_nopriv_edu_saml_acs', array( $this, 'handle_acs' ) );
		add_action( 'admin_post_edu_saml_acs', array( $this, 'handle_acs' ) );

		add_action( 'admin_post_nopriv_edu_saml_slo', array( $this, 'handle_slo' ) );
		add_action( 'admin_post_edu_saml_slo', array( $this, 'handle_slo' ) );

		EDU_SAML_Breakglass::init();

		// Force-SSO: redirect the standard login form to the IdP unless the
		// request is explicitly using the break-glass escape hatch.
		add_action( 'login_init', array( $this, 'maybe_force_sso_redirect' ) );

		// Add a small "Administrator / break-glass login" link to wp-login.php
		// so break-glass admins always have a path to the password form even
		// when Force SSO is enabled.
		add_action( 'login_footer', array( $this, 'render_breakglass_link' ) );

		// Render the "Sign in with <IdP>" SSO button above the normal
		// username/password form (when SSO is configured and not already
		// force-redirecting away from this page).
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_assets' ) );
		// Note: the 'login_form' action fires late in wp-login.php's markup
		// (right before the submit button), which is AFTER the username and
		// password fields have already been output. That caused the SSO
		// button + "or" divider to render below the normal login form
		// instead of above it. 'login_message' fires just below the logo,
		// before the <form> markup begins, so hooking it there renders the
		// SSO button + divider in the correct visual position (above the
		// username/password fields).
		add_action( 'login_message', array( $this, 'render_sso_button' ) );

		// Print inline CSS for the button's hover/focus color (needs its own
		// <style> block since pseudo-classes can't be set via style="").
		add_action( 'login_head', array( $this, 'print_custom_color_css' ) );
	}


	/**
	 * Serve SP metadata XML.
	 */
	public function handle_metadata() {
		if ( edu_saml_sp_library_missing() ) {
			wp_die( esc_html__( 'SAML library is not installed. Run composer install in the plugin directory.', 'edu-saml-sp' ) );
		}

		try {
			$settings_obj = new \OneLogin\Saml2\Settings( EDU_SAML_SP::get_saml_settings(), true );
			$metadata     = $settings_obj->getSPMetadata();
			header( 'Content-Type: text/xml' );
			echo $metadata; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} catch ( \Exception $e ) {
			wp_die( esc_html__( 'Unable to generate SP metadata. Check your SAML settings.', 'edu-saml-sp' ) );
		}
		exit;
	}

	/**
	 * Initiate SP-initiated SSO login (redirect to IdP with AuthnRequest).
	 */
	public function handle_login() {
		if ( edu_saml_sp_library_missing() || ! EDU_SAML_SP::is_configured() ) {
			wp_die( esc_html__( 'SAML SSO is not fully configured. Please contact your administrator.', 'edu-saml-sp' ) );
		}

		$redirect_to = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : admin_url();
		$redirect_to = wp_validate_redirect( $redirect_to, admin_url() );

		try {
			$auth = EDU_SAML_SP::get_auth();
			$auth->login( $redirect_to );
		} catch ( \Exception $e ) {
			wp_die( esc_html__( 'Unable to start SSO login. Please contact your administrator.', 'edu-saml-sp' ) );
		}
		exit;
	}

	/**
	 * Assertion Consumer Service: process the SAMLResponse POST from the IdP.
	 */
	public function handle_acs() {
		if ( edu_saml_sp_library_missing() || ! EDU_SAML_SP::is_configured() ) {
			wp_die( esc_html__( 'SAML SSO is not fully configured. Please contact your administrator.', 'edu-saml-sp' ) );
		}

		try {
			$auth = EDU_SAML_SP::get_auth();
			$auth->processResponse();

			$errors = $auth->getErrors();
			if ( ! empty( $errors ) ) {
				$this->log_and_fail( 'ACS processing errors: ' . implode( '; ', $errors ) . ' | Last reason: ' . $auth->getLastErrorReason() );
			}

			if ( ! $auth->isAuthenticated() ) {
				$this->log_and_fail( 'ACS: assertion did not result in an authenticated session.' );
			}

			$name_id    = $auth->getNameId();
			$attributes = $auth->getAttributes();

			$user_or_error = EDU_SAML_Provisioning::process_assertion( $name_id, $attributes );

			if ( is_wp_error( $user_or_error ) ) {
				$this->fail_login_generic();
			}

			$this->log_in_user( $user_or_error );

			$relay_state = isset( $_POST['RelayState'] ) ? wp_unslash( $_POST['RelayState'] ) : '';
			$redirect_to = $relay_state ? wp_validate_redirect( $relay_state, admin_url() ) : admin_url();

			// Avoid redirecting back to the SP metadata/login endpoints in a loop.
			if ( false !== strpos( $redirect_to, 'admin-post.php' ) ) {
				$redirect_to = admin_url();
			}

			wp_safe_redirect( $redirect_to );
			exit;

		} catch ( \Exception $e ) {
			$this->log_and_fail( 'ACS exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Single Logout Service endpoint.
	 */
	public function handle_slo() {
		if ( edu_saml_sp_library_missing() || ! EDU_SAML_SP::is_configured() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		try {
			$auth = EDU_SAML_SP::get_auth();
			// This will either redirect to the IdP (SP-initiated logout) or
			// finish processing an IdP-initiated LogoutRequest/Response.
			$auth->processSLO( false, null, false, function() {
				wp_logout();
			} );

			$errors = $auth->getErrors();
			if ( ! empty( $errors ) ) {
				$this->log( 'SLO errors: ' . implode( '; ', $errors ) );
			}
		} catch ( \Exception $e ) {
			$this->log( 'SLO exception: ' . $e->getMessage() );
		}

		if ( is_user_logged_in() ) {
			wp_logout();
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Log the WP user in via standard auth cookie mechanics.
	 *
	 * @param WP_User $user
	 */
	private function log_in_user( WP_User $user ) {
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		do_action( 'wp_login', $user->user_login, $user );
	}

	/**
	 * Log a detailed reason server-side and die with a generic public message.
	 *
	 * @param string $detail
	 */
	private function log_and_fail( $detail ) {
		$this->log( $detail );
		$this->fail_login_generic();
	}

	/**
	 * Generic failure response shown to the browser (anti-enumeration).
	 */
	private function fail_login_generic() {
		$login_url = wp_login_url();
		$message   = __( 'Unable to sign you in with your institutional account. Please contact your administrator if this continues.', 'edu-saml-sp' );
		wp_safe_redirect( add_query_arg( 'saml_error', rawurlencode( $message ), $login_url ) );
		exit;
	}

	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[EDU SAML SP] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Redirect wp-login.php to the IdP when Force SSO is enabled, unless
	 * the request is using the break-glass escape hatch or is itself part
	 * of the SAML flow (avoid infinite redirect loops), or the user is a
	 * designated break-glass account attempting a normal password login.
	 */
	public function maybe_force_sso_redirect() {
		$settings = EDU_SAML_Settings::instance();

		if ( '1' !== $settings->get( 'force_sso', '0' ) ) {
			return;
		}

		if ( ! EDU_SAML_SP::is_configured() ) {
			return; // Don't lock everyone out if SAML isn't configured yet.
		}

		// Allow the break-glass escape hatch query param to render the
		// normal login form. This does NOT bypass authentication -- it only
		// bypasses the automatic redirect so the password form is reachable.
		if ( isset( $_GET['breakglass'] ) && '1' === $_GET['breakglass'] ) {
			return;
		}

		// Allow logout, password reset, and registration actions to proceed
		// normally rather than forcing them through SSO.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		$exempt_actions = array( 'logout', 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'register', 'postpass' );
		if ( in_array( $action, $exempt_actions, true ) ) {
			return;
		}

		// If this is a POST login attempt for a break-glass username, allow
		// it to proceed to normal WP authentication instead of redirecting.
		if ( 'login' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['log'] ) ) {
			$attempted_username = sanitize_user( wp_unslash( $_POST['log'] ), true );
			if ( EDU_SAML_Breakglass::is_exempt( $attempted_username ) ) {
				return;
			}
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : admin_url();
		$sso_url     = add_query_arg( 'redirect_to', rawurlencode( wp_validate_redirect( $redirect_to, admin_url() ) ), EDU_SAML_SP::get_login_url() );

		wp_safe_redirect( $sso_url );
		exit;
	}

	/**
	 * Render a small, unobtrusive link on wp-login.php that lets break-glass
	 * admins reach the normal password form even when Force SSO is enabled.
	 */
	public function render_breakglass_link() {
		$settings = EDU_SAML_Settings::instance();
		if ( '1' !== $settings->get( 'force_sso', '0' ) ) {
			return;
		}
		$url = add_query_arg( 'breakglass', '1', wp_login_url() );
		echo '<p style="text-align:center;margin-top:1em;"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Administrator login', 'edu-saml-sp' ) . '</a></p>';
	}

	/**
	 * Enqueue the small stylesheet used by the SSO button on wp-login.php.
	 */
	public function enqueue_login_assets() {
		if ( ! $this->should_show_sso_button() ) {
			return;
		}
		wp_enqueue_style(
			'edu-saml-sp-login',
			EDU_SAML_SP_URL . 'assets/login.css',
			array(),
			EDU_SAML_SP_VERSION
		);
	}

	/**
	 * Whether the "Sign in with SSO" button should be shown on the current
	 * wp-login.php request.
	 *
	 * @return bool
	 */
	private function should_show_sso_button() {
		if ( edu_saml_sp_library_missing() || ! EDU_SAML_SP::is_configured() ) {
			return false;
		}

		// Only show on the actual login form view (not lost-password,
		// register, resetpass, logout, etc.).
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		if ( 'login' !== $action ) {
			return false;
		}

		return true;
	}

	/**
	 * Render a "Sign in with your institutional account" SSO button above
	 * the normal username/password form on wp-login.php.
	 *
	 * When Force SSO is enabled, users are already redirected away before
	 * this ever renders (see maybe_force_sso_redirect()) -- this button
	 * only appears in the non-forced case, or when the break-glass escape
	 * hatch query param is present, giving admins a way back to SSO too.
	 */
	public function render_sso_button() {
		if ( ! $this->should_show_sso_button() ) {
			return;
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : admin_url();
		$sso_url     = add_query_arg(
			'redirect_to',
			rawurlencode( wp_validate_redirect( $redirect_to, admin_url() ) ),
			EDU_SAML_SP::get_login_url()
		);

		$button_text = EDU_SAML_Settings::instance()->get( 'sso_button_text', __( 'Sign in with your institutional account', 'edu-saml-sp' ) );
		if ( '' === trim( (string) $button_text ) ) {
			$button_text = __( 'Sign in with your institutional account', 'edu-saml-sp' );
		}

		echo '<div class="edu-saml-sso-wrap">';
		$style = $this->get_sso_button_inline_style();
		echo '<a class="button button-primary button-hero edu-saml-sso-button" href="' . esc_url( $sso_url ) . '"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		echo esc_html( $button_text );
		echo '</a>';
		echo '<div class="edu-saml-sso-divider"><span>' . esc_html__( 'or', 'edu-saml-sp' ) . '</span></div>';
		echo '</div>';
	}

	/**
	 * Build an inline style attribute value for the SSO button's background
	 * and text colors, if configured. Returns an empty string when no
	 * custom colors are set (falls back to default WP button styling).
	 * Hover color is applied separately via a small inline <style> block
	 * (see enqueue_login_assets()/print_custom_color_css()) since CSS
	 * pseudo-classes can't be expressed via the style="" attribute.
	 *
	 * @return string
	 */
	private function get_sso_button_inline_style() {
		$settings = EDU_SAML_Settings::instance();
		$bg       = $settings->get( 'sso_button_bg_color', '' );
		$text     = $settings->get( 'sso_button_text_color', '' );

		$parts = array();
		if ( $bg ) {
			$parts[] = 'background-color:' . $bg;
			$parts[] = 'border-color:' . $bg;
		}
		if ( $text ) {
			$parts[] = 'color:' . $text;
		}

		return implode( ';', $parts );
	}

	/**
	 * Print a small inline <style> block for the SSO button's hover/focus
	 * background color, when configured. Hooked to login_head so it's
	 * output in the document <head> alongside the enqueued stylesheet.
	 */
	public function print_custom_color_css() {
		if ( ! $this->should_show_sso_button() ) {
			return;
		}

		$settings = EDU_SAML_Settings::instance();
		$hover    = $settings->get( 'sso_button_hover_color', '' );
		$text     = $settings->get( 'sso_button_text_color', '' );

		if ( ! $hover ) {
			return;
		}

		echo '<style type="text/css">';
		echo '.edu-saml-sso-button.button.button-hero:hover,';
		echo '.edu-saml-sso-button.button.button-hero:focus,';
		echo '.edu-saml-sso-button.button.button-hero:active {';
		echo 'background-color:' . esc_attr( $hover ) . ';';
		echo 'border-color:' . esc_attr( $hover ) . ';';
		if ( $text ) {
			echo 'color:' . esc_attr( $text ) . ';';
		}
		echo '}';
		echo '</style>';
	}
}
