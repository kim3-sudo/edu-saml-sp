<?php
/**
 * User matching, JIT provisioning, and group -> role synchronization.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EDU_SAML_Provisioning {

	/**
	 * Usermeta key storing a hash of the immutable unique identifier
	 * (NameID / unique-id attribute) used to match a WP user on every login.
	 */
	const META_UNIQUE_ID_HASH = '_edu_saml_unique_id_hash';

	/**
	 * Usermeta key storing which attribute name was used to derive the
	 * unique identifier at provisioning time (informational only).
	 */
	const META_UNIQUE_ID_SOURCE = '_edu_saml_unique_id_source';

	/**
	 * Usermeta flag marking an account as created/managed by this plugin.
	 */
	const META_MANAGED = '_edu_saml_managed';

	/**
	 * Given the raw NameID + attributes from a validated SAML assertion,
	 * find or create the corresponding WP user, sync mutable fields and
	 * role, log them in, and return the WP_User. Returns a WP_Error on
	 * failure (always with a generic, non-enumerating message).
	 *
	 * @param string $name_id
	 * @param array  $attributes Raw attributes array as returned by OneLogin (name => array(values)).
	 * @return WP_User|WP_Error
	 */
	public static function process_assertion( $name_id, array $attributes ) {
		$settings = EDU_SAML_Settings::instance();

		$unique_id_attr = $settings->get( 'unique_id_attribute', 'email' );
		$unique_id_value = self::extract_attribute( $attributes, $unique_id_attr );

		// Fall back to NameID itself if the configured unique-id attribute
		// wasn't present in the assertion (common when NameID IS the
		// unique identifier, e.g. persistent/eduPersonPrincipalName-as-NameID).
		if ( '' === $unique_id_value ) {
			$unique_id_value = $name_id;
		}

		if ( '' === trim( (string) $unique_id_value ) ) {
			self::log( 'Assertion rejected: no usable unique identifier value found (NameID empty and attribute "' . $unique_id_attr . '" missing).' );
			return self::generic_error();
		}

		$unique_id_hash = self::hash_unique_id( $unique_id_value );

		$email      = self::extract_attribute( $attributes, $settings->get( 'attr_email', 'email' ) );
		$first_name = self::extract_attribute( $attributes, $settings->get( 'attr_first_name', 'firstName' ) );
		$last_name  = self::extract_attribute( $attributes, $settings->get( 'attr_last_name', 'lastName' ) );
		$groups     = self::extract_attribute_multi( $attributes, $settings->get( 'attr_groups', 'groups' ) );

		// Email is required to create or sanely update a WP user.
		if ( '' === trim( (string) $email ) && filter_var( $unique_id_value, FILTER_VALIDATE_EMAIL ) ) {
			$email = $unique_id_value;
		}

		$existing_user = self::find_user_by_unique_id_hash( $unique_id_hash );

		if ( $existing_user ) {
			return self::update_existing_user( $existing_user, $email, $first_name, $last_name, $groups );
		}

		if ( '1' !== $settings->get( 'auto_provision', '1' ) ) {
			self::log( 'Assertion rejected: no existing user matched unique id hash and auto-provisioning is disabled.' );
			return self::generic_error();
		}

		if ( '' === trim( (string) $email ) ) {
			$attr_email_key = $settings->get( 'attr_email', 'email' );
			self::log( 'Assertion rejected: cannot auto-provision without an email attribute value.' );
			self::log_verbose(
				'Email attribute "' . $attr_email_key . '" was not found (or was empty) in the assertion, and the unique identifier value did not look like an email address. Available assertion attribute names: ' . self::describe_attribute_names( $attributes ) . '.'
			);
			return self::generic_error();
		}

		return self::create_new_user( $unique_id_value, $unique_id_hash, $unique_id_attr, $email, $first_name, $last_name, $groups );
	}


	/**
	 * A single, generic, non-revealing error used for ALL provisioning /
	 * matching failures so we never leak whether a given identity exists.
	 *
	 * @return WP_Error
	 */
	private static function generic_error() {
		return new WP_Error(
			'edu_saml_auth_failed',
			__( 'Unable to sign you in with your institutional account. Please contact your administrator if this continues.', 'edu-saml-sp' )
		);
	}

	/**
	 * Write a detailed reason to a private server-side log only (never to
	 * the browser), to preserve anti-enumeration guarantees while still
	 * giving admins something to debug with. Written whenever WP_DEBUG is
	 * on, or the "Diagnostic Logging" setting is 'basic'/'verbose'.
	 *
	 * @param string $message
	 */
	private static function log( $message ) {
		if ( EDU_SAML_Settings::instance()->should_log( 'basic' ) ) {
			error_log( '[EDU SAML SP] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Write a more detailed diagnostic message that may include attribute
	 * names/values sourced from the IdP assertion. Only written when the
	 * "Diagnostic Logging" setting is explicitly 'verbose' (or WP_DEBUG is
	 * on), since this level of detail can include identity data.
	 *
	 * @param string $message
	 */
	private static function log_verbose( $message ) {
		if ( EDU_SAML_Settings::instance()->should_log( 'verbose' ) ) {
			error_log( '[EDU SAML SP] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Build a human-readable, comma-separated list of attribute names
	 * present in the assertion (names only, never values), for verbose
	 * diagnostic logging.
	 *
	 * @param array $attributes
	 * @return string
	 */
	private static function describe_attribute_names( array $attributes ) {
		$names = array_keys( $attributes );
		if ( empty( $names ) ) {
			return '(none)';
		}
		return implode( ', ', array_map( 'strval', $names ) );
	}


	/**
	 * Hash the unique identifier value for storage/lookup. We hash (rather
	 * than store in plaintext) so that a database leak of usermeta doesn't
	 * directly expose upstream IdP identifiers, and so lookups are exact
	 * fixed-length comparisons.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function hash_unique_id( $value ) {
		// Use a plugin/site-specific salt via wp_hash() (keyed on WP secret
		// keys) so identifiers cannot be trivially rainbow-tabled, while
		// remaining deterministic across requests.
		return hash_hmac( 'sha256', strtolower( trim( (string) $value ) ), wp_salt( 'edu_saml_sp' ) );
	}

	/**
	 * Find a WP user previously provisioned/linked with the given unique-id hash.
	 *
	 * @param string $hash
	 * @return WP_User|null
	 */
	private static function find_user_by_unique_id_hash( $hash ) {
		$users = get_users(
			array(
				'meta_key'   => self::META_UNIQUE_ID_HASH,
				'meta_value' => $hash,
				'number'     => 1,
				'fields'     => 'all',
			)
		);
		return ! empty( $users ) ? $users[0] : null;
	}

	/**
	 * Extract the first value of a named attribute from the OneLogin attributes array.
	 *
	 * @param array  $attributes
	 * @param string $name
	 * @return string
	 */
	private static function extract_attribute( array $attributes, $name ) {
		if ( '' === trim( (string) $name ) ) {
			return '';
		}
		if ( isset( $attributes[ $name ] ) && is_array( $attributes[ $name ] ) && ! empty( $attributes[ $name ] ) ) {
			return (string) $attributes[ $name ][0];
		}
		return '';
	}

	/**
	 * Extract ALL values of a named (potentially multi-valued) attribute,
	 * e.g. groups/memberOf.
	 *
	 * @param array  $attributes
	 * @param string $name
	 * @return array
	 */
	private static function extract_attribute_multi( array $attributes, $name ) {
		if ( '' === trim( (string) $name ) ) {
			return array();
		}
		if ( isset( $attributes[ $name ] ) && is_array( $attributes[ $name ] ) ) {
			return array_map( 'strval', $attributes[ $name ] );
		}
		return array();
	}

	/**
	 * Determine the WP role to assign based on the group memberships and
	 * the configured group -> role mapping. First match (in configured
	 * order) wins; falls back to the configured default role.
	 *
	 * @param array $groups
	 * @return string
	 */
	private static function resolve_role( array $groups ) {
		$settings = EDU_SAML_Settings::instance();
		$map      = $settings->get_group_role_map();
		$valid_roles = array_keys( function_exists( 'get_editable_roles' ) ? get_editable_roles() : array() );

		foreach ( $map as $group_value => $role ) {
			if ( in_array( $group_value, $groups, true ) && in_array( $role, $valid_roles, true ) ) {
				return $role;
			}
		}

		$default_role = $settings->get( 'default_role', 'subscriber' );
		return in_array( $default_role, $valid_roles, true ) ? $default_role : 'subscriber';
	}

	/**
	 * Update an existing matched user's mutable fields + role, then return it.
	 *
	 * @param WP_User $user
	 * @param string  $email
	 * @param string  $first_name
	 * @param string  $last_name
	 * @param array   $groups
	 * @return WP_User|WP_Error
	 */
	private static function update_existing_user( WP_User $user, $email, $first_name, $last_name, array $groups ) {
		$update = array( 'ID' => $user->ID );

		if ( '' !== trim( (string) $email ) && is_email( $email ) && $email !== $user->user_email ) {
			// Avoid colliding with a different existing account's email.
			$other = get_user_by( 'email', $email );
			if ( ! $other || (int) $other->ID === (int) $user->ID ) {
				$update['user_email'] = sanitize_email( $email );
			} else {
				self::log( 'Email sync skipped for user #' . $user->ID . ': email already used by another account.' );
			}
		}

		if ( '' !== trim( (string) $first_name ) ) {
			$update['first_name'] = sanitize_text_field( $first_name );
		}
		if ( '' !== trim( (string) $last_name ) ) {
			$update['last_name'] = sanitize_text_field( $last_name );
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_user( $update );
			if ( is_wp_error( $result ) ) {
				self::log( 'wp_update_user failed for user #' . $user->ID . ': ' . $result->get_error_message() );
				return self::generic_error();
			}
		} else {
			if ( isset( $update['first_name'] ) ) {
				update_user_meta( $user->ID, 'first_name', $update['first_name'] );
			}
			if ( isset( $update['last_name'] ) ) {
				update_user_meta( $user->ID, 'last_name', $update['last_name'] );
			}
		}

		// Re-sync role on every login, as configured.
		$role = self::resolve_role( $groups );
		$fresh_user = get_user_by( 'id', $user->ID );
		if ( $fresh_user && ! in_array( $role, (array) $fresh_user->roles, true ) ) {
			$fresh_user->set_role( $role );
		} elseif ( $fresh_user && empty( $fresh_user->roles ) ) {
			$fresh_user->set_role( $role );
		}

		return get_user_by( 'id', $user->ID );
	}

	/**
	 * Create a brand-new WP user for a first-time SAML login (JIT provisioning).
	 *
	 * @param string $unique_id_value
	 * @param string $unique_id_hash
	 * @param string $unique_id_attr
	 * @param string $email
	 * @param string $first_name
	 * @param string $last_name
	 * @param array  $groups
	 * @return WP_User|WP_Error
	 */
	private static function create_new_user( $unique_id_value, $unique_id_hash, $unique_id_attr, $email, $first_name, $last_name, array $groups ) {
		if ( ! is_email( $email ) ) {
			self::log( 'Cannot auto-provision: email attribute value is not a valid email address.' );
			return self::generic_error();
		}

		if ( email_exists( $email ) ) {
			// Email collides with an account not linked to this identity.
			// Do NOT silently take over that account -- fail generically.
			self::log( 'Cannot auto-provision: email already belongs to an unlinked existing account.' );
			return self::generic_error();
		}

		$username = self::generate_unique_username( $email, $unique_id_value );

		$random_password = wp_generate_password( 32, true, true );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => sanitize_email( $email ),
				'user_pass'    => $random_password,
				'first_name'   => sanitize_text_field( $first_name ),
				'last_name'    => sanitize_text_field( $last_name ),
				'display_name' => trim( sanitize_text_field( $first_name . ' ' . $last_name ) ) ?: $username,
				'role'         => self::resolve_role( $groups ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::log( 'wp_insert_user failed during auto-provisioning: ' . $user_id->get_error_message() );
			return self::generic_error();
		}

		update_user_meta( $user_id, self::META_UNIQUE_ID_HASH, $unique_id_hash );
		update_user_meta( $user_id, self::META_UNIQUE_ID_SOURCE, sanitize_text_field( $unique_id_attr ) );
		update_user_meta( $user_id, self::META_MANAGED, '1' );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Generate a unique, sanitized WP username from an email/unique id,
	 * appending a numeric suffix on collision.
	 *
	 * @param string $email
	 * @param string $fallback
	 * @return string
	 */
	private static function generate_unique_username( $email, $fallback ) {
		$base = '';
		if ( is_email( $email ) ) {
			$parts = explode( '@', $email );
			$base  = $parts[0];
		}
		if ( '' === $base ) {
			$base = $fallback;
		}

		$base = sanitize_user( $base, true );
		$base = strtolower( $base );
		if ( '' === $base ) {
			$base = 'sso_user';
		}

		$username = $base;
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			$suffix++;
			$username = $base . $suffix;
		}

		return $username;
	}
}
