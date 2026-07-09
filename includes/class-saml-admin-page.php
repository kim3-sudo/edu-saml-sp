<?php
/**
 * Renders the plugin's settings page under Settings > SAML SP, with tabs
 * for IdP config, attribute mapping, provisioning, break-glass, and metadata.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EDU_SAML_Admin_Page {

	/** @var EDU_SAML_Admin_Page|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'SAML SP Settings', 'edu-saml-sp' ),
			__( 'SAML SP', 'edu-saml-sp' ),
			'manage_options',
			'edu-saml-sp',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_edu-saml-sp' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'edu-saml-sp-admin', EDU_SAML_SP_URL . 'assets/admin.css', array(), EDU_SAML_SP_VERSION );

		if ( '1' === EDU_SAML_Settings::instance()->get( 'unicorn_mode', '0' ) ) {
			wp_add_inline_style( 'edu-saml-sp-admin', $this->unicorn_mode_css() );
		}


		wp_enqueue_script(
			'edu-saml-sp-admin',
			EDU_SAML_SP_URL . 'assets/admin.js',
			array(),
			EDU_SAML_SP_VERSION,
			true
		);
		wp_localize_script(
			'edu-saml-sp-admin',
			'eduSamlIdpImporter',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => EDU_SAML_IdP_Metadata_Importer::ACTION,
				'nonce'   => wp_create_nonce( EDU_SAML_IdP_Metadata_Importer::NONCE_ACTION ),
				'i18n'    => array(
					'confirm'       => __( "Auto-populating will overwrite the IdP Entity ID, SSO URL, SLO URL, and Certificate fields below with values parsed from the provided metadata.\n\nAny existing values in those fields will be lost (unless left blank in the metadata). This change is not saved until you click \"Save Changes\".\n\nContinue?", 'edu-saml-sp' ),
					'needInput'     => __( 'Please enter a metadata URL or choose a metadata file to upload first.', 'edu-saml-sp' ),
					'working'       => __( 'Fetching and parsing metadata…', 'edu-saml-sp' ),
					'success'       => __( 'IdP metadata fields have been populated below. Review them, then click "Save Changes" to save.', 'edu-saml-sp' ),
					'genericError'  => __( 'Unable to auto-populate IdP metadata.', 'edu-saml-sp' ),
				),
			)
		);
	}


	/**
	 * Inline CSS for the "Unicorn Mode" easter egg: Comic Sans font and an
	 * animated rainbow text-color effect, scoped entirely to this plugin's
	 * settings page wrapper so it never leaks elsewhere in wp-admin.
	 *
	 * @return string
	 */
	private function unicorn_mode_css() {
		// NOTE: input/textarea/select values are intentionally excluded from
		// the font-family + rainbow text-fill rules below. Applying
		// `-webkit-text-fill-color: transparent` to form controls makes
		// their *value* text invisible (while still being present/saved
		// under the hood) since form controls render their value as
		// foreground text, not via background-clip. This previously made
		// it look like saved settings (IdP URLs, certs, etc.) had
		// disappeared. Form fields still get the Comic Sans font for fun,
		// just not the transparent rainbow fill.
		return "
			.edu-saml-sp-wrap, .edu-saml-sp-wrap * {
				font-family: 'Comic Sans MS', 'Comic Sans', cursive !important;
			}
			.edu-saml-sp-wrap h1,
			.edu-saml-sp-wrap h2,
			.edu-saml-sp-wrap h3,
			.edu-saml-sp-wrap p,
			.edu-saml-sp-wrap label,
			.edu-saml-sp-wrap th,
			.edu-saml-sp-wrap td,
			.edu-saml-sp-wrap li,
			.edu-saml-sp-wrap a,
			.edu-saml-sp-wrap strong,
			.edu-saml-sp-wrap span,
			.edu-saml-sp-wrap .nav-tab {
				background-image: linear-gradient(90deg, #ff0000, #ff9900, #ffee00, #33ff00, #00ffee, #3300ff, #ee00ff, #ff0000);
				background-size: 400% 100%;
				-webkit-background-clip: text;
				background-clip: text;
				-webkit-text-fill-color: transparent;
				color: transparent;
				animation: edu-saml-unicorn-rainbow 6s linear infinite;
			}
			.edu-saml-sp-wrap input,
			.edu-saml-sp-wrap textarea,
			.edu-saml-sp-wrap select,
			.edu-saml-sp-wrap button,
			.edu-saml-sp-wrap code {
				background-image: none !important;
				-webkit-background-clip: initial !important;
				background-clip: initial !important;
				-webkit-text-fill-color: initial !important;
				animation: none !important;
			}
			.edu-saml-sp-wrap input,
			.edu-saml-sp-wrap textarea,
			.edu-saml-sp-wrap select {
				color: #1d2327 !important;
			}
			@keyframes edu-saml-unicorn-rainbow {
				0% { background-position: 0% 50%; }
				100% { background-position: 400% 50%; }
			}
		";
	}

					<?php esc_html_e( 'The onelogin/php-saml library is not installed yet (run composer install in the plugin directory). Settings can still be configured below.', 'edu-saml-sp' ); ?>
				</p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $this->tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-saml-sp', 'tab' => $slug ), admin_url( 'options-general.php' ) ) ); ?>"
					   class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'metadata' === $tab ) : ?>
				<?php $this->render_metadata_tab(); ?>
			<?php elseif ( 'help' === $tab ) : ?>
				<?php $this->render_help_tab(); ?>
			<?php elseif ( 'breakglass' === $tab ) : ?>
				<?php $this->render_breakglass_tab( $opts ); ?>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'edu_saml_sp_group' );
					if ( 'idp' === $tab ) {
						$this->render_idp_tab( $opts );
					} elseif ( 'login_experience' === $tab ) {
						$this->render_login_experience_tab( $opts );
					} elseif ( 'attributes' === $tab ) {
						$this->render_attributes_tab( $opts );
					} elseif ( 'provisioning' === $tab ) {
						$this->render_provisioning_tab( $opts );
					} elseif ( 'encryption' === $tab ) {
						$this->render_encryption_tab( $opts );
					} elseif ( 'plugin_settings' === $tab ) {
						$this->render_plugin_settings_tab( $opts );
					}

					// Preserve current tab across save-and-redirect. Nested under the
					// option key so it actually reaches the sanitize() callback's
					// $input array (a top-level field name would NOT be visible there).
					echo '<input type="hidden" name="' . esc_attr( EDU_SAML_SP_OPTION_KEY ) . '[_edu_saml_tab]" value="' . esc_attr( $tab ) . '" />';

					submit_button();
					?>

				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function tabs() {
		return array(
			'idp'              => __( 'IdP Metadata', 'edu-saml-sp' ),
			'metadata'         => __( 'SP Metadata', 'edu-saml-sp' ),
			'login_experience' => __( 'Login Experience', 'edu-saml-sp' ),
			'attributes'       => __( 'Attribute Mapping', 'edu-saml-sp' ),
			'provisioning'     => __( 'Provisioning', 'edu-saml-sp' ),
			'encryption'       => __( 'Assertion Encryption', 'edu-saml-sp' ),
			'breakglass'       => __( 'Break-Glass', 'edu-saml-sp' ),
			'plugin_settings'  => __( 'Plugin Settings', 'edu-saml-sp' ),
			'help'             => __( 'Help', 'edu-saml-sp' ),
		);
	}

	private function render_notices() {
		settings_errors( 'edu_saml_sp_group' );

		if ( isset( $_GET['edu_saml_bg_created'] ) ) {
			$creds = EDU_SAML_Breakglass::consume_pending_credentials();

			if ( $creds ) {
				echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Break-glass admin account created.', 'edu-saml-sp' ) . '</strong></p>';

				echo '<p>' . esc_html__( 'Username:', 'edu-saml-sp' ) . ' <code>' . esc_html( $creds['username'] ) . '</code></p>';
				echo '<p>' . esc_html__( 'Password (shown once — save it now):', 'edu-saml-sp' ) . ' <code>' . esc_html( $creds['password'] ) . '</code></p>';
				echo '<p>' . esc_html__( 'This password will not be shown again. Please update the account email address via Users afterward.', 'edu-saml-sp' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Break-glass account was created, but the one-time credential display already expired.', 'edu-saml-sp' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['edu_saml_bg_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['edu_saml_bg_error'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'edu-saml-sp' ) . '</p></div>';
		}
	}

	private function render_idp_tab( $opts ) {
		?>
		<h2><?php esc_html_e( 'Identity Provider Metadata', 'edu-saml-sp' ); ?></h2>
		<p><?php esc_html_e( 'The settings on this page are required. Get these settings from your IdP.', 'edu-saml-sp' ); ?></p>

		<div class="edu-saml-autopopulate-box">
			<h3><?php esc_html_e( 'Auto-Populate from IdP Metadata', 'edu-saml-sp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Paste your IdP\'s metadata URL, or upload its metadata XML file, then click "Auto Populate" to automatically fill in the Entity ID, SSO URL, SLO URL, and Certificate fields below. Nothing is saved until you review the values and click "Save Changes".', 'edu-saml-sp' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="edu_saml_metadata_url"><?php esc_html_e( 'Metadata URL', 'edu-saml-sp' ); ?></label></th>
					<td><input type="url" class="regular-text" id="edu_saml_metadata_url" placeholder="https://idp.example.edu/metadata" /></td>
				</tr>
				<tr>
					<th><label for="edu_saml_metadata_file"><?php esc_html_e( 'Or upload metadata file', 'edu-saml-sp' ); ?></label></th>
					<td><input type="file" id="edu_saml_metadata_file" accept=".xml,text/xml,application/xml,application/samlmetadata+xml" /></td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-secondary" id="edu_saml_autopopulate_btn"><?php esc_html_e( 'Auto Populate', 'edu-saml-sp' ); ?></button>
				<span id="edu_saml_autopopulate_status" class="edu-saml-autopopulate-status" role="status" aria-live="polite"></span>
			</p>
		</div>

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="idp_entity_id"><?php esc_html_e( 'IdP Entity ID', 'edu-saml-sp' ); ?></label></th>

				<td><input type="text" class="regular-text" id="idp_entity_id" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[idp_entity_id]" value="<?php echo esc_attr( $opts['idp_entity_id'] ); ?>" />
					<p class="description"><?php esc_html_e( 'The unique identifier for the Identity Provider (issuer URI), from your IdP metadata.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="idp_sso_url"><?php esc_html_e( 'IdP SSO URL (entry point)', 'edu-saml-sp' ); ?></label></th>
				<td><input type="url" class="regular-text" id="idp_sso_url" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[idp_sso_url]" value="<?php echo esc_attr( $opts['idp_sso_url'] ); ?>" required />
					<p class="description"><?php esc_html_e( 'The SingleSignOnService URL where AuthnRequests are sent.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="idp_slo_url"><?php esc_html_e( 'IdP SLO URL (optional)', 'edu-saml-sp' ); ?></label></th>
				<td><input type="url" class="regular-text" id="idp_slo_url" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[idp_slo_url]" value="<?php echo esc_attr( $opts['idp_slo_url'] ); ?>" />
					<p class="description"><?php esc_html_e( 'The SingleLogoutService URL, if your IdP supports SLO.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="idp_x509_cert"><?php esc_html_e( 'IdP x.509 Certificate (PEM)', 'edu-saml-sp' ); ?></label></th>
				<td><textarea id="idp_x509_cert" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[idp_x509_cert]" rows="10" class="large-text code" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----" required><?php echo esc_textarea( $opts['idp_x509_cert'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Paste the full PEM certificate used to verify signed assertions/responses from the IdP.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Signature Requirements', 'edu-saml-sp' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="checkbox" id="want_messages_signed" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[want_messages_signed]" value="1" <?php checked( '1', $opts['want_messages_signed'] ); ?> />
							<?php esc_html_e( 'Require Signed Response', 'edu-saml-sp' ); ?>
						</label>
						<br />
						<label>
							<input type="checkbox" id="want_assertions_signed" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[want_assertions_signed]" value="1" <?php checked( '1', $opts['want_assertions_signed'] ); ?> />
							<?php esc_html_e( 'Require Signed Assertion', 'edu-saml-sp' ); ?>
						</label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'At least one of these must be enabled — this plugin will not accept unsigned SAML responses. If you attempt to save with both unchecked, "Require Signed Assertion" will be re-enabled automatically.', 'edu-saml-sp' ); ?></p>
					<p class="description" id="edu_saml_signing_guidance" style="font-style:italic;"></p>
					<script>
					( function() {
						var msgCb  = document.getElementById( 'want_messages_signed' );
						var assCb  = document.getElementById( 'want_assertions_signed' );
						var guide  = document.getElementById( 'edu_saml_signing_guidance' );
						if ( ! msgCb || ! assCb || ! guide ) {
							return;
						}
						var text = {
							both: <?php echo wp_json_encode( __( 'IdP configuration: sign both the SAML Response and the Assertion.', 'edu-saml-sp' ) ); ?>,
							assertionOnly: <?php echo wp_json_encode( __( 'IdP configuration: sign the SAML Assertion (this is the default behavior for most Identity Providers).', 'edu-saml-sp' ) ); ?>,
							responseOnly: <?php echo wp_json_encode( __( 'IdP configuration: sign the entire SAML Response/message, not just the Assertion.', 'edu-saml-sp' ) ); ?>,
							none: <?php echo wp_json_encode( __( 'At least one signature requirement must be enabled.', 'edu-saml-sp' ) ); ?>
						};
						function update() {
							if ( msgCb.checked && assCb.checked ) {
								guide.textContent = text.both;
							} else if ( assCb.checked ) {
								guide.textContent = text.assertionOnly;
							} else if ( msgCb.checked ) {
								guide.textContent = text.responseOnly;
							} else {
								guide.textContent = text.none;
							}
						}
						msgCb.addEventListener( 'change', update );
						assCb.addEventListener( 'change', update );
						update();
					} )();
					</script>
				</td>
			</tr>
			<tr>
				<th><label for="sp_entity_id"><?php esc_html_e( 'SP Entity ID / Issuer', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="regular-text" id="sp_entity_id" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[sp_entity_id]" value="<?php echo esc_attr( $opts['sp_entity_id'] ); ?>" required />
					<p class="description"><?php esc_html_e( 'This site\'s unique SAML entity identifier. Often the site URL. Must match what you register at the IdP.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="nameid_format"><?php esc_html_e( 'NameID Format', 'edu-saml-sp' ); ?></label></th>
				<td>
					<select id="nameid_format" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[nameid_format]">
						<?php
						$formats = array(
							'emailAddress' => __( 'Email Address', 'edu-saml-sp' ),
							'persistent'   => __( 'Persistent', 'edu-saml-sp' ),
							'transient'    => __( 'Transient', 'edu-saml-sp' ),
							'unspecified'  => __( 'Unspecified', 'edu-saml-sp' ),
						);

						foreach ( $formats as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $opts['nameid_format'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The NameID is treated as an immutable identifier. Email is treated separately as a mutable attribute.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="unique_id_attribute"><?php esc_html_e( 'Unique Identifier Attribute', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="regular-text" id="unique_id_attribute" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[unique_id_attribute]" value="<?php echo esc_attr( $opts['unique_id_attribute'] ); ?>" />
					<p class="description"><?php esc_html_e( 'The SAML attribute name that carries the immutable unique identifier (e.g. email, GUID, UUID, or an Entra/Okta object identifier claim). If absent, the NameID value itself is used.', 'edu-saml-sp' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	private function render_login_experience_tab( $opts ) {
		?>
		<h2><?php esc_html_e( 'Login Experience Settings', 'edu-saml-sp' ); ?></h2>
		<p><?php esc_html_e( 'The settings on this page are optional. Use these settings to set custom branding.', 'edu-saml-sp' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="sso_button_text"><?php esc_html_e( 'SSO Button Text', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="regular-text" id="sso_button_text" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[sso_button_text]" value="<?php echo esc_attr( $opts['sso_button_text'] ); ?>" placeholder="<?php esc_attr_e( 'Sign in with your institutional account', 'edu-saml-sp' ); ?>" />
					<p class="description"><?php esc_html_e( 'Text displayed on the SSO button on the login page. Defaults to "Sign in with your institutional account" if left blank.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="sso_button_bg_color"><?php esc_html_e( 'SSO Button Background Color', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="edu-saml-color-field" id="sso_button_bg_color" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[sso_button_bg_color]" value="<?php echo esc_attr( $opts['sso_button_bg_color'] ); ?>" placeholder="#2271b1" />
					<p class="description"><?php esc_html_e( 'Hex color (e.g. #2271b1) for the SSO button background. Leave blank to use the default WordPress button style.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="sso_button_text_color"><?php esc_html_e( 'SSO Button Text Color', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="edu-saml-color-field" id="sso_button_text_color" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[sso_button_text_color]" value="<?php echo esc_attr( $opts['sso_button_text_color'] ); ?>" placeholder="#ffffff" />
					<p class="description"><?php esc_html_e( 'Hex color (e.g. #ffffff) for the SSO button label text. Leave blank to use the default.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="sso_button_hover_color"><?php esc_html_e( 'SSO Button Hover Color', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="edu-saml-color-field" id="sso_button_hover_color" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[sso_button_hover_color]" value="<?php echo esc_attr( $opts['sso_button_hover_color'] ); ?>" placeholder="#135e96" />
					<p class="description"><?php esc_html_e( 'Hex color (e.g. #135e96) for the SSO button background when hovered/focused. Leave blank to use the default.', 'edu-saml-sp' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	private function render_attributes_tab( $opts ) {
		?>
		<h2><?php esc_html_e( 'Attribute Mapping', 'edu-saml-sp' ); ?></h2>
		<p><?php esc_html_e( 'The settings on this page are required. Get these settings from your IdP.', 'edu-saml-sp' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="attr_email"><?php esc_html_e( 'Email Attribute', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="regular-text" id="attr_email" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[attr_email]" value="<?php echo esc_attr( $opts['attr_email'] ); ?>" />
					<p class="description"><?php esc_html_e( 'SAML attribute name carrying the user\'s email address. Treated as mutable — synced on every login.', 'edu-saml-sp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="attr_first_name"><?php esc_html_e( 'First Name Attribute', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="regular-text" id="attr_first_name" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[attr_first_name]" value="<?php echo esc_attr( $opts['attr_first_name'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="attr_last_name"><?php esc_html_e( 'Last Name Attribute', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="regular-text" id="attr_last_name" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[attr_last_name]" value="<?php echo esc_attr( $opts['attr_last_name'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="attr_groups"><?php esc_html_e( 'Groups Attribute', 'edu-saml-sp' ); ?></label></th>
				<td><input type="text" class="regular-text" id="attr_groups" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[attr_groups]" value="<?php echo esc_attr( $opts['attr_groups'] ); ?>" />
					<p class="description"><?php esc_html_e( 'SAML attribute name carrying group membership (e.g. groups, memberOf, or an OID such as http://schemas.xmlsoap.org/claims/Group). If you are not passing group membership in your IdP assertions, this attribute is ignored and should be left blank.', 'edu-saml-sp' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	private function render_provisioning_tab( $opts ) {
		$editable_roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		?>
		<h2><?php esc_html_e( 'Provisioning Settings', 'edu-saml-sp' ); ?></h2>
		<p><?php esc_html_e( 'Set the default provisioning settings and group to role mapping here.', 'edu-saml-sp' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Auto-Provision New Users', 'edu-saml-sp' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[auto_provision]" value="1" <?php checked( '1', $opts['auto_provision'] ); ?> />
						<?php esc_html_e( 'Automatically create a new WordPress account on first successful SAML login.', 'edu-saml-sp' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'If disabled, logins for unrecognized identities are denied with a generic error (no account details are revealed).', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Force SSO Login', 'edu-saml-sp' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[force_sso]" value="1" <?php checked( '1', $opts['force_sso'] ); ?> />
						<?php esc_html_e( 'Redirect wp-login.php to the Identity Provider for all users.', 'edu-saml-sp' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Break-glass accounts (configured on the Break-Glass tab) remain exempt and can still sign in with a WordPress username and password via the "Administrator login" link.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="default_role"><?php esc_html_e( 'Default Role', 'edu-saml-sp' ); ?></label></th>
				<td>
					<select id="default_role" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[default_role]">
						<?php foreach ( $editable_roles as $role_slug => $role_info ) : ?>
							<option value="<?php echo esc_attr( $role_slug ); ?>" <?php selected( $opts['default_role'], $role_slug ); ?>><?php echo esc_html( translate_user_role( $role_info['name'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Role assigned when no group mapping below matches the user\'s groups.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="group_role_map"><?php esc_html_e( 'Group → Role Mapping', 'edu-saml-sp' ); ?></label></th>
				<td>
					<textarea id="group_role_map" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[group_role_map]" rows="8" class="large-text code" placeholder="IT-Admins = administrator&#10;Faculty = editor&#10;Staff = author"><?php echo esc_textarea( $opts['group_role_map'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'If you haven\'t set your groups attribute in the Attribute Mapping tab, leave this blank.', 'edu-saml-sp' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'One mapping per line: Group Value = wp_role. The first matching line (top to bottom) wins; unmatched users get the default role above. Roles must be valid WordPress roles (core roles like Subscriber, Contributor, Author, Editor, Administrator, or any custom role registered by a theme/plugin). Roles are re-synced on every login.', 'edu-saml-sp' ); ?>
					</p>
					<p class="description"><strong><?php esc_html_e( 'Available roles on this site:', 'edu-saml-sp' ); ?></strong>
						<?php echo esc_html( implode( ', ', array_keys( $editable_roles ) ) ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_breakglass_tab( $opts ) {
		?>
		<div class="edu-saml-breakglass">
			<h2><?php esc_html_e( 'Break-Glass Accounts', 'edu-saml-sp' ); ?></h2>
			<p><?php esc_html_e( 'Break-glass accounts are exempt from the "Force SSO Login" redirect, so they can always sign in with a normal WordPress username and password via the "Administrator login" link on the login page — even if the IdP is unreachable or misconfigured.', 'edu-saml-sp' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'edu_saml_sp_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="breakglass_usernames"><?php esc_html_e( 'Exempt Usernames', 'edu-saml-sp' ); ?></label></th>
						<td>
							<textarea id="breakglass_usernames" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[breakglass_usernames]" rows="5" class="large-text code" placeholder="admin&#10;backup_admin"><?php echo esc_textarea( implode( "\n", (array) $opts['breakglass_usernames'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One WordPress username per line. Only existing usernames are kept after saving.', 'edu-saml-sp' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Break-Glass Usernames', 'edu-saml-sp' ) ); ?>
			</form>

			<hr />

			<h3><?php esc_html_e( 'Create a New Break-Glass Admin Account', 'edu-saml-sp' ); ?></h3>
			<p><?php esc_html_e( 'Generates a new WordPress administrator account with a strong random password and automatically adds it to the exempt list above. The password is shown exactly once — copy it immediately.', 'edu-saml-sp' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( EDU_SAML_Breakglass::ACTION_CREATE ); ?>" />
				<?php wp_nonce_field( EDU_SAML_Breakglass::NONCE_ACTION ); ?>
				<?php submit_button( __( 'Create Break-Glass Admin Account', 'edu-saml-sp' ), 'delete' ); ?>
			</form>
		</div>
		<?php
	}

	private function render_encryption_tab( $opts ) {
		?>
		<h2><?php esc_html_e( 'Assertion Encryption', 'edu-saml-sp' ); ?></h2>
		<p><?php esc_html_e( 'Not all IdPs support assertion encryption. If yours does, you can configure it here by providing a certificate.', 'edu-saml-sp' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Accept Encrypted Assertions', 'edu-saml-sp' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="want_assertions_encrypted" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[want_assertions_encrypted]" value="1" <?php checked( '1', $opts['want_assertions_encrypted'] ); ?> />
						<?php esc_html_e( 'Require/accept SAML assertions encrypted by the Identity Provider.', 'edu-saml-sp' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Not all Identity Providers support assertion encryption. If enabled, provide the SP certificate and private key below, and give the certificate to your IdP administrator so they can encrypt assertions using it.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="sp_x509_cert"><?php esc_html_e( 'SP Certificate (PEM)', 'edu-saml-sp' ); ?></label></th>
				<td>
					<textarea id="sp_x509_cert" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[sp_x509_cert]" rows="10" class="large-text code" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"><?php echo esc_textarea( $opts['sp_x509_cert'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'The public certificate (PEM format — typically a .pem, .cert, .crt, or .cer file) for this Service Provider. Give this to your IdP administrator so encrypted assertions can be sent to this site.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="sp_private_key"><?php esc_html_e( 'SP Private Key (PEM)', 'edu-saml-sp' ); ?></label></th>
				<td>
					<textarea id="sp_private_key" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[sp_private_key]" rows="10" class="large-text code" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"><?php echo esc_textarea( $opts['sp_private_key'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'The private key (PEM format) matching the certificate above. Used to decrypt assertions sent by the IdP. Keep this secret — never share it with the IdP.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="assertion_encryption_algorithm"><?php esc_html_e( 'Assertion Encryption Algorithm', 'edu-saml-sp' ); ?></label></th>
				<td>
					<select id="assertion_encryption_algorithm" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[assertion_encryption_algorithm]">
						<?php
						$assertion_algorithms = array(
							'aes256-gcm' => __( 'AES256-GCM (recommended)', 'edu-saml-sp' ),
							'aes256-cbc' => __( 'AES256-CBC', 'edu-saml-sp' ),
						);
						foreach ( $assertion_algorithms as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $opts['assertion_encryption_algorithm'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The symmetric algorithm the IdP should use to encrypt the assertion content. Communicate this to your IdP administrator.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="key_transport_algorithm"><?php esc_html_e( 'Key Transport Encryption Algorithm', 'edu-saml-sp' ); ?></label></th>
				<td>
					<select id="key_transport_algorithm" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[key_transport_algorithm]">
						<?php
						$key_transport_algorithms = array(
							'rsa-oaep-sha256' => __( 'RSA-OAEP with SHA-256 mask (recommended)', 'edu-saml-sp' ),
							'rsa-oaep-sha1'   => __( 'RSA-OAEP with SHA-1 mask', 'edu-saml-sp' ),
							'rsa-1_5'         => __( 'RSA-1.5', 'edu-saml-sp' ),
						);
						foreach ( $key_transport_algorithms as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $opts['key_transport_algorithm'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The algorithm the IdP should use to encrypt the symmetric key with this SP\'s public certificate. Communicate this to your IdP administrator.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_plugin_settings_tab( $opts ) {
		?>
		<h2><?php esc_html_e( 'Plugin Settings', 'edu-saml-sp' ); ?></h2>
		<p><?php esc_html_e( 'Configure the plugin.', 'edu-saml-sp' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="diagnostic_logging"><?php esc_html_e( 'Diagnostic Logging', 'edu-saml-sp' ); ?></label></th>
				<td>
					<select id="diagnostic_logging" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[diagnostic_logging]">
						<?php
						$logging_levels = array(
							'off'     => __( 'Off', 'edu-saml-sp' ),
							'basic'   => __( 'Basic', 'edu-saml-sp' ),
							'verbose' => __( 'Verbose', 'edu-saml-sp' ),
						);
						foreach ( $logging_levels as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $opts['diagnostic_logging'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Controls how much SAML login diagnostic information is written to the PHP error log. "Basic" logs high-level auth events and errors; "Verbose" additionally logs detailed request/response data useful for troubleshooting IdP configuration issues. Leave "Off" in normal production use. When WP_DEBUG is enabled, verbose logging is always available regardless of this setting.', 'edu-saml-sp' ); ?></p>
					<p class="description"><?php esc_html_e( 'Be careful to not expose non-public information when using verbose logging, and disable diagnostic logging when it is not needed.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Unicorn Mode', 'edu-saml-sp' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( EDU_SAML_SP_OPTION_KEY ); ?>[unicorn_mode]" value="1" <?php checked( '1', $opts['unicorn_mode'] ); ?> />
						<?php esc_html_e( 'Enable Unicorn Mode on this settings page.', 'edu-saml-sp' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'For entertainment purposes only. Save changes to see the magic ✨🦄✨.', 'edu-saml-sp' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}


	private function render_help_tab() {
		$acs_url   = EDU_SAML_SP::get_acs_url();
		$sls_url   = EDU_SAML_SP::get_sls_url();
		$login_url = EDU_SAML_SP::get_login_url();
		$sp_entity = EDU_SAML_Settings::instance()->get( 'sp_entity_id', home_url( '/' ) );
		?>
		<div class="edu-saml-help">

			<h2><?php esc_html_e( 'Help &amp; Documentation', 'edu-saml-sp' ); ?></h2>
			<p><?php esc_html_e( 'This page explains what the plugin does, defines common SAML terminology, and walks through configuring several popular Identity Providers (IdPs). It is intended for any organization configuring single sign-on with a SAML 2.0 IdP.', 'edu-saml-sp' ); ?></p>

			<h3><?php esc_html_e( 'What this plugin does', 'edu-saml-sp' ); ?></h3>
			<p><?php esc_html_e( 'This plugin turns your WordPress site into a SAML 2.0 Service Provider (SP). Instead of (or in addition to) logging in with a local WordPress username and password, users can authenticate against your organization\'s Identity Provider (IdP) — such as Okta, Duo, Shibboleth, Microsoft Entra ID, or any other SAML 2.0-compliant IdP — and be automatically signed into WordPress. The plugin can also automatically create WordPress accounts for new users on first login (provisioning) and assign WordPress roles based on group membership asserted by the IdP.', 'edu-saml-sp' ); ?></p>

			<h3><?php esc_html_e( 'How the tabs fit together', 'edu-saml-sp' ); ?></h3>
			<ul class="edu-saml-help-list">
				<li><strong><?php esc_html_e( 'IdP Metadata', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'Information about your Identity Provider — its Entity ID, SSO/SLO URLs, and signing certificate. Usually copied from IdP metadata, or auto-populated using the metadata URL/file importer on that tab.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'SP Metadata', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'Information about this WordPress site as a Service Provider — the URLs and metadata XML you give to your IdP administrator when registering this site.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Login Experience', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'Optional branding for the SSO button shown on the WordPress login page.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Attribute Mapping', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'Tells the plugin which SAML attribute names carry the user\'s email, first name, last name, and group memberships.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Provisioning', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'Controls whether new WordPress accounts are created automatically, whether SSO is enforced for everyone, the default WordPress role, and group-to-role mapping rules.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Assertion Encryption', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'Optional support for IdPs that encrypt SAML assertions, using an SP certificate/private key pair.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Break-Glass', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'Emergency-access WordPress accounts that remain exempt from forced SSO, so administrators are never locked out if the IdP is unreachable or misconfigured.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Plugin Settings', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'General plugin behavior, including diagnostic logging for troubleshooting.', 'edu-saml-sp' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Quick-start checklist', 'edu-saml-sp' ); ?></h3>
			<ol class="edu-saml-help-list">
				<li><?php esc_html_e( 'Give your IdP administrator the values on the "SP Metadata" tab (ACS URL, Entity ID, and SP Metadata XML) to register this site as a Service Provider/application.', 'edu-saml-sp' ); ?></li>
				<li><?php esc_html_e( 'Get IdP metadata (a URL or XML file) from your IdP administrator, and use "Auto Populate" on the "IdP Metadata" tab to fill in the Entity ID, SSO URL, SLO URL, and certificate — or enter them manually.', 'edu-saml-sp' ); ?></li>
				<li><?php esc_html_e( 'Confirm the SAML attribute names on the "Attribute Mapping" tab match what your IdP actually sends (see the IdP-specific guides below).', 'edu-saml-sp' ); ?></li>
				<li><?php esc_html_e( 'Decide on auto-provisioning, default role, and any group → role mappings on the "Provisioning" tab.', 'edu-saml-sp' ); ?></li>
				<li><?php esc_html_e( 'Create at least one Break-Glass account before enabling "Force SSO Login", so you always have a way to sign in if SSO breaks.', 'edu-saml-sp' ); ?></li>
				<li><?php esc_html_e( 'Try it out! ', 'edu-saml-sp' ); ?></li>
			</ol>

			<h3><?php esc_html_e( 'SAML glossary', 'edu-saml-sp' ); ?></h3>
			<ul class="edu-saml-help-list">
				<li><strong><?php esc_html_e( 'SP (Service Provider)', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'The application relying on SSO — in this case, this WordPress site.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'IdP (Identity Provider)', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'The system that authenticates users and issues SAML assertions — e.g. Okta, Duo, Shibboleth, or Entra ID.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Entity ID', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'A unique URI identifying an SP or IdP. Must match exactly between what is registered at the IdP and what is configured here.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'ACS URL (Assertion Consumer Service)', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'The URL on this site where the IdP sends the SAML Response after a successful login.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'NameID', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'The primary subject identifier in a SAML assertion, treated by this plugin as an immutable user identifier.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Assertion', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'The signed (and optionally encrypted) XML statement issued by the IdP asserting who the user is and what attributes/groups they have.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'SSO / SLO', 'edu-saml-sp' ); ?>:</strong> <?php esc_html_e( 'Single Sign-On (logging in via the IdP) and Single Logout (logging out of both the SP and IdP session).', 'edu-saml-sp' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'This site\'s values', 'edu-saml-sp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'You will need these when configuring your IdP in the guides below.', 'edu-saml-sp' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'SP Entity ID / Audience URI', 'edu-saml-sp' ); ?></th>
					<td><code><?php echo esc_html( $sp_entity ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'ACS URL / Single Sign On URL / Reply URL', 'edu-saml-sp' ); ?></th>
					<td><code><?php echo esc_html( $acs_url ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'SLS / Single Logout URL', 'edu-saml-sp' ); ?></th>
					<td><code><?php echo esc_html( $sls_url ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'SP-Initiated Login URL', 'edu-saml-sp' ); ?></th>
					<td><code><?php echo esc_html( $login_url ); ?></code></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Identity Provider configuration guides', 'edu-saml-sp' ); ?></h3>
			<p><?php esc_html_e( 'Expand a guide below for step-by-step instructions. Field names vary between IdP product versions — use these as a starting point and consult your IdP\'s current documentation for specifics.', 'edu-saml-sp' ); ?></p>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'Okta', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<ol>
						<li><?php esc_html_e( 'In the Okta Admin Console, go to Applications → Applications → Create App Integration, and choose "SAML 2.0".', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'On the "Configure SAML" step, set:', 'edu-saml-sp' ); ?>
							<ul>
								<li><?php echo wp_kses_post( sprintf( /* translators: %s: ACS URL */ __( '<strong>Single sign-on URL</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
								<li><?php echo wp_kses_post( sprintf( /* translators: %s: SP entity ID */ __( '<strong>Audience URI (SP Entity ID)</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $sp_entity ) . '</code>' ) ); ?></li>
								<li><?php esc_html_e( '"Name ID format": Email Address (or whichever format matches the "NameID Format" chosen on the IdP Metadata tab).', 'edu-saml-sp' ); ?></li>
							</ul>
						</li>
						<li><?php esc_html_e( 'Under "Attribute Statements", add attributes for email, first name, and last name (e.g. email, firstName, lastName) mapping to Okta user profile properties.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'If you need role mapping, add a "Group Attribute Statement" (e.g. named "groups") with a filter matching the Okta groups you want to pass through, and use that same name as the Groups Attribute on the Attribute Mapping tab.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'After saving, open the application\'s "Sign On" tab and use "View Setup Instructions", or download the "Identity Provider metadata" URL/XML — paste that URL or file into the Auto-Populate box on the IdP Metadata tab.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Assign users/groups to the application in Okta so they are permitted to log in.', 'edu-saml-sp' ); ?></li>
					</ol>
				</div>
			</details>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'Duo Single Sign-On', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<p><?php esc_html_e( 'Duo Single Sign-On (Duo SSO) acts as a SAML IdP in front of an upstream directory (e.g. Active Directory, Entra ID, Google Workspace, or Duo\'s own directory), adding multi-factor authentication to the login flow.', 'edu-saml-sp' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'In the Duo Admin Panel, go to Applications → Protect an Application, and search for "Generic Service Provider" (or "Generic SAML Service Provider"), then click "Protect".', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Under "Service Provider", enter:', 'edu-saml-sp' ); ?>
							<ul>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>Entity ID</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $sp_entity ) . '</code>' ) ); ?></li>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>Assertion Consumer Service (ACS) URL</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
							</ul>
						</li>
						<li><?php esc_html_e( 'Configure Duo\'s connection to your upstream directory (Active Directory, Entra ID, etc.) if not already connected, so Duo knows about your users and their attributes/group memberships.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Under "Attributes" (sometimes called "Map Attributes"), map the upstream directory attributes to SAML attribute names your users\' email, first name, last name, and group memberships will be sent as — record these names for the Attribute Mapping tab.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Download the Duo application\'s metadata XML (or copy its metadata URL, "Single Sign-On URL", and certificate) and paste/enter them via the Auto-Populate box or manually on the IdP Metadata tab.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Assign the appropriate users/groups policy to the application so only intended users can authenticate.', 'edu-saml-sp' ); ?></li>
					</ol>
				</div>
			</details>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'Shibboleth', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<p><?php esc_html_e( 'Shibboleth is a self-hosted, open-source SAML IdP commonly used both by federations (e.g. InCommon, eduGAIN — common at universities and research institutions) and by standalone organizations (private companies, agencies) that run Shibboleth without joining a federation. Configuration differs slightly depending on which model you use.', 'edu-saml-sp' ); ?></p>

					<p><strong><?php esc_html_e( 'Option A: Federation-based deployment (e.g. InCommon, eduGAIN)', 'edu-saml-sp' ); ?></strong></p>
					<ol>
						<li><?php esc_html_e( 'Register this site as a Service Provider with your federation, providing the SP metadata XML (from the SP Metadata tab), the ACS URL, and the SP Entity ID.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Work with your Shibboleth IdP administrator to add an attribute-release policy (in attribute-filter.xml) that releases the required attributes (email, first/last name, and optionally group/affiliation data such as eduPersonAffiliation or an isMemberOf attribute) to this SP\'s Entity ID.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Once the federation publishes the updated metadata aggregate (or your IdP administrator gives you the IdP\'s metadata URL directly), use Auto-Populate on the IdP Metadata tab to import the IdP\'s Entity ID, SSO URL, and certificate.', 'edu-saml-sp' ); ?></li>
					</ol>

					<p><strong><?php esc_html_e( 'Option B: Standalone/direct deployment (no federation)', 'edu-saml-sp' ); ?></strong></p>
					<ol>
						<li><?php esc_html_e( 'Send your Shibboleth IdP administrator this site\'s SP metadata XML (from the SP Metadata tab) so they can add it directly to the IdP\'s metadata provider configuration (metadata-providers.xml), instead of relying on a federation aggregate.', 'edu-saml-sp' ); ?></li>
						<li><?php echo wp_kses_post( sprintf( __( 'Ask them for the IdP\'s own metadata URL (typically something like %s) or an exported metadata XML file, and use Auto-Populate on the IdP Metadata tab to import it.', 'edu-saml-sp' ), '<code>https://idp.example.org/idp/shibboleth</code>' ) ); ?></li>
						<li><?php esc_html_e( 'Have them configure an attribute-release policy in attribute-filter.xml scoped to this SP\'s Entity ID, releasing email, first/last name, and any group/role attribute you plan to use.', 'edu-saml-sp' ); ?></li>
					</ol>

					<p><?php esc_html_e( 'In both cases, confirm with your Shibboleth administrator which attribute IDs (not necessarily the SAML attribute names) are released — common examples include mail, givenName, sn, eduPersonPrincipalName, and eduPersonAffiliation or isMemberOf for group/role data — and enter the exact released attribute names on the Attribute Mapping tab.', 'edu-saml-sp' ); ?></p>
				</div>
			</details>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'Microsoft Entra ID (Azure AD)', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<ol>
						<li><?php esc_html_e( 'In the Microsoft Entra admin center, go to Enterprise Applications → New Application → "Create your own application", and choose "Integrate any other application you don\'t find in the gallery (Non-gallery)".', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Open the new application, go to "Single sign-on", and select "SAML".', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Under "Basic SAML Configuration", set:', 'edu-saml-sp' ); ?>
							<ul>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>Identifier (Entity ID)</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $sp_entity ) . '</code>' ) ); ?></li>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>Reply URL (Assertion Consumer Service URL)</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>Sign on URL</strong> (optional, for IdP-initiated login): %s', 'edu-saml-sp' ), '<code>' . esc_html( $login_url ) . '</code>' ) ); ?></li>
							</ul>
						</li>
						<li><?php esc_html_e( 'Under "Attributes & Claims", edit the claims to include email, first name (givenname), last name (surname), and, if using group-based roles, add a "Groups" claim (choose "Security groups" or the appropriate group type) — record the claim names for the Attribute Mapping tab. Entra often sends group claims as long GUIDs unless you configure a friendlier group claim format.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Download the "Federation Metadata XML" (or copy the "App Federation Metadata Url") from the "SAML Certificates" section, and use Auto-Populate on the IdP Metadata tab to import the Entity ID, SSO URL, and certificate.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Under "Users and groups", assign the users/groups who should be able to sign in to this application.', 'edu-saml-sp' ); ?></li>
					</ol>
				</div>
			</details>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'PingIdentity (PingOne / PingFederate)', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<p><?php esc_html_e( 'The steps below cover PingOne (Ping\'s cloud SSO service); PingFederate (self-hosted) uses the same concepts under slightly different menu names (e.g. "SP Connection" instead of "Application").', 'edu-saml-sp' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'In the PingOne Admin Console, go to Applications → Applications → Add Application → "New SAML Application" (or in PingFederate, go to SP Connections → Create New).', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Choose to configure the SAML connection manually, then set:', 'edu-saml-sp' ); ?>
							<ul>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>ACS URLs</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>Entity ID</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $sp_entity ) . '</code>' ) ); ?></li>
								<li><?php esc_html_e( '"Signing" and "Assertion validity" can be left at defaults unless your organization\'s security policy specifies otherwise.', 'edu-saml-sp' ); ?></li>
							</ul>
						</li>
						<li><?php esc_html_e( 'Under "Attribute Mapping", map PingOne user directory attributes to outgoing SAML attribute names for email, first name, and last name — and, if needed, a group/role attribute sourced from the user\'s group memberships. Record the exact attribute names for the Attribute Mapping tab.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'On the application\'s "Configuration" tab, download the "IdP Metadata" file (or copy the metadata URL/Issuer, SSO URL, and signing certificate), then use Auto-Populate on the IdP Metadata tab to import them.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Enable the application and assign the appropriate users/groups so they can access it.', 'edu-saml-sp' ); ?></li>
					</ol>
				</div>
			</details>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'OneLogin', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<ol>
						<li><?php esc_html_e( 'In the OneLogin Admin portal, go to Applications → Applications → Add App, and search for "SAML Custom Connector" (choose the "SAML Test Connector (Advanced)" or a similar generic SAML connector).', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'On the "Configuration" tab, set:', 'edu-saml-sp' ); ?>
							<ul>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>Audience (Entity ID)</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $sp_entity ) . '</code>' ) ); ?></li>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>ACS (Consumer) URL</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>ACS (Consumer) URL Validator</strong>: a regex matching %s (e.g. escape the URL and anchor with ^ and $)', 'edu-saml-sp' ), '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
							</ul>
						</li>
						<li><?php esc_html_e( 'On the "Parameters" tab, add parameters mapping OneLogin user fields to outgoing SAML attribute names for email, first name, and last name, and (optionally) a "Groups"/"Roles" parameter including the user\'s OneLogin roles or group memberships — include it "in SAML assertion".', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'On the "SSO" tab, copy the "Issuer URL" (or download the "SAML Metadata" file) and the X.509 certificate, then use Auto-Populate on the IdP Metadata tab (or enter the values manually) to import them.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'On the "Access" tab, assign the roles that should be allowed to use this application.', 'edu-saml-sp' ); ?></li>
					</ol>
				</div>
			</details>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'Google Workspace', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<ol>
						<li><?php esc_html_e( 'In the Google Admin console, go to Apps → Web and mobile apps → Add app → "Add custom SAML app".', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Give the app a name (e.g. this site\'s name), then on the "Google Identity Provider details" screen, download the "IdP metadata" file (or copy the SSO URL, Entity ID, and certificate) — you\'ll import these on the IdP Metadata tab in a later step.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'On the "Service provider details" screen, set:', 'edu-saml-sp' ); ?>
							<ul>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>ACS URL</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>Entity ID</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $sp_entity ) . '</code>' ) ); ?></li>
								<li><?php esc_html_e( 'Name ID format: EMAIL, and Name ID: Basic Information &gt; Primary email (or whichever format matches the "NameID Format" chosen on the IdP Metadata tab).', 'edu-saml-sp' ); ?></li>
							</ul>
						</li>
						<li><?php esc_html_e( 'On the "Attribute mapping" screen, map Google directory attributes (First name, Last name, Primary email, and optionally an Organizational Unit or Group field) to the outgoing SAML attribute names you plan to use — record these names for the Attribute Mapping tab. Google Workspace does not send group membership by default; if you need group-based roles, add a custom attribute or use Google Groups with an additional attribute mapping app setting, or manage role mapping via Organizational Units mapped to a custom attribute.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Finish creating the app, then turn its SSO status ON for the appropriate Organizational Units/Groups so those users can authenticate.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Use Auto-Populate on the IdP Metadata tab with the metadata file/URL from step 2 to import the IdP Entity ID, SSO URL, and certificate.', 'edu-saml-sp' ); ?></li>
					</ol>
				</div>
			</details>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'Active Directory Federation Services (ADFS)', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<ol>
						<li><?php esc_html_e( 'In the AD FS Management console, right-click "Relying Party Trusts" and choose "Add Relying Party Trust", then select "Claims aware" and "Enter data about the relying party manually".', 'edu-saml-sp' ); ?></li>
						<li><?php echo wp_kses_post( sprintf( __( 'Set the <strong>Relying party identifier</strong> to %s.', 'edu-saml-sp' ), '<code>' . esc_html( $sp_entity ) . '</code>' ) ); ?></li>
						<li><?php esc_html_e( 'Configure the SAML 2.0 SSO endpoint:', 'edu-saml-sp' ); ?>
							<ul>
								<li><?php echo wp_kses_post( sprintf( __( '<strong>SAML 2.0 WebSSO protocol URL (ACS URL)</strong>: %s', 'edu-saml-sp' ), '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
							</ul>
						</li>
						<li><?php esc_html_e( 'Finish the wizard, then right-click the new relying party trust and choose "Edit Claim Issuance Policy" to add claim rules ("Send LDAP Attributes as Claims") mapping Active Directory attributes (E-Mail-Addresses, Given-Name, Surname, and Token-Groups or a custom group attribute) to outgoing claim types — record the exact outgoing claim type URIs/names for the Attribute Mapping tab.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Ensure the relying party trust\'s NameID claim rule matches the "NameID Format" chosen on the IdP Metadata tab (commonly Email Address, via a "Transform an Incoming Claim" rule from E-Mail-Address to Name ID).', 'edu-saml-sp' ); ?></li>
						<li><?php echo wp_kses_post( sprintf( __( 'Retrieve the AD FS federation metadata (typically at a URL such as %s) or export the token-signing certificate, then use Auto-Populate on the IdP Metadata tab to import the Entity ID, SSO URL, and certificate.', 'edu-saml-sp' ), '<code>https://adfs.example.com/federationmetadata/2007-06/federationmetadata.xml</code>' ) ); ?></li>
						<li><?php esc_html_e( 'Adjust the relying party trust\'s Access Control Policy to permit the appropriate users/groups.', 'edu-saml-sp' ); ?></li>
					</ol>
				</div>
			</details>

			<details class="edu-saml-help-idp">
				<summary><?php esc_html_e( 'Other / Generic SAML 2.0 IdPs', 'edu-saml-sp' ); ?></summary>
				<div class="edu-saml-help-idp-body">
					<p><?php esc_html_e( 'This plugin works with any standards-compliant SAML 2.0 Identity Provider — including those not covered by a dedicated guide above, such as JumpCloud, Keycloak, Auth0, or a custom-built IdP. Regardless of the specific product, you will generally need to:', 'edu-saml-sp' ); ?></p>
					<ol>
						<li><?php echo wp_kses_post( sprintf( __( 'Register this site as an SP/application using the SP Entity ID (%s) and ACS URL (%s) from the SP Metadata tab.', 'edu-saml-sp' ), '<code>' . esc_html( $sp_entity ) . '</code>', '<code>' . esc_html( $acs_url ) . '</code>' ) ); ?></li>
						<li><?php esc_html_e( 'Configure which user attributes are sent in the assertion (email, first name, last name, and optionally group/role membership), and note the exact attribute names used.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Obtain the IdP\'s metadata (a URL or XML file) and import it using Auto-Populate on the IdP Metadata tab, or manually copy the IdP Entity ID, SSO URL, SLO URL, and signing certificate.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Enter the attribute names from step 2 on the Attribute Mapping tab.', 'edu-saml-sp' ); ?></li>
						<li><?php esc_html_e( 'Assign the appropriate users/groups access to the application at the IdP.', 'edu-saml-sp' ); ?></li>
					</ol>
				</div>
			</details>

			<h3><?php esc_html_e( 'Troubleshooting', 'edu-saml-sp' ); ?></h3>
			<ul class="edu-saml-help-list">
				<li><strong><?php esc_html_e( 'Signature validation errors:', 'edu-saml-sp' ); ?></strong> <?php esc_html_e( 'Confirm the certificate on the IdP Metadata tab exactly matches the current signing certificate at the IdP — certificates are periodically rotated and must be updated here when that happens.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Clock skew errors:', 'edu-saml-sp' ); ?></strong> <?php esc_html_e( 'SAML assertions have a limited validity window. Ensure the server\'s system clock is accurate (NTP-synced), as even a few minutes of drift can cause "not yet valid" or "expired" errors.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Users can log in but roles/attributes look wrong:', 'edu-saml-sp' ); ?></strong> <?php esc_html_e( 'Double-check that the attribute names on the Attribute Mapping tab exactly match (including case) what the IdP actually sends — a mismatch silently results in a blank value rather than an error.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Entity ID mismatch errors:', 'edu-saml-sp' ); ?></strong> <?php esc_html_e( 'The SP Entity ID configured here must exactly match (including trailing slashes and case) the Entity ID/Audience/Identifier registered at the IdP.', 'edu-saml-sp' ); ?></li>
				<li><strong><?php esc_html_e( 'Need more detail?', 'edu-saml-sp' ); ?></strong> <?php esc_html_e( 'Temporarily enable Diagnostic Logging (Basic or Verbose) on the Plugin Settings tab, reproduce the failed login, then check your PHP error log for details. Remember to disable it again afterward.', 'edu-saml-sp' ); ?></li>
			</ul>

		</div>
		<?php
	}

	private function render_metadata_tab() {

		?>

		<h2><?php esc_html_e( 'Service Provider Metadata', 'edu-saml-sp' ); ?></h2>
		<p><?php esc_html_e( 'Provide these values to your IdP administrator when registering this site as a SAML Service Provider.', 'edu-saml-sp' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'ACS (Assertion Consumer Service) URL', 'edu-saml-sp' ); ?></th>
				<td><code><?php echo esc_html( EDU_SAML_SP::get_acs_url() ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'SLS (Single Logout Service) URL', 'edu-saml-sp' ); ?></th>
				<td><code><?php echo esc_html( EDU_SAML_SP::get_sls_url() ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'SP-Initiated Login URL', 'edu-saml-sp' ); ?></th>
				<td><code><?php echo esc_html( EDU_SAML_SP::get_login_url() ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'SP Metadata XML', 'edu-saml-sp' ); ?></th>
				<td><a class="button" href="<?php echo esc_url( EDU_SAML_SP::get_metadata_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View SP Metadata XML', 'edu-saml-sp' ); ?></a></td>
			</tr>
		</table>
		<?php
	}
}
