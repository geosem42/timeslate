/* Timeslate — settings page interactivity.
   Handles three interactions: adding a period row to a day, removing a
   period row, and managing the blackout-date list (add unique dates,
   remove them). No framework; single delegated click listener on the
   document keeps the wiring trivial. */

( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		// --- Period rows ---

		const addPeriodBtn = e.target.closest( '.ts-period-add' );
		if ( addPeriodBtn ) {
			e.preventDefault();
			addPeriodRow( addPeriodBtn );
			return;
		}

		const removePeriodBtn = e.target.closest( '.ts-period-remove' );
		if ( removePeriodBtn ) {
			e.preventDefault();
			const row = removePeriodBtn.closest( '.ts-period' );
			if ( row ) {
				row.remove();
			}
			return;
		}

		// --- Blackout dates ---

		const addBlackoutBtn = e.target.closest( '#ts-blackout-add' );
		if ( addBlackoutBtn ) {
			e.preventDefault();
			addBlackoutDate();
			return;
		}

		const removeBlackoutBtn = e.target.closest( '.ts-blackout-remove' );
		if ( removeBlackoutBtn ) {
			e.preventDefault();
			const item = removeBlackoutBtn.closest( '.ts-blackout-item' );
			if ( item ) {
				item.remove();
			}
		}
	} );

	// Pressing Enter inside the blackout date picker should add the
	// date rather than submit the whole form.
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && e.target && e.target.id === 'ts-blackout-input' ) {
			e.preventDefault();
			addBlackoutDate();
		}
	} );

	function addPeriodRow( button ) {
		const day = button.closest( '.ts-day' );
		if ( ! day ) {
			return;
		}
		const dayIdx = day.getAttribute( 'data-day' );
		const periodsWrap = day.querySelector( '.ts-day-periods' );
		const tpl = document.getElementById( 'ts-period-template' );
		if ( ! periodsWrap || ! tpl ) {
			return;
		}

		const count = periodsWrap.children.length;
		const clone = tpl.content.firstElementChild.cloneNode( true );
		clone.setAttribute( 'data-period', String( count ) );

		// Re-index every name="opening_hours[<day>][periods][<i>][...]" so
		// PHP sees a dense array. The template uses day=0 i=0 so we can
		// swap blindly with a regex.
		clone.querySelectorAll( '[name]' ).forEach( function ( input ) {
			input.name = input.name.replace(
				/opening_hours\[\d+\]\[periods\]\[\d+\]/,
				'opening_hours[' + dayIdx + '][periods][' + count + ']'
			);
			// Template values were rendered server-side with defaults of
			// 00:00 / 0; clear them so the owner types fresh values.
			if ( input.type === 'time' || input.type === 'number' ) {
				input.value = '';
			}
		} );

		periodsWrap.appendChild( clone );
		const firstInput = clone.querySelector( 'input' );
		if ( firstInput ) {
			firstInput.focus();
		}
	}

	function addBlackoutDate() {
		const picker = document.getElementById( 'ts-blackout-input' );
		const list = document.getElementById( 'ts-blackout-list' );
		const tpl = document.getElementById( 'ts-blackout-template' );
		if ( ! picker || ! list || ! tpl ) {
			return;
		}

		const value = picker.value;
		if ( ! value ) {
			return;
		}

		// Skip duplicates — checking the hidden inputs already in the list.
		const existing = list.querySelectorAll( 'input[name="blackout_dates[]"]' );
		for ( let i = 0; i < existing.length; i++ ) {
			if ( existing[ i ].value === value ) {
				picker.value = '';
				return;
			}
		}

		const clone = tpl.content.firstElementChild.cloneNode( true );
		const hidden = clone.querySelector( 'input[type="hidden"]' );
		const label = clone.querySelector( '.ts-blackout-date' );
		if ( hidden ) {
			hidden.value = value;
		}
		if ( label ) {
			label.textContent = value;
		}

		// Keep the list sorted by date so the UI mirrors the sanitized
		// server-side order.
		const nodes = Array.from( list.children );
		let inserted = false;
		for ( let i = 0; i < nodes.length; i++ ) {
			const existingVal = nodes[ i ].querySelector( 'input[type="hidden"]' ).value;
			if ( value < existingVal ) {
				list.insertBefore( clone, nodes[ i ] );
				inserted = true;
				break;
			}
		}
		if ( ! inserted ) {
			list.appendChild( clone );
		}

		picker.value = '';
	}
} )();
