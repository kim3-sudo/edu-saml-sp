<?php
/**
 * AJAX-backed importer that lets an administrator auto-populate the IdP
 * Metadata fields (Entity ID, SSO URL, SLO URL, x.509 Certificate) by
 * pasting a metadata URL or uploading a metadata XML file, using the
 * bundled OneLogin\Saml2\IdPMetadataParser to do the actual XML parsing.
 *
 * This class never writes to the plugin's stored options itself -- it only
 * returns parsed values to the browser via AJAX so the admin can review
 * them in the form and click the normal "Save Changes" button.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EDU_SAML_IdP_Metadata_Importer {

	const ACTION = 'edu_saml_parse_idp_metadata';
	const NONCE_ACTION = 'edu_saml_parse_idp_metadata_action';

	/** @var EDU_SAML_IdP_Metadata_Importer|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_ajax_request' ) );
	}

	/**
	 * AJAX handler: parse IdP metadata from a URL or uploaded file and
	 * return the extracted fields as JSON. Never persists anything.
	 */
	public function handle_ajax_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'edu-saml-sp' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( edu_saml_sp_library_missing() ) {
			wp_send_json_error( array( 'message' => __( 'The onelogin/php-saml library is not installed. Run composer install in the plugin directory to enable this feature.', 'edu-saml-sp' ) ) );
		}

		$xml = '';

		if ( ! empty( $_FILES['metadata_file'] ) && UPLOAD_ERR_NO_FILE !== $_FILES['metadata_file']['error'] ) {
			$xml = $this->read_uploaded_file( $_FILES['metadata_file'] );
			if ( is_wp_error( $xml ) ) {
				wp_send_json_error( array( 'message' => $xml->get_error_message() ) );
			}
		} elseif ( ! empty( $_POST['metadata_url'] ) ) {
			$url = sanitize_url( wp_unslash( $_POST['metadata_url'] ) );
			$xml = $this->fetch_remote_xml( $url );
			if ( is_wp_error( $xml ) ) {
				wp_send_json_error( array( 'message' => $xml->get_error_message() ) );
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Please provide a metadata URL or upload a metadata file.', 'edu-saml-sp' ) ) );
		}

		$fields = $this->parse_metadata_xml( $xml );
		if ( is_wp_error( $fields ) ) {
			wp_send_json_error( array( 'message' => $fields->get_error_message() ) );
		}

		wp_send_json_success( $fields );
	}

	/**
	 * Read and validate an uploaded metadata file, returning its raw XML
	 * contents. The file is processed entirely in memory/temp upload
	 * storage -- it is never copied into a permanent plugin/media location.
	 *
	 * @param array $file $_FILES['metadata_file'].
	 * @return string|WP_Error
	 */
	private function read_uploaded_file( array $file ) {
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'edu_saml_upload_error', __( 'The file could not be uploaded. Please try again.', 'edu-saml-sp' ) );
		}

		// Reasonable size ceiling -- legitimate IdP metadata documents are
		// tiny (a few KB); this just guards against pathological uploads.
		$max_bytes = 1024 * 1024; // 1MB.
		if ( ! empty( $file['size'] ) && $file['size'] > $max_bytes ) {
			return new WP_Error( 'edu_saml_upload_too_large', __( 'The uploaded file is too large to be valid IdP metadata.', 'edu-saml-sp' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'edu_saml_upload_invalid', __( 'Invalid file upload.', 'edu-saml-sp' ) );
		}

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === trim( $contents ) ) {
			return new WP_Error( 'edu_saml_upload_empty', __( 'The uploaded file is empty or could not be read.', 'edu-saml-sp' ) );
		}

		return $contents;
	}

	/**
	 * Fetch raw XML from a metadata URL, restricted to http(s) schemes.
	 *
	 * Note: as with the underlying onelogin/php-saml library, this method
	 * does not attempt to defend against SSRF beyond the scheme check --
	 * this feature should only be used with a URL trusted by the site
	 * administrator (i.e. the IdP's own published metadata endpoint).
	 *
	 * @param string $url
	 * @return string|WP_Error
	 */
	private function fetch_remote_xml( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'edu_saml_invalid_url', __( 'Please enter a valid http:// or https:// metadata URL.', 'edu-saml-sp' ) );
		}

		try {
			$xml = \OneLogin\Saml2\IdPMetadataParser::parseRemoteXML( $url );
		} catch ( \Exception $e ) {
			return new WP_Error( 'edu_saml_fetch_failed', __( 'Unable to retrieve metadata from that URL:', 'edu-saml-sp' ) . ' ' . $e->getMessage() );
		}

		// parseRemoteXML() returns the already-parsed array, not raw XML,
		// so short-circuit by handing it straight back in the shape our
		// normalizer expects (see parse_metadata_xml()).
		return $this->extract_fields_from_parsed_array( $xml );
	}

	/**
	 * Parse a raw XML string (from an uploaded file) into our normalized
	 * field array using the library's IdPMetadataParser::parseXML().
	 *
	 * @param string|array $xml_or_parsed Raw XML string, or an already-parsed array (from fetch_remote_xml()).
	 * @return array|WP_Error
	 */
	private function parse_metadata_xml( $xml_or_parsed ) {
		// fetch_remote_xml() already returns our normalized field array (or
		// a WP_Error, handled by the caller before reaching here).
		if ( is_array( $xml_or_parsed ) ) {
			return $xml_or_parsed;
		}

		try {
			$parsed = \OneLogin\Saml2\IdPMetadataParser::parseXML( $xml_or_parsed );
		} catch ( \Exception $e ) {
			return new WP_Error( 'edu_saml_parse_failed', __( 'Unable to parse the provided metadata:', 'edu-saml-sp' ) . ' ' . $e->getMessage() );
		}

		return $this->extract_fields_from_parsed_array( $parsed );
	}

	/**
	 * Normalize the php-saml library's parsed metadata array into the flat
	 * set of fields used by this plugin's IdP settings.
	 *
	 * @param array $parsed Result of IdPMetadataParser::parseXML()/parseRemoteXML().
	 * @return array|WP_Error
	 */
	private function extract_fields_from_parsed_array( $parsed ) {
		if ( empty( $parsed ) || empty( $parsed['idp'] ) ) {
			return new WP_Error( 'edu_saml_no_idp_descriptor', __( 'No IDPSSODescriptor was found in that metadata document. Please double-check the URL/file and try again.', 'edu-saml-sp' ) );
		}

		$idp = $parsed['idp'];

		$cert = '';
		if ( ! empty( $idp['x509cert'] ) ) {
			$cert = $idp['x509cert'];
		} elseif ( ! empty( $idp['x509certMulti']['signing'][0] ) ) {
			$cert = $idp['x509certMulti']['signing'][0];
		} elseif ( ! empty( $idp['x509certMulti']['encryption'][0] ) ) {
			$cert = $idp['x509certMulti']['encryption'][0];
		}
		if ( '' !== $cert ) {
			$cert = \OneLogin\Saml2\Utils::formatCert( $cert, true );
		}

		return array(
			'idp_entity_id' => isset( $idp['entityId'] ) ? $idp['entityId'] : '',
			'idp_sso_url'   => isset( $idp['singleSignOnService']['url'] ) ? $idp['singleSignOnService']['url'] : '',
			'idp_slo_url'   => isset( $idp['singleLogoutService']['url'] ) ? $idp['singleLogoutService']['url'] : '',
			'idp_x509_cert' => $cert,
		);
	}
}
