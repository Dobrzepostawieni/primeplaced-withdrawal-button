/**
 * Progressive enhancement for the withdrawal form. The form works without it.
 *
 * - Blocks a second submit while a step is being sent (a double click on
 *   "Confirm withdrawal" is also handled on the server).
 * - Adds a "Copy" button next to the request number on the success screen.
 */

export const lockOnSubmit = ( form: HTMLFormElement ): void => {
	form.addEventListener( 'submit', ( event ) => {
		if ( form.dataset.ppwbSubmitting === '1' ) {
			event.preventDefault();
			return;
		}
		form.dataset.ppwbSubmitting = '1';
		form.querySelectorAll< HTMLButtonElement >(
			'button[type="submit"]'
		).forEach( ( button ) => button.setAttribute( 'aria-disabled', 'true' ) );
	} );
};

export const addCopyButton = ( number: HTMLElement ): void => {
	const label = number.dataset.copyLabel;
	const done = number.dataset.copiedLabel;
	if ( ! label || ! done || ! navigator.clipboard ) {
		return;
	}

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'ppwb-button ppwb-copy';
	button.textContent = label;
	button.addEventListener( 'click', () => {
		navigator.clipboard
			.writeText( ( number.textContent ?? '' ).trim() )
			.then( () => {
				button.textContent = done;
			} )
			.catch( () => undefined );
	} );
	number.after( button );
};

/**
 * Shows the "Other carrier" field only while "Other" is selected. Without
 * JavaScript the field stays visible.
 */
export const toggleOtherCarrier = ( select: HTMLSelectElement ): void => {
	const field = select
		.closest( 'form' )
		?.querySelector< HTMLElement >( '.ppwb-field--carrier-other' );
	if ( ! field ) {
		return;
	}

	const sync = (): void => {
		field.hidden = ! select.selectedOptions[ 0 ]?.hasAttribute(
			'data-ppwb-other'
		);
	};
	select.addEventListener( 'change', sync );
	sync();
};

if ( typeof document !== 'undefined' ) {
	document.addEventListener( 'DOMContentLoaded', () => {
		document
			.querySelectorAll< HTMLFormElement >( '.ppwb-form form' )
			.forEach( lockOnSubmit );
		document
			.querySelectorAll< HTMLElement >( '.ppwb-success__number' )
			.forEach( addCopyButton );
		document
			.querySelectorAll< HTMLSelectElement >( '#ppwb-carrier' )
			.forEach( toggleOtherCarrier );
	} );
}
