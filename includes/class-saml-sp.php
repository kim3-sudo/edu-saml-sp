<?php
/**
 * Builds the OneLogin\Saml2\Auth settings array from plugin options and
 * provides small helper wrappers used by the auth handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EDU_SAML_SP {

	/**
	 * NameID format constants map (plugin option value -> full SAML URN).
	 *
	 * @return array
	 */
	public static function nameid_format_map() {
		return array(
			'emailAddress' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
			'persistent'   => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
			'transient'    => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
			'unspecified'  => 'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified',
		);
	}

	/**
	 * Build the settings array consumed by \OneLogin\Saml2\Auth.
	 *
	 * @return array
	 */
	public static function get_saml_settings() {
		$settings = EDU_SAML_Settings::instance();
		$formats  = self::nameid_format_map();

		$nameid_format_key = $settings->get( 'nameid_format', 'emailAddress' );
		$nameid_format      = isset( $formats[ $nameid_format_key ] ) ? $formats[ $nameid_format_key ] : $formats['emailAddress'];

		$sp_entity_id = $settings->get( 'sp_entity_id', home_url( '/' ) );

		return array(
			'strict' => true,
			'debug'  => defined( 'WP_DEBUG' ) && WP_DEBUG,

			'sp' => array(
				'entityId' => $sp_entity_id,
				'assertionConsumerService' => array(
					'url'     => self::get_acs_url(),
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
				),
				'singleLogoutService' => array(
					'url'     => self::get_sls_url(),
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
				),
				'NameIDFormat' => $nameid_format,
			),

			'idp' => array(
				'entityId' => $settings->get( 'idp_entity_id', '' ),
				'singleSignOnService' => array(
					'url'     => $settings->get( 'idp_sso_url', '' ),
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
				),
				'singleLogoutService' => array(
					'url'     => $settings->get( 'idp_slo_url', '' ),
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
				),
				'x509cert' => $settings->get( 'idp_x509_cert', '' ),
			),

			'security' => array(
				'nameIdEncrypted'              => false,
				'authnRequestsSigned'          => false,
				'logoutRequestSigned'          => false,
				'logoutResponseSigned'         => false,
				'wantMessagesSigned'           => '1' === $settings->get( 'want_messages_signed', '0' ),
				'wantAssertionsSigned'         => '1' === $settings->get( 'want_assertions_signed', '1' ),
				'wantNameId'                   => true,
				'wantAssertionsEncrypted'      => false,
				'wantNameIdEncrypted'          => false,
				'requestedAuthnContext'        => false,
				'signMetadata'                 => false,
			),
		);
	}

	/**
	 * Instantiate a OneLogin\Saml2\Auth object using our settings.
	 *
	 * @return \OneLogin\Saml2\Auth
	 */
	public static function get_auth() {
		return new \OneLogin\Saml2\Auth( self::get_saml_settings() );
	}

	/**
	 * URL WordPress listens on for the Assertion Consumer Service (ACS).
	 *
	 * @return string
	 */
	public static function get_acs_url() {
		return admin_url( 'admin-post.php?action=edu_saml_acs' );
	}

	/**
	 * URL WordPress listens on for Single Logout Service (SLS).
	 *
	 * @return string
	 */
	public static function get_sls_url() {
		return admin_url( 'admin-post.php?action=edu_saml_slo' );
	}

	/**
	 * URL that initiates SP-initiated SSO login.
	 *
	 * @return string
	 */
	public static function get_login_url() {
		return admin_url( 'admin-post.php?action=edu_saml_login' );
	}

	/**
	 * URL that serves SP metadata XML.
	 *
	 * @return string
	 */
	public static function get_metadata_url() {
		return admin_url( 'admin-post.php?action=edu_saml_metadata' );
	}

	/**
	 * Whether the plugin has the minimum configuration needed to attempt SSO.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = EDU_SAML_Settings::instance();
		return (bool) $settings->get( 'idp_sso_url' ) && (bool) $settings->get( 'idp_x509_cert' ) && (bool) $settings->get( 'sp_entity_id' );
	}
}
