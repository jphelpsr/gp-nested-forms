<?php
/**
 * Gravity Perks // Nested Forms // Check Required Child Values on Parent Submission
 *
 * Replace 130 in the filter name with your PARENT form ID.
 * Checks stored required values; does not rerun all child submission validators.
 */
add_filter( 'gform_validation_130', function( $result ) {
	$form         = $result['form'];
	$current_page = max( 1, (int) rgpost( 'gform_source_page_number_' . $form['id'] ) );
	$is_final     = ! rgpost( 'gform_target_page_number_' . $form['id'] );

	// Unlike empty(), this treats the string "0" as a supplied value.
	$has_value = function( $value ) use ( &$has_value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $has_value( $item ) ) {
					return true;
				}
			}
			return false;
		}
		return trim( (string) $value ) !== '';
	};

	foreach ( $form['fields'] as $field ) {
		// On Next, check this page. On final submission, check all visible pages.
		if ( $field->type !== 'form'
			|| ( ! $is_final && (int) $field->pageNumber !== $current_page )
			|| GFFormsModel::is_field_hidden( $form, $field, array() ) ) {
			continue;
		}

		$child_form = GFAPI::get_form( $field->gpnfForm );
		$entry_ids  = array_unique( array_filter( array_map( 'absint', explode( ',', (string) rgpost( 'input_' . $field->id ) ) ) ) );
		if ( ! $child_form || ! $entry_ids ) {
			continue;
		}

		$incomplete = 0;
		foreach ( $entry_ids as $entry_id ) {
			$entry = GFAPI::get_entry( $entry_id );
			// Nested Forms handles child-entry authorization separately.
			if ( is_wp_error( $entry ) || (int) $entry['form_id'] !== (int) $child_form['id'] ) {
				continue;
			}

			foreach ( $child_form['fields'] as $child_field ) {
				if ( ! $child_field->isRequired
					|| GFFormsModel::is_field_hidden( $child_form, $child_field, array(), $entry ) ) {
					continue;
				}

				$value = GFFormsModel::get_lead_field_value( $entry, $child_field );
				$type  = $child_field->get_input_type();

				if ( $type === 'consent' ) {
					// Consent labels/revisions are not evidence of agreement.
					$value = rgar( $entry, $child_field->id . '.1' );
				} elseif ( $type === 'list' ) {
					$value = maybe_unserialize( $value );
				} elseif ( $type === 'fileupload' && $child_field->multipleFiles ) {
					$value = json_decode( (string) $value, true );
				}

				$missing = ! $has_value( $value );
				// Name/Address fields can be nonempty yet lack a required component.
				if ( in_array( $type, array( 'name', 'address' ), true ) && is_array( $value ) ) {
					$missing = $missing || (bool) $child_field->complex_validation_message( $value, $child_field->get_required_inputs_ids() );
				}

				if ( $missing ) {
					$incomplete++;
					break; // Count each incomplete entry once.
				}
			}
		}

		if ( $incomplete ) {
			$field->failed_validation  = true;
			$field->validation_message = sprintf(
				_n(
					'%d entry is missing required information. Please edit it and complete the required fields.',
					'%d entries are missing required information. Please edit each one and complete the required fields.',
					$incomplete
				),
				$incomplete
			);
			$result['is_valid'] = false;
		}
	}

	$result['form'] = $form;
	return $result;
} );
