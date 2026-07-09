<?php
/**
 * Settings storage, defaults, and sanitization for the EDU SAML SP plugin.
 *
 * All configuration lives in a single serialized option (not autoloaded)
 * so we never sprinkle dozens of individual wp_options rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EDU_SAML_Settings {

	/** @var EDU_SAML_Settings|null */
	private static $instance = null;

	/** @var array Cached options for this request. */
	private $options = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			// IdP configuration.
			'idp_entity_id'        => '',
			'idp_sso_url'           => '',
			'idp_slo_url'           => '',
			'idp_x509_cert'         => '',

			// SP configuration.
			'sp_entity_id'          => home_url( '/' ),

			// Login page.
			'sso_button_text'       => __( 'Sign in with your institutional account', 'edu-saml-sp' ),
			'sso_button_bg_color'      => '',
			'sso_button_text_color'    => '',
			'sso_button_hover_color'   => '',

			// NameID / identity.
			'nameid_format'         => 'emailAddress', // emailAddress | persistent | transient | unspecified.
			'unique_id_attribute'   => 'email',         // e.g. email, eduPersonPrincipalName, uid, objectidentifier.

			// Attribute mapping.
			'attr_email'            => 'email',
			'attr_first_name'       => 'firstName',
			'attr_last_name'        => 'lastName',
			'attr_groups'           => '',

			// Provisioning.
			'auto_provision'        => '1',   // '1' or '0'.
			'force_sso'             => '0',   // '1' or '0'.
			'default_role'          => 'subscriber',
			'group_role_map'        => '',    // newline-delimited "Group Value = wp_role".

			// Break-glass.
			'breakglass_usernames'  => array(), // array of WP usernames exempt from forced SSO.

			// Security.
			'want_assertions_signed'  => '1',
			'want_messages_signed'    => '0',

			// Assertion encryption (optional; not all IdPs support this).
			'want_assertions_encrypted'      => '0',   // '1' or '0'.
			'sp_x509_cert'                   => '',    // SP's public certificate (PEM), given to the IdP for encryption.
			'sp_private_key'                 => '',    // SP's private key (PEM), used to decrypt assertions.
			'assertion_encryption_algorithm' => 'aes256-gcm', // aes256-gcm | aes256-cbc.
			'key_transport_algorithm'        => 'rsa-oaep-sha256', // rsa-oaep-sha256 | rsa-oaep-sha1 | rsa-1_5.

			// Plugin settings.
			'diagnostic_logging'   => 'off', // off | basic | verbose.
		);
	}



	/**
	 * Get all options merged with defaults.
	 *
	 * @return array
	 */
	public function get_options() {
		if ( null === $this->options ) {
			$stored        = get_option( EDU_SAML_SP_OPTION_KEY, array() );
			$this->options = wp_parse_args( is_array( $stored ) ? $stored : array(), self::get_defaults() );
		}
		return $this->options;
	}

	/**
	 * Get a single option value.
	 *
	 * @param string $key
	 * @param mixed  $default_value
	 * @return mixed
	 */
	public function get( $key, $default_value = null ) {
		$opts = $this->get_options();
		return isset( $opts[ $key ] ) ? $opts[ $key ] : $default_value;
	}

	/**
	 * Persist a full options array (already sanitized).
	 *
	 * @param array $options
	 */
	public function update( array $options ) {
		$merged = wp_parse_args( $options, self::get_defaults() );
		update_option( EDU_SAML_SP_OPTION_KEY, $merged, false );
		$this->options = $merged;
	}

	/**
	 * Register the setting with WordPress Settings API (used mainly for the
	 * sanitize callback + nonce/capability plumbing that register_setting()
	 * gives us for free when the admin page uses settings_fields()).
	 */
	public function register_settings() {
		register_setting(
			'edu_saml_sp_group',
			EDU_SAML_SP_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Tabs that contain checkbox fields, mapped to the checkbox option keys
	 * they render. Used so that unchecking a box on the currently-submitted
	 * tab is respected (saved as '0'), while checkboxes that simply aren't
	 * present because they live on a *different* tab are left untouched
	 * (falling back to the existing stored value) instead of being wiped.
	 *
	 * @return array tab_slug => array of option keys.
	 */
	private static function checkbox_fields_by_tab() {
		return array(
			'provisioning' => array( 'auto_provision', 'force_sso' ),
			'idp'          => array( 'want_assertions_signed', 'want_messages_signed' ),
			'encryption'   => array( 'want_assertions_encrypted' ),
		);
	}


	/**
	 * Sanitize incoming settings form submission.
	 *
	 * This plugin's settings page is split across multiple tabs, each
	 * submitting only its own fields via the shared `options.php` handler
	 * for a single serialized option. To avoid one tab's save wiping out
	 * values that live on other tabs, any field not present in $input falls
	 * back to the *currently saved* value (not the hardcoded default).
	 *
	 * @param array $input Raw $_POST-derived array.
	 * @return array Sanitized options array.
	 */
	public function sanitize( $input ) {
		$defaults = self::get_defaults();

		// Baseline: whatever is already saved, merged with defaults so any
		// key that has never been saved still has a sane fallback.
		$stored   = get_option( EDU_SAML_SP_OPTION_KEY, array() );
		$existing = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );

		$sanitized = array();

		$sanitized['idp_entity_id'] = isset( $input['idp_entity_id'] ) ? sanitize_text_field( wp_unslash( $input['idp_entity_id'] ) ) : $existing['idp_entity_id'];
		$sanitized['idp_sso_url']   = isset( $input['idp_sso_url'] ) ? sanitize_url( wp_unslash( $input['idp_sso_url'] ) ) : $existing['idp_sso_url'];
		$sanitized['idp_slo_url']   = isset( $input['idp_slo_url'] ) ? sanitize_url( wp_unslash( $input['idp_slo_url'] ) ) : $existing['idp_slo_url'];

		// Certificate: strip anything that isn't part of a PEM block, but keep line structure.
		$sanitized['idp_x509_cert'] = isset( $input['idp_x509_cert'] ) ? $this->sanitize_pem( wp_unslash( $input['idp_x509_cert'] ) ) : $existing['idp_x509_cert'];

		$sanitized['sp_entity_id'] = isset( $input['sp_entity_id'] ) ? sanitize_text_field( wp_unslash( $input['sp_entity_id'] ) ) : $existing['sp_entity_id'];

		// SSO button text: keep the existing/default value if submitted blank
		// so the button never ends up with empty/missing label text.
		if ( isset( $input['sso_button_text'] ) && '' !== trim( wp_unslash( $input['sso_button_text'] ) ) ) {
			$sanitized['sso_button_text'] = sanitize_text_field( wp_unslash( $input['sso_button_text'] ) );
		} else {
			$sanitized['sso_button_text'] = $existing['sso_button_text'];
		}

		// SSO button colors: optional. Blank means "use the theme/WP admin
		// button default styling" -- so we only accept valid hex colors and
		// otherwise fall back to blank (not the previously saved value),
		// since an admin explicitly clearing the field should reset to default.
		$sanitized['sso_button_bg_color']    = isset( $input['sso_button_bg_color'] ) ? $this->sanitize_hex_color( $input['sso_button_bg_color'] ) : $existing['sso_button_bg_color'];
		$sanitized['sso_button_text_color']  = isset( $input['sso_button_text_color'] ) ? $this->sanitize_hex_color( $input['sso_button_text_color'] ) : $existing['sso_button_text_color'];
		$sanitized['sso_button_hover_color'] = isset( $input['sso_button_hover_color'] ) ? $this->sanitize_hex_color( $input['sso_button_hover_color'] ) : $existing['sso_button_hover_color'];

		$allowed_nameid_formats  = array( 'emailAddress', 'persistent', 'transient', 'unspecified' );
		$sanitized['nameid_format'] = ( isset( $input['nameid_format'] ) && in_array( $input['nameid_format'], $allowed_nameid_formats, true ) )
			? $input['nameid_format']
			: $existing['nameid_format'];

		$sanitized['unique_id_attribute'] = isset( $input['unique_id_attribute'] ) ? sanitize_text_field( wp_unslash( $input['unique_id_attribute'] ) ) : $existing['unique_id_attribute'];

		$sanitized['attr_email']      = isset( $input['attr_email'] ) ? sanitize_text_field( wp_unslash( $input['attr_email'] ) ) : $existing['attr_email'];
		$sanitized['attr_first_name'] = isset( $input['attr_first_name'] ) ? sanitize_text_field( wp_unslash( $input['attr_first_name'] ) ) : $existing['attr_first_name'];
		$sanitized['attr_last_name']  = isset( $input['attr_last_name'] ) ? sanitize_text_field( wp_unslash( $input['attr_last_name'] ) ) : $existing['attr_last_name'];
		$sanitized['attr_groups']     = isset( $input['attr_groups'] ) ? sanitize_text_field( wp_unslash( $input['attr_groups'] ) ) : $existing['attr_groups'];

		// Which tab was actually submitted (if any)? Used below to decide
		// whether an absent checkbox means "unchecked" or "not on this tab".
		$submitted_tab = isset( $input['_edu_saml_tab'] ) ? sanitize_key( wp_unslash( $input['_edu_saml_tab'] ) ) : '';
		$checkbox_map  = self::checkbox_fields_by_tab();

		foreach ( $checkbox_map as $tab_slug => $fields ) {
			foreach ( $fields as $field ) {
				if ( $tab_slug === $submitted_tab ) {
					// This tab was submitted: an absent checkbox truly means unchecked.
					$sanitized[ $field ] = ! empty( $input[ $field ] ) ? '1' : '0';
				} else {
					// A different tab was submitted: preserve the existing value
					// unless the field happens to be present anyway.
					$sanitized[ $field ] = isset( $input[ $field ] ) ? ( ! empty( $input[ $field ] ) ? '1' : '0' ) : $existing[ $field ];
				}
			}
		}

		$valid_roles = array_keys( function_exists( 'get_editable_roles' ) ? get_editable_roles() : array() );
		$sanitized['default_role'] = ( isset( $input['default_role'] ) && in_array( $input['default_role'], $valid_roles, true ) )
			? $input['default_role']
			: $existing['default_role'];

		$sanitized['group_role_map'] = isset( $input['group_role_map'] ) ? $this->sanitize_group_role_map( wp_unslash( $input['group_role_map'] ), $valid_roles ) : $existing['group_role_map'];

		$allowed_diagnostic_logging  = array( 'off', 'basic', 'verbose' );
		$sanitized['diagnostic_logging'] = ( isset( $input['diagnostic_logging'] ) && in_array( $input['diagnostic_logging'], $allowed_diagnostic_logging, true ) )
			? $input['diagnostic_logging']
			: $existing['diagnostic_logging'];


		// Break-glass usernames: newline or comma separated list -> normalized array of existing usernames.
		$sanitized['breakglass_usernames'] = isset( $input['breakglass_usernames'] )
			? $this->sanitize_breakglass_list( wp_unslash( $input['breakglass_usernames'] ) )
			: $existing['breakglass_usernames'];

		// Assertion encryption: SP certificate/private key pair, plus the
		// algorithm hints communicated to the IdP admin. This is entirely
		// optional -- not all IdPs support assertion encryption -- and is
		// gated by the 'want_assertions_encrypted' checkbox handled above.
		$sanitized['sp_x509_cert']   = isset( $input['sp_x509_cert'] ) ? $this->sanitize_pem( wp_unslash( $input['sp_x509_cert'] ) ) : $existing['sp_x509_cert'];
		$sanitized['sp_private_key'] = isset( $input['sp_private_key'] ) ? $this->sanitize_pem( wp_unslash( $input['sp_private_key'] ) ) : $existing['sp_private_key'];

		$allowed_assertion_algorithms = array( 'aes256-gcm', 'aes256-cbc' );
		$sanitized['assertion_encryption_algorithm'] = ( isset( $input['assertion_encryption_algorithm'] ) && in_array( $input['assertion_encryption_algorithm'], $allowed_assertion_algorithms, true ) )
			? $input['assertion_encryption_algorithm']
			: $existing['assertion_encryption_algorithm'];

		$allowed_key_transport_algorithms = array( 'rsa-oaep-sha256', 'rsa-oaep-sha1', 'rsa-1_5' );
		$sanitized['key_transport_algorithm'] = ( isset( $input['key_transport_algorithm'] ) && in_array( $input['key_transport_algorithm'], $allowed_key_transport_algorithms, true ) )
			? $input['key_transport_algorithm']
			: $existing['key_transport_algorithm'];

		// If encryption is enabled but the cert/key pair is incomplete,
		// warn the admin -- decryption cannot work without both.
		if ( '1' === $sanitized['want_assertions_encrypted'] && ( '' === $sanitized['sp_x509_cert'] || '' === $sanitized['sp_private_key'] ) ) {
			add_settings_error(
				'edu_saml_sp_group',
				'edu_saml_encryption_incomplete',
				__( '"Accept Encrypted Assertions" is enabled, but the SP certificate and/or private key is missing. Encrypted assertions cannot be decrypted until both are provided.', 'edu-saml-sp' ),
				'warning'
			);
		}


		// Security invariant: at least one of "require signed response" /
		// "require signed assertion" must always be enabled -- this plugin
		// will not accept fully unsigned SAML responses. Rather than trust
		// an admin to catch this themselves, enforce it here so it can
		// never be persisted with both disabled. Assertion-signing is
		// re-enabled as the fallback since it's the plugin's original
		// default and the behavior nearly all IdPs support out of the box.
		if ( '1' !== $sanitized['want_assertions_signed'] && '1' !== $sanitized['want_messages_signed'] ) {
			$sanitized['want_assertions_signed'] = '1';
			add_settings_error(
				'edu_saml_sp_group',
				'edu_saml_signing_requirement',
				__( 'At least one of "Require Signed Response" or "Require Signed Assertion" must be enabled. "Require Signed Assertion" has been re-enabled automatically.', 'edu-saml-sp' ),
				'warning'
			);
		}

		return $sanitized;
	}



	/**
	 * Sanitize a hex color value (e.g. "#1a2b3c" or "1a2b3c"). Returns an
	 * empty string for anything that doesn't validate, so callers can treat
	 * blank as "use default styling".
	 *
	 * @param string $raw
	 * @return string
	 */
	private function sanitize_hex_color( $raw ) {
		$raw = trim( wp_unslash( (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( function_exists( 'sanitize_hex_color' ) ) {
			$color = sanitize_hex_color( $raw );
			return $color ? $color : '';
		}
		// Fallback if sanitize_hex_color() isn't available for some reason.
		return preg_match( '/^#[0-9a-fA-F]{3,6}$/', $raw ) ? $raw : '';
	}

	/**
	 * Keep only valid PEM certificate content (defensive normalization).
	 *
	 * @param string $raw
	 * @return string
	 */
	private function sanitize_pem( $raw ) {
		$raw = trim( $raw );
		// Allow standard PEM structure only.
		if ( '' === $raw ) {
			return '';
		}
		// Strip anything not base64/PEM-safe, but keep BEGIN/END markers and newlines.
		$raw = preg_replace( '/[^A-Za-z0-9+\/=\-\r\n_ ]/', '', $raw );
		return trim( $raw );
	}

	/**
	 * Parse & sanitize "Group Value = wp_role" lines into a normalized string,
	 * dropping any lines with unknown roles.
	 *
	 * @param string $raw
	 * @param array  $valid_roles
	 * @return string
	 */
	private function sanitize_group_role_map( $raw, array $valid_roles ) {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$clean_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $group_value, $role ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$group_value = sanitize_text_field( $group_value );
			$role        = sanitize_key( $role );

			if ( '' === $group_value || ! in_array( $role, $valid_roles, true ) ) {
				continue;
			}
			$clean_lines[] = $group_value . ' = ' . $role;
		}

		return implode( "\n", $clean_lines );
	}

	/**
	 * Parse the group_role_map string into an associative array
	 * [ group_value => role ] for use at login time.
	 *
	 * @return array
	 */
	public function get_group_role_map() {
		$raw   = $this->get( 'group_role_map', '' );
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$map   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $group_value, $role ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $group_value && '' !== $role ) {
				$map[ $group_value ] = $role;
			}
		}

		return $map;
	}

	/**
	 * Normalize a raw break-glass usernames list (array from checkboxes, or
	 * newline/comma separated text) into a de-duplicated array of usernames
	 * that actually exist in WordPress.
	 *
	 * @param mixed $raw
	 * @return array
	 */
	private function sanitize_breakglass_list( $raw ) {
		if ( is_array( $raw ) ) {
			$candidates = $raw;
		} else {
			$candidates = preg_split( '/[\r\n,]+/', (string) $raw );
		}

		$usernames = array();
		foreach ( $candidates as $candidate ) {
			$candidate = sanitize_user( trim( $candidate ), true );
			if ( '' === $candidate ) {
				continue;
			}
			if ( get_user_by( 'login', $candidate ) ) {
				$usernames[] = $candidate;
			}
		}

		return array_values( array_unique( $usernames ) );
	}

	/**
	 * Is the given WP username exempt from forced SSO?
	 *
	 * @param string $username
	 * @return bool
	 */
	public function is_breakglass_username( $username ) {
		$list = (array) $this->get( 'breakglass_usernames', array() );
		return in_array( $username, $list, true );
	}

	/**
	 * Add a username to the break-glass list and persist.
	 *
	 * @param string $username
	 */
	public function add_breakglass_username( $username ) {
		$opts = $this->get_options();
		$list = (array) $opts['breakglass_usernames'];
		if ( ! in_array( $username, $list, true ) ) {
			$list[] = $username;
		}
		$opts['breakglass_usernames'] = array_values( array_unique( $list ) );
		$this->update( $opts );
	}

	/**
	 * Whether a diagnostic log message at the given level should be
	 * written. WP_DEBUG being enabled always unlocks at least 'basic'
	 * logging (for backward compatibility) and, since it typically implies
	 * a development/staging context, also unlocks 'verbose' logging. In
	 * production, the 'diagnostic_logging' setting is the sole gate.
	 *
	 * @param string $level 'basic' or 'verbose'.
	 * @return bool
	 */
	public function should_log( $level = 'basic' ) {
		$levels = array(
			'off'     => 0,
			'basic'   => 1,
			'verbose' => 2,
		);

		$requested = isset( $levels[ $level ] ) ? $levels[ $level ] : $levels['basic'];

		$configured = $this->get( 'diagnostic_logging', 'off' );
		$configured_rank = isset( $levels[ $configured ] ) ? $levels[ $configured ] : $levels['off'];

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$configured_rank = max( $configured_rank, $levels['verbose'] );
		}

		return $configured_rank >= $requested;
	}
}

