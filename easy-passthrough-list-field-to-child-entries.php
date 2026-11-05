<?php
/**
 * Gravity Perks // Easy Passthrough + Nested Forms // Populate Child Entries from List Field
 * https://gravitywiz.com/documentation/gravity-forms-easy-passthrough/
 *
 * Populate a Nested Form field with child entries created from the rows of a List field on a previous entry. The source
 * entry is identified by an Easy Passthrough token in the URL. Each List row becomes its own child entry, with each
 * mapped List column populating a field on the child form. Child form fields that are not mapped are left blank.
 *
 * This is useful when migrating from a form that collected repeating data in a List field to a new form that collects
 * the same data via a Nested Form field (e.g. carrying last year's application data into this year's application).
 *
 * The source entry is never modified. Forms loaded without an Easy Passthrough token are unaffected.
 *
 * Requirements:
 *  * GP Easy Passthrough and GP Nested Forms (with sessions enabled).
 *  * The source List field must have "Enable multiple columns" checked.
 *  * Mapped child form fields must be Single Line Text, Number, or Hidden fields.
 *
 * Instructions:
 *
 * 1. Install the snippet. See "Where do I put snippets?" in our documentation for installation instructions.
 *
 * 2. Configure the snippet based on inline instructions.
 *
 * 3. To populate multiple Nested Form fields (on the same form or different forms), add a separate configuration
 *    block for each List field => Nested Form field pairing.
 */
class GPEP_List_Field_To_Child_Entries {

	private $args;

	private static $is_cleanup_registered = false;

	public function __construct( $args ) {
		$this->args = $args;
		add_filter( 'gform_register_init_scripts_' . $args['target_form_id'], array( $this, 'register_init_script' ), 20 );
		add_action( 'wp_ajax_gpep_list_to_child_entries', array( $this, 'ajax_populate_child_entries' ) );
		add_action( 'wp_ajax_nopriv_gpep_list_to_child_entries', array( $this, 'ajax_populate_child_entries' ) );
		// The cleanup is shared by all instances, so only register it once.
		if ( ! self::$is_cleanup_registered ) {
			add_action( 'gpep_ltce_import_marker_cleanup', array( $this, 'cleanup_import_marker' ) );
			self::$is_cleanup_registered = true;
		}
	}

	/**
	 * Identifies this configuration so multiple instances can run on the same site, or the same form, independently.
	 */
	public function get_instance_hash() {
		return wp_hash( wp_json_encode( $this->args ) );
	}

	private function save_import_marker( $key, $state ) {
		$state['expires'] = time() + 8 * DAY_IN_SECONDS;
		// A non-autoloaded option survives object-cache eviction (unlike a transient).
		update_option( $key, $state, false );
		if ( ! wp_next_scheduled( 'gpep_ltce_import_marker_cleanup', array( $key ) ) ) {
			wp_schedule_single_event( $state['expires'], 'gpep_ltce_import_marker_cleanup', array( $key ) );
		}
	}

	public function cleanup_import_marker( $key ) {
		if ( strpos( $key, 'gpep_ltce_' ) !== 0 ) {
			return;
		}
		$state = get_option( $key );
		if ( $state && $state['expires'] > time() ) {
			wp_schedule_single_event( $state['expires'], 'gpep_ltce_import_marker_cleanup', array( $key ) );
		} else {
			delete_option( $key );
		}
	}

	private function is_configured() {
		return $this->args['source_form_id'] && $this->args['target_form_id'] && $this->args['nested_field_id']
			&& function_exists( 'gp_easy_passthrough' ) && function_exists( 'gp_nested_forms' ) && class_exists( 'GPNF_Session' );
	}

	public function register_init_script( $form ) {
		// Do not populate a posted form, a Save & Continue draft, or a child form editing request.
		if ( ! $this->is_configured() || rgpost( 'gform_submit' ) || rgget( 'gf_token' ) || rgpost( 'gpnf_parent_form_id' ) ) {
			return;
		}
		$param = apply_filters( 'gpep_token_param_name', 'ep_token' );
		$token = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
		if ( ! $token ) {
			return; // Forms loaded without an Easy Passthrough token are unaffected.
		}
		$config = array(
			'formId'  => (int) $form['id'],
			'fieldId' => (int) $this->args['nested_field_id'],
			'url'     => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'gpep_list_to_child_entries_' . $form['id'] ),
			'param'   => $param,
			'hash'    => $this->get_instance_hash(),
		);
		// Read the bearer token from the current URL in JS, never print it into cached HTML.
		$script = <<<'JS'
(function ($, config) {
	var form = document.getElementById('gform_' + config.formId);
	var boundKey = 'childEntriesImportBound' + config.fieldId;
	if (!form || form.dataset[boundKey]) { return; }
	form.dataset[boundKey] = '1';
	var token = new URL(window.location.href).searchParams.get(config.param);
	if (!token) { return; }
	var busy = true, started = false;
	var field = document.getElementById('field_' + config.formId + '_' + config.fieldId);
	if (!field) { return; }
	var message = document.createElement('p');
	message.setAttribute('role', 'status');
	message.textContent = 'Loading your previous entries…';
	field.prepend(message);
	// Block parent submission and child entry changes until the import has finished.
	form.addEventListener('submit', function (event) {
		if (busy) { event.preventDefault(); event.stopImmediatePropagation(); message.scrollIntoView({block: 'center'}); }
	}, true);
	form.addEventListener('click', function (event) {
		if (busy && event.target.closest('.gform_next_button, .gform_button, .gform_save_link, .gpnf-add-entry, .gpnf-row-actions')) {
			event.preventDefault(); event.stopImmediatePropagation();
		}
	}, true);
	function showError(text) {
		message.textContent = text + ' Please reload this page to retry before continuing.';
		message.setAttribute('role', 'alert');
	}
	function importChildEntries() {
		if (started) { return; }
		started = true;
		$.ajax({
			url: config.url, method: 'POST', timeout: 30000,
			data: {
				action: 'gpep_list_to_child_entries', instance: config.hash, nonce: config.nonce, token: token,
				gpnf_context: window['GPNestedForms_' + config.formId + '_' + config.fieldId].ajaxContext
			}
		}).done(function (response) {
			if (!response || !response.success) {
				showError(response && response.data && response.data.message ? response.data.message : 'Previous entries could not be loaded.');
				return;
			}
			var input = document.getElementById('input_' + config.formId + '_' + config.fieldId);
			var existing = input && input.value ? input.value.split(',') : [];
			response.data.entries.forEach(function (entry) {
				if (existing.indexOf(String(entry.entryId)) === -1) { GPNestedForms.loadEntry(entry); }
			});
			busy = false;
			message.textContent = response.data.entries.length ? 'Previous entries loaded. Please review each entry before continuing.' : '';
		}).fail(function (xhr) {
			var data = xhr.responseJSON && xhr.responseJSON.data;
			showError(data && data.message ? data.message : 'Previous entries could not be loaded.');
		});
	}
	// Nested Forms initializes its cookie asynchronously. Wait for it before creating orphaned child entries.
	function waitForSession() {
		var instance = window['GPNestedForms_' + config.formId + '_' + config.fieldId];
		// Start even if the Nested Form field is conditionally hidden or on a later page.
		var promise = window['gpnfSessionPromise_' + config.formId] || (instance && instance.initSession());
		if (promise) { promise.done(importChildEntries).fail(function () { showError('The Nested Forms session could not be started.'); }); }
	}
	gform.addAction('gpnf_session_initialized', function (instance) {
		if (Number(instance.formId) === config.formId) { waitForSession(); }
	});
	waitForSession();
})(jQuery,
JS;
		$script .= ' ' . wp_json_encode( $config ) . ');';
		GFFormDisplay::add_init_script( $form['id'], 'gpep_list_to_child_entries_' . $this->get_instance_hash(), GFFormDisplay::ON_PAGE_RENDER, $script );
	}

	public function ajax_populate_child_entries() {
		// Every instance listens for this action. Only handle requests from this instance's configuration.
		if ( rgpost( 'instance' ) !== $this->get_instance_hash() ) {
			return;
		}
		if ( ! $this->is_configured() ) {
			wp_send_json_error( array( 'message' => 'The child entry importer is not configured.' ), 400 );
		}
		check_ajax_referer( 'gpep_list_to_child_entries_' . $this->args['target_form_id'], 'nonce' );
		$token  = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$source = $token ? gp_easy_passthrough()->get_entry_for_token( $token ) : false;
		if ( ! $source || is_wp_error( $source ) || (int) $source['form_id'] !== (int) $this->args['source_form_id'] || rgar( $source, 'status' ) !== 'active' ) {
			wp_send_json_error( array( 'message' => 'This link is not valid for the previous entry.' ), 403 );
		}
		$session = new GPNF_Session( $this->args['target_form_id'] );
		$hash    = $session->get( 'hash' );
		if ( ! is_string( $hash ) || ! $hash || is_numeric( $hash ) ) {
			wp_send_json_error( array( 'message' => 'The Nested Forms session is unavailable. Enable cookies and try again.' ), 400 );
		}
		// One import per browser draft/field, not one import per source entry. No source metadata is written.
		$key = 'gpep_ltce_' . hash_hmac( 'sha256', $this->args['target_form_id'] . ':' . $this->args['nested_field_id'] . ':' . $hash, wp_salt() );
		// MySQL advisory lock is connection-owned and releases automatically if PHP terminates.
		global $wpdb;
		$lock = substr( $key, 0, 64 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			wp_send_json_error( array( 'message' => 'Another request is already loading these entries.' ), 409 );
		}
		$result = null;
		try {
			$result = $this->create_child_entries( $source, $session, $key );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'entries' => $result ) );
	}

	private function create_child_entries( $source, $session, $key ) {
		$field      = GFAPI::get_field( $this->args['target_form_id'], $this->args['nested_field_id'] );
		$child_form = $field && $field->type === 'form' ? GFAPI::get_form( $field->gpnfForm ) : false;
		if ( ! $child_form || ! rgar( $child_form, 'is_active' ) || rgar( $child_form, 'is_trash' ) ) {
			return new WP_Error( 'config', 'Check the Nested Form field and its child form configuration.' );
		}
		$state = get_option( $key );
		if ( $state ) {
			if ( (int) $state['source'] !== (int) $source['id'] ) {
				return new WP_Error( 'different_source', 'This browser already has a different import in progress. Finish it or use a separate browser profile.' );
			}
			// Reuse current entries, including edits. Deleted/trashed/submitted entries are not recreated.
			$this->save_import_marker( $key, $state );
			return $this->prepare_child_entries_for_display( $state['ids'], $session, $child_form );
		}
		$existing = $session->get( 'nested_entries' );
		if ( ! empty( $existing[ $field->id ] ) ) {
			// Never merge into or overwrite an existing draft's child entries.
			$this->save_import_marker( $key, array( 'source' => $source['id'], 'ids' => array() ) );
			return array();
		}
		$list = GFAPI::get_field( $this->args['source_form_id'], $this->args['list_field_id'] );
		if ( ! $list || $list->type !== 'list' || ! $list->enableColumns ) {
			return new WP_Error( 'list', 'Check the source List field configuration.' );
		}
		foreach ( $this->args['column_map'] as $column => $child_field_id ) {
			$child_field = GFFormsModel::get_field( $child_form, $child_field_id );
			if ( ! isset( $list->choices[ $column ] ) || ! $child_field || ! in_array( $child_field->get_input_type(), array( 'text', 'number', 'hidden' ), true ) ) {
				return new WP_Error( 'map', 'Map each List column to a Single Line Text, Number, or Hidden field in the child form.' );
			}
		}
		$raw  = rgar( $source, $list->id );
		$rows = $raw === '' || $raw === null ? array() : GFCommon::maybe_unserialize( $raw );
		if ( ! is_array( $rows ) || count( $rows ) > $this->args['max_rows'] ) {
			return new WP_Error( 'rows', 'The source List field value is not in the expected format or exceeds the import limit.' );
		}
		$prepared = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== count( $list->choices ) ) {
				return new WP_Error( 'columns', 'The source List field rows do not match the configured List field columns.' );
			}
			$cells = array_values( $row ); // GF stores List rows keyed by column label, in column order.
			$entry = array( 'form_id' => $child_form['id'], 'created_by' => get_current_user_id(), 'source_url' => '' );
			foreach ( $this->args['column_map'] as $column => $child_field_id ) {
				if ( ! is_scalar( $cells[ $column ] ) && $cells[ $column ] !== null ) {
					return new WP_Error( 'cell', 'An unsupported value was found in the source List field.' );
				}
				$entry[ (string) $child_field_id ] = trim( (string) $cells[ $column ] );
			}
			// Preserve a literal zero; skip only completely blank mapped rows.
			$values = array_intersect_key( $entry, array_flip( array_map( 'strval', array_values( $this->args['column_map'] ) ) ) );
			if ( count( array_filter( $values, 'strlen' ) ) ) {
				$prepared[] = $entry;
			}
		}
		if ( $field->gpnfEntryLimitMax && count( $prepared ) > (int) $field->gpnfEntryLimitMax ) {
			return new WP_Error( 'limit', 'The source List field has more rows than this Nested Form field\'s entry limit allows.' );
		}
		$ids = array();
		foreach ( $prepared as $entry ) {
			$id = GFAPI::add_entry( $entry );
			if ( is_wp_error( $id ) ) {
				foreach ( $ids as $created_id ) {
					GFAPI::delete_entry( $created_id );
				}
				return new WP_Error( 'create', 'The child entries could not be created. No partial import was retained.' );
			}
			$ids[] = $id;
			$child = new GPNF_Entry( $id );
			$child->set_parent_meta( $this->args['target_form_id'], $session->get( 'hash' ) );
			$child->set_nested_form_field( $field->id );
			$child->set_expiration();
		}
		$this->save_import_marker( $key, array( 'source' => $source['id'], 'ids' => $ids ) );
		return $this->prepare_child_entries_for_display( $ids, $session, $child_form );
	}

	private function prepare_child_entries_for_display( $ids, $session, $child_form ) {
		$result      = array();
		$current     = $session->get( 'nested_entries' );
		$current_ids = isset( $current[ $this->args['nested_field_id'] ] ) ? $current[ $this->args['nested_field_id'] ] : array();
		foreach ( $ids as $id ) {
			$entry = GFAPI::get_entry( $id );
			if ( is_wp_error( $entry ) || $entry['status'] !== 'active'
				|| (int) $entry['form_id'] !== (int) $child_form['id']
				|| (string) gform_get_meta( $id, GPNF_Entry::ENTRY_PARENT_KEY ) !== $session->get( 'hash' )
				|| (int) gform_get_meta( $id, GPNF_Entry::ENTRY_NESTED_FORM_FIELD_KEY ) !== (int) $this->args['nested_field_id'] ) {
				continue;
			}
			if ( ! in_array( (int) $id, array_map( 'intval', $current_ids ), true ) ) {
				$session->add_child_entry( $id );
			}
			$result[] = array(
				'formId'      => (int) $this->args['target_form_id'],
				'fieldId'     => (int) $this->args['nested_field_id'],
				'entryId'     => (int) $id,
				'entry'       => $entry,
				'fieldValues' => gp_nested_forms()->get_entry_display_values( $entry, $child_form ),
				'mode'        => 'add',
			);
		}
		return $result;
	}

}

# Configuration

new GPEP_List_Field_To_Child_Entries( array(
	'source_form_id'  => 128, // Update "128" to the ID of the source form (the form with the List field).
	'target_form_id'  => 130, // Update "130" to the ID of the target form (the parent form with the Nested Form field).
	'list_field_id'   => 1,   // Update "1" to the ID of the List field on the source form.
	'nested_field_id' => 1,   // Update "1" to the ID of the Nested Form field on the target form.
	'max_rows'        => 10,  // Maximum number of List rows that will be imported.
	'column_map'      => array(
		// List column index (starting at 0) => child form field ID
		0 => 1,
		1 => 3,
		2 => 4,
	),
) );

// To populate another Nested Form field, add another configuration block. For example:
// new GPEP_List_Field_To_Child_Entries( array(
// 	'source_form_id'  => 128,
// 	'target_form_id'  => 130,
// 	'list_field_id'   => 2,
// 	'nested_field_id' => 3,
// 	'max_rows'        => 10,
// 	'column_map'      => array(
// 		0 => 1,
// 		1 => 2,
// 	),
// ) );
