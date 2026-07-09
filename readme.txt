=== EDU SAML SP ===
Contributors: sejinkim
Tags: saml, sso, single sign-on, saml2, authentication
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your WordPress site into a SAML2 Service Provider (SP), authenticating users against any SAML2-compliant Identity Provider (IdP).

== Description ==

This plugin uses the [onelogin/php-saml](https://github.com/SAML-Toolkits/php-saml) library for all XML parsing, signature verification, and SAML protocol handling.

= Features =

* SP-initiated SSO login (`admin-post.php?action=edu_saml_login`)
* Assertion Consumer Service (ACS) endpoint for processing IdP responses
* SP metadata XML endpoint for easy IdP-side registration
* Configurable NameID format (Email Address, Persistent, Transient, Unspecified)
* Configurable unique-identifier attribute (email, eduPersonPrincipalName, uid, GUID, Entra/Okta object identifier, etc.) — treated as **immutable** and used to match users on every login
* Configurable attribute mapping for email (mutable, synced every login), first name, last name, and groups
* JIT (just-in-time) auto-provisioning toggle
* Group → WordPress role mapping (supports core roles and any custom role registered by a theme/plugin), re-synced on every login
* "Force SSO" mode that redirects `wp-login.php` to the IdP
* Break-glass accounts: designate specific WordPress usernames as exempt from forced SSO, plus a one-click button to generate a new break-glass administrator account with a random password (shown once)

== Installation ==

1. Copy the `edu-saml-sp` directory into `wp-content/plugins/`.
2. Install the SAML library dependency via Composer, from inside the plugin directory:

`cd wp-content/plugins/edu-saml-sp && composer install`

This creates a `vendor/` directory containing `onelogin/php-saml` and its own dependencies (`robrichards/xmlseclibs`, etc.). The plugin will refuse to process SAML logins (and will show an admin notice) until this step is completed.

3. Activate **EDU SAML SP** from the WordPress admin **Plugins** screen.
4. Go to **Settings → SAML SP** to configure the plugin (see below).

== Configuration ==

= 1. Identity Provider tab =

* IdP Entity ID — The IdP's issuer URI, from its metadata.
* IdP SSO URL (entry point) — The `SingleSignOnService` URL where AuthnRequests are sent.
* IdP SLO URL — Optional `SingleLogoutService` URL.
* IdP x.509 Certificate (PEM) — The full PEM-formatted certificate used to verify signed responses/assertions.
* SP Entity ID / Issuer — This site's unique SAML entity ID — often the site URL. Must match what's registered at the IdP.
* NameID Format — EmailAddress, Persistent, Transient, or Unspecified. The NameID is always treated as the immutable identifier.
* Unique Identifier Attribute — The SAML attribute name carrying an immutable unique ID (e.g. `email`, `eduPersonPrincipalName`, `uid`, or an Entra/Okta object identifier claim). Falls back to the NameID value if this attribute is absent from the assertion.

= 2. Attribute Mapping tab =

Map the SAML attribute *names* your IdP sends for:

* Email (mutable — updated on the user's WP account on every login)
* First Name
* Last Name
* Groups (e.g. `groups`, `memberOf`, or an OID such as `http://schemas.xmlsoap.org/claims/Group`)

= 3. Provisioning tab =

* Auto-Provision New Users — when ON, a new WordPress account is created automatically the first time a recognized IdP identity signs in (JIT provisioning). When OFF, unrecognized identities are denied with a generic error and no account is created.
* Force SSO Login — when ON, `wp-login.php` redirects everyone to the IdP, except break-glass accounts.
* Default Role — the WordPress role assigned when no group mapping (below) matches.
* Group → Role Mapping — one mapping per line: `Group Value = wp_role`. The first matching line (top to bottom) wins. Works with any core role (Subscriber, Contributor, Author, Editor, Administrator) or custom role registered by a theme/plugin. Role is **re-evaluated and re-applied on every login**, so manual role changes made in wp-admin will be overwritten on the user's next SSO login.

= 4. Break-Glass tab =

* Exempt Usernames — a list of existing WordPress usernames that are exempt from the Force SSO redirect. These accounts can always reach the normal WordPress username/password login form via the "Administrator login" link shown at the bottom of the login page when Force SSO is enabled. (This link only bypasses the *redirect*; the account still needs a valid password to actually log in.)
* Create Break-Glass Admin Account button — generates a brand-new WordPress `administrator` account with a strong random password, adds it to the exempt list automatically, and displays the one-time username/password in an admin notice. **This password is shown exactly once and is never stored** — copy it immediately. Afterward, update the account's email address via **Users** in wp-admin.

= 5. SP Metadata tab =

Displays the URLs and metadata XML you'll need to give to your IdP administrator to register this site as a Service Provider:

* ACS URL
* SLS (logout) URL
* SP-initiated login URL
* Full SP metadata XML document

== How user matching works ==

On every successful SAML login:

1. The plugin extracts the NameID and the configured "unique identifier" attribute from the assertion.
2. It computes an HMAC-SHA256 hash of that identifier (keyed to your site's secret keys) and looks for an existing WordPress user with a matching stored hash (`_edu_saml_unique_id_hash` user meta).
3. **If found:** mutable fields (email, first/last name) are updated, the group→role mapping is re-evaluated, and the user is logged in.
4. **If not found and auto-provisioning is ON:** a new WordPress account is created, linked via the hashed identifier, and the user is logged in.
5. **If not found and auto-provisioning is OFF:** login is denied with a generic error message. No information about whether an account exists is ever revealed to the browser — detailed reasons are only logged server-side when diagnostic logging is enabled.

Email is intentionally **not** used for matching (since email is mutable and could be changed at the IdP), only for creating/updating the WP account's contact info.

== Frequently Asked Questions ==

= Does this plugin implement its own cryptography or XML parsing? =

No. All signature verification and response/assertion validation (expiry, audience restriction, destination checks) is delegated to the `onelogin/php-saml` library.

= How is plugin data stored? =

The plugin option (`edu_saml_sp_options`) is stored as a single, non-autoloaded WordPress option. Settings changes require the `manage_options` capability and are protected by WordPress's standard Settings API nonces. Break-glass account creation is nonce-protected and requires `manage_options`.

= What happens on uninstall? =

Deactivating/deleting the plugin does not remove any WordPress user accounts it created. To fully remove plugin data, delete the `edu_saml_sp_options` option and any `_edu_saml_*` user meta manually if desired.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
