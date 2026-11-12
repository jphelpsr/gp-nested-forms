<?php
/**
 * Gravity Perks // Nested Forms + Populate Anything // Child Emails Fallback
 *
 * Server-side failsafe for setups using "Populate Child Entries" + "Populate All Results".
 *
 * After a parent entry is saved, if a field marked with the "gpnf-child-emails-fallback" CSS class
 * is empty, it is filled with the email addresses of the child entries attached through the
 * Nested Form field. This reads the child entry IDs submitted with the Nested Form field, so it
 * does not depend on the session cookie or the hidden gpnf_parent_entry_id field.
 *
 * If Populate Anything already populated the field, this snippet does nothing.
 *
 * Setup:
 * 1. Parent form: add "gpnf-child-emails-fallback" to the CSS Class Name setting of the attendee
 *    email field (alongside "gppa-populate-all"; leave the existing configuration as is).
 * 2. Child form (optional): add "gpnf-child-email" to the CSS Class Name of the email field to use.
 *    If no field has this class, the first Email field in the child form is used.
 *
 * Runs at priority 9 on gform_entry_post_save so the value is in place before add-on feeds
 * (which process at priority 10) send the entry anywhere.
 */
add_filter( 'gform_entry_post_save', function( $entry, $form ) {

	if ( ! function_exists( 'gp_nested_forms' ) ) {
		return $entry;
	}

	$targets       = array();
	$nested_fields = array();

	foreach ( $form['fields'] as $field ) {
		if ( strpos( (string) $field->cssClass, 'gpnf-child-emails-fallback' ) !== false && rgblank( rgar( $entry, (string) $field->id ) ) ) {
			$targets[] = $field;
		}
		if ( $field->get_input_type() === 'form' ) {
			$nested_fields[] = $field;
		}
	}

	// Nothing to do: no fallback field, it was already populated, or no Nested Form field.
	if ( empty( $targets ) || empty( $nested_fields ) ) {
		return $entry;
	}

	$emails = array();

	foreach ( $nested_fields as $nested_field ) {

		$child_form = GFAPI::get_form( $nested_field->gpnfForm );
		if ( ! $child_form ) {
			continue;
		}

		// Prefer a child field flagged with "gpnf-child-email", otherwise use the first Email field.
		$email_field_id = null;
		foreach ( $child_form['fields'] as $child_field ) {
			if ( strpos( (string) $child_field->cssClass, 'gpnf-child-email' ) !== false ) {
				$email_field_id = $child_field->id;
				break;
			}
			if ( ! $email_field_id && $child_field->get_input_type() === 'email' ) {
				$email_field_id = $child_field->id;
			}
		}
		if ( ! $email_field_id ) {
			continue;
		}

		$child_ids = array_filter( array_map( 'absint', explode( ',', (string) rgar( $entry, (string) $nested_field->id ) ) ) );

		foreach ( $child_ids as $child_id ) {
			$child_entry = GFAPI::get_entry( $child_id );
			if ( is_wp_error( $child_entry ) ) {
				continue;
			}
			$email = trim( (string) rgar( $child_entry, (string) $email_field_id ) );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}
	}

	$emails = array_unique( $emails );
	if ( empty( $emails ) ) {
		return $entry;
	}

	// Same separator as the "Populate All Results" snippet.
	$value = implode( ', ', $emails );

	foreach ( $targets as $target ) {
		GFAPI::update_entry_field( $entry['id'], $target->id, $value );
		$entry[ (string) $target->id ] = $value;

		GFCommon::log_debug( sprintf(
			'GPNF Child Emails Fallback: field %d on entry %d was empty; filled from %d child entr(y/ies). UA: %s',
			$target->id,
			$entry['id'],
			count( $emails ),
			rgar( $entry, 'user_agent' )
		) );
	}

	return $entry;
}, 9, 2 );
