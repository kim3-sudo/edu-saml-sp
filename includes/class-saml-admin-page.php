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
	}

	private function current_tab() {
		$tabs = array( 'idp', 'login_experience', 'attributes', 'provisioning', 'breakglass', 'plugin_settings', 'metadata' );
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'idp';
		return in_array( $tab, $tabs, true ) ? $tab : 'idp';
	}


	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = EDU_SAML_Settings::instance();
		$opts     = $settings->get_options();
		$tab      = $this->current_tab();

		$this->render_notices();
		?>
		<div class="wrap edu-saml-sp-wrap">
			<h1><?php esc_html_e( 'SAML SP Settings', 'edu-saml-sp' ); ?></h1>

			<?php if ( edu_saml_sp_library_missing() ) : ?>
				<div class="notice notice-warning"><p>
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
			'breakglass'       => __( 'Break-Glass', 'edu-saml-sp' ),
			'plugin_settings'  => __( 'Plugin Settings', 'edu-saml-sp' ),
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
					<p class="description"><?php esc_html_e( 'The SAML attribute name that carries the immutable unique identifier (e.g. email, eduPersonPrincipalName, uid, or an Entra/Okta object identifier claim). If absent, the NameID value itself is used.', 'edu-saml-sp' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	private function render_login_experience_tab( $opts ) {
		?>
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
					<p class="description"><?php esc_html_e( 'SAML attribute name carrying group membership (e.g. groups, memberOf, or an OID such as http://schemas.xmlsoap.org/claims/Group).', 'edu-saml-sp' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	private function render_provisioning_tab( $opts ) {
		$editable_roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		?>
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

	private function render_metadata_tab() {
		?>
		<h2><?php esc_html_e( 'Service Provider Metadata', 'edu-saml-sp' ); ?></h2>
		<p><?php esc_html_e( 'Provide these values to your Identity Provider administrator when registering this site as a SAML Service Provider.', 'edu-saml-sp' ); ?></p>
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
