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

			// NameID / identity.
			'nameid_format'         => 'emailAddress', // emailAddress | persistent | transient | unspecified.
			'unique_id_attribute'   => 'email',         // e.g. email, eduPersonPrincipalName, uid, objectidentifier.

			// Attribute mapping.
			'attr_email'            => 'email',
			'attr_first_name'       => 'firstName',
			'attr_last_name'        => 'lastName',
			'attr_groups'           => 'groups',

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
	 * Sanitize incoming settings form submission.
	 *
	 * @param array $input Raw $_POST-derived array.
	 * @return array Sanitized options array.
	 */
	public function sanitize( $input ) {
		$defaults  = self::get_defaults();
		$sanitized = array();

		$sanitized['idp_entity_id'] = isset( $input['idp_entity_id'] ) ? sanitize_text_field( wp_unslash( $input['idp_entity_id'] ) ) : $defaults['idp_entity_id'];
		$sanitized['idp_sso_url']   = isset( $input['idp_sso_url'] ) ? sanitize_url( wp_unslash( $input['idp_sso_url'] ) ) : $defaults['idp_sso_url'];
		$sanitized['idp_slo_url']   = isset( $input['idp_slo_url'] ) ? sanitize_url( wp_unslash( $input['idp_slo_url'] ) ) : $defaults['idp_slo_url'];

		// Certificate: strip anything that isn't part of a PEM block, but keep line structure.
		$sanitized['idp_x509_cert'] = isset( $input['idp_x509_cert'] ) ? $this->sanitize_pem( wp_unslash( $input['idp_x509_cert'] ) ) : $defaults['idp_x509_cert'];

		$sanitized['sp_entity_id'] = isset( $input['sp_entity_id'] ) ? sanitize_text_field( wp_unslash( $input['sp_entity_id'] ) ) : $defaults['sp_entity_id'];

		$allowed_nameid_formats  = array( 'emailAddress', 'persistent', 'transient', 'unspecified' );
		$sanitized['nameid_format'] = ( isset( $input['nameid_format'] ) && in_array( $input['nameid_format'], $allowed_nameid_formats, true ) )
			? $input['nameid_format']
			: $defaults['nameid_format'];

		$sanitized['unique_id_attribute'] = isset( $input['unique_id_attribute'] ) ? sanitize_text_field( wp_unslash( $input['unique_id_attribute'] ) ) : $defaults['unique_id_attribute'];

		$sanitized['attr_email']      = isset( $input['attr_email'] ) ? sanitize_text_field( wp_unslash( $input['attr_email'] ) ) : $defaults['attr_email'];
		$sanitized['attr_first_name'] = isset( $input['attr_first_name'] ) ? sanitize_text_field( wp_unslash( $input['attr_first_name'] ) ) : $defaults['attr_first_name'];
		$sanitized['attr_last_name']  = isset( $input['attr_last_name'] ) ? sanitize_text_field( wp_unslash( $input['attr_last_name'] ) ) : $defaults['attr_last_name'];
		$sanitized['attr_groups']     = isset( $input['attr_groups'] ) ? sanitize_text_field( wp_unslash( $input['attr_groups'] ) ) : $defaults['attr_groups'];

		$sanitized['auto_provision'] = ! empty( $input['auto_provision'] ) ? '1' : '0';
		$sanitized['force_sso']      = ! empty( $input['force_sso'] ) ? '1' : '0';

		$valid_roles = array_keys( function_exists( 'get_editable_roles' ) ? get_editable_roles() : array() );
		$sanitized['default_role'] = ( isset( $input['default_role'] ) && in_array( $input['default_role'], $valid_roles, true ) )
			? $input['default_role']
			: $defaults['default_role'];

		$sanitized['group_role_map'] = isset( $input['group_role_map'] ) ? $this->sanitize_group_role_map( wp_unslash( $input['group_role_map'] ), $valid_roles ) : $defaults['group_role_map'];

		// Break-glass usernames: newline or comma separated list -> normalized array of existing usernames.
		$sanitized['breakglass_usernames'] = isset( $input['breakglass_usernames'] )
			? $this->sanitize_breakglass_list( wp_unslash( $input['breakglass_usernames'] ) )
			: $defaults['breakglass_usernames'];

		$sanitized['want_assertions_signed'] = ! empty( $input['want_assertions_signed'] ) ? '1' : '0';
		$sanitized['want_messages_signed']   = ! empty( $input['want_messages_signed'] ) ? '1' : '0';

		return $sanitized;
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
}
