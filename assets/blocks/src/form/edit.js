/**
 * Editor preview for the timeslate/form block.
 *
 * Authors don't need to see the real multi-step wizard while writing
 * the page — the wizard needs live REST calls and mutates form state,
 * which is noisy inside the editor. Instead we render a compact
 * "preview" card showing the heading + subheading with a note pointing
 * at the settings page, so authors know what to configure without
 * leaving the editor.
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const { heading, subheading } = attributes;
	const blockProps = useBlockProps( { className: 'timeslate-form-editor' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Header copy', 'timeslate' ) } initialOpen>
					<TextControl
						label={ __( 'Heading', 'timeslate' ) }
						value={ heading }
						onChange={ ( v ) => setAttributes( { heading: v } ) }
					/>
					<TextControl
						label={ __( 'Subheading', 'timeslate' ) }
						value={ subheading }
						onChange={ ( v ) => setAttributes( { subheading: v } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="timeslate-form-editor__card">
					{ heading && (
						<h3 className="timeslate-form-editor__heading">
							{ heading }
						</h3>
					) }
					{ subheading && (
						<p className="timeslate-form-editor__sub">{ subheading }</p>
					) }
					<div className="timeslate-form-editor__note">
						{ __(
							'The multi-step booking wizard renders on the frontend. Opening hours, capacity, and the booking window are configured under Bookings → Settings.',
							'timeslate'
						) }
					</div>
				</div>
			</div>
		</>
	);
}
