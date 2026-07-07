/**
 * IdP Metadata "Auto Populate" feature: lets an administrator paste a
 * metadata URL or upload a metadata file, warns them that doing so will
 * overwrite the IdP Entity ID / SSO URL / SLO URL / Certificate fields,
 * then fetches + parses the metadata via AJAX and fills those fields in.
 *
 * Nothing is persisted here -- the admin still has to click the normal
 * "Save Changes" button to actually save the populated values.
 */

( function() {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function() {
		var btn = document.getElementById( 'edu_saml_autopopulate_btn' );
		if ( ! btn || typeof window.eduSamlIdpImporter === 'undefined' ) {
			return;
		}

		var cfg        = window.eduSamlIdpImporter;
		var urlField    = document.getElementById( 'edu_saml_metadata_url' );
		var fileField   = document.getElementById( 'edu_saml_metadata_file' );
		var statusEl    = document.getElementById( 'edu_saml_autopopulate_status' );

		function setStatus( message, isError ) {
			if ( ! statusEl ) {
				return;
			}
			statusEl.textContent = message || '';
			statusEl.classList.toggle( 'edu-saml-autopopulate-status--error', !! isError );
			statusEl.classList.toggle( 'edu-saml-autopopulate-status--success', ! isError && !! message );
		}

		function setField( id, value ) {
			var el = document.getElementById( id );
			if ( el && 'string' === typeof value ) {
				el.value = value;
			}
		}

		btn.addEventListener( 'click', function() {
			var url  = urlField ? urlField.value.trim() : '';
			var file = ( fileField && fileField.files && fileField.files.length ) ? fileField.files[0] : null;

			if ( ! url && ! file ) {
				setStatus( cfg.i18n.needInput, true );
				return;
			}

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( cfg.i18n.confirm ) ) {
				return;
			}

			var formData = new FormData();
			formData.append( 'action', cfg.action );
			formData.append( 'nonce', cfg.nonce );
			if ( file ) {
				formData.append( 'metadata_file', file );
			} else {
				formData.append( 'metadata_url', url );
			}

			btn.disabled = true;
			setStatus( cfg.i18n.working, false );

			window.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			} )
				.then( function( response ) {
					return response.json();
				} )
				.then( function( json ) {
					btn.disabled = false;

					if ( ! json || ! json.success ) {
						var message = ( json && json.data && json.data.message ) ? json.data.message : cfg.i18n.genericError;
						setStatus( message, true );
						return;
					}

					var data = json.data || {};
					setField( 'idp_entity_id', data.idp_entity_id );
					setField( 'idp_sso_url', data.idp_sso_url );
					setField( 'idp_slo_url', data.idp_slo_url );
					setField( 'idp_x509_cert', data.idp_x509_cert );

					setStatus( cfg.i18n.success, false );
				} )
				.catch( function() {
					btn.disabled = false;
					setStatus( cfg.i18n.genericError, true );
				} );
		} );
	} );
} )();
