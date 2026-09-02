/**
 * Frontend booking wizard — mounts into every
 * `.timeslate-form-container` emitted by render.php.
 *
 * Flow is four steps + a success screen:
 *   1. When & who — date, number of people, time (availability fetched live)
 *   2. Contact   — name, email, phone
 *   3. Notes     — optional special requests
 *   4. Review    — summary + submit
 *   → success state with confirmation message
 *
 * React (wp-element) for state; fetch() for REST calls; no framework
 * beyond WP's bundled wp-element so we don't ship our own React copy.
 *
 * The container's data-* attributes carry per-render config (REST
 * base URL, max people from options, max days ahead). Mounting supports
 * multiple instances per page for authors who want the form in more
 * than one spot.
 */
import { createRoot, useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

// ---- Entry point ------------------------------------------------------

function mountAll() {
	const containers = document.querySelectorAll( '.timeslate-form-container' );
	containers.forEach( ( node ) => {
		if ( node.dataset.sbMounted === '1' ) {
			return;
		}
		node.dataset.sbMounted = '1';

		const config = {
			restBase: String( node.dataset.restBase || '' ).replace( /\/+$/, '' ),
			maxPeople: parseInt( node.dataset.maxPeople || '10', 10 ) || 10,
			maxDays:  parseInt( node.dataset.maxDays  || '60', 10 ) || 60,
		};

		const root = createRoot( node );
		root.render( <BookingWizard config={ config } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAll );
} else {
	mountAll();
}

// ---- Main component ---------------------------------------------------

function BookingWizard( { config } ) {
	const [ step, setStep ]             = useState( 1 );
	const [ form, setForm ]             = useState( {
		date: '',
		people: 2,
		time: '',
		name: '',
		email: '',
		phone: '',
		notes: '',
		website: '', // honeypot
	} );
	const [ availability, setAvailability ] = useState( null );
	const [ availLoading, setAvailLoading ] = useState( false );
	const [ submitting, setSubmitting ]     = useState( false );
	const [ submitError, setSubmitError ]   = useState( '' );
	const [ result, setResult ]             = useState( null );

	const updateForm = ( patch ) => setForm( ( prev ) => ( { ...prev, ...patch } ) );

	// When date or people changes on step 1, re-fetch availability.
	useEffect( () => {
		if ( step !== 1 || ! form.date || ! form.people ) {
			return undefined;
		}
		const controller = new AbortController();
		setAvailLoading( true );

		fetch(
			`${ config.restBase }/availability?date=${ encodeURIComponent( form.date ) }&people=${ form.people }`,
			{ signal: controller.signal }
		)
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				setAvailability( data );
				setAvailLoading( false );
				// If the previously selected time is no longer in the new
				// availability, clear it so the user re-picks.
				if ( form.time && Array.isArray( data.slots ) ) {
					const stillThere = data.slots.some( ( s ) => s.time === form.time );
					if ( ! stillThere ) {
						updateForm( { time: '' } );
					}
				}
			} )
			.catch( ( e ) => {
				if ( e.name === 'AbortError' ) {
					return;
				}
				setAvailability( {
					status: 'error',
					slots: [],
					message: __( 'Could not load availability. Please try again.', 'timeslate' ),
				} );
				setAvailLoading( false );
			} );

		return () => controller.abort();
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ form.date, form.people, step ] );

	async function submit() {
		setSubmitting( true );
		setSubmitError( '' );
		try {
			const response = await fetch( `${ config.restBase }/bookings`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( form ),
			} );
			const data = await response.json().catch( () => ( {} ) );
			if ( ! response.ok ) {
				setSubmitError( data.message || __( 'Booking failed. Please try again.', 'timeslate' ) );
				setSubmitting( false );
				return;
			}
			setResult( data );
			setSubmitting( false );
		} catch ( e ) {
			setSubmitError( __( 'Network error. Please try again.', 'timeslate' ) );
			setSubmitting( false );
		}
	}

	if ( result ) {
		return <SuccessView result={ result } form={ form } />;
	}

	return (
		<div className="timeslate-form" aria-live="polite">
			<Stepper step={ step } total={ 4 } />

			{ step === 1 && (
				<StepWhen
					form={ form }
					updateForm={ updateForm }
					availability={ availability }
					availLoading={ availLoading }
					config={ config }
				/>
			) }
			{ step === 2 && <StepContact form={ form } updateForm={ updateForm } /> }
			{ step === 3 && <StepNotes   form={ form } updateForm={ updateForm } /> }
			{ step === 4 && <StepReview  form={ form } /> }

			{ submitError && (
				<div className="timeslate-form__error" role="alert">
					{ submitError }
				</div>
			) }

			<div className="timeslate-form__nav">
				{ step > 1 && (
					<button
						type="button"
						className="timeslate-form__btn timeslate-form__btn--ghost"
						onClick={ () => setStep( step - 1 ) }
						disabled={ submitting }
					>
						{ __( 'Back', 'timeslate' ) }
					</button>
				) }
				{ step < 4 && (
					<button
						type="button"
						className="timeslate-form__btn timeslate-form__btn--primary"
						onClick={ () => setStep( step + 1 ) }
						disabled={ ! isStepValid( step, form ) }
					>
						{ __( 'Continue', 'timeslate' ) }
					</button>
				) }
				{ step === 4 && (
					<button
						type="button"
						className="timeslate-form__btn timeslate-form__btn--primary"
						onClick={ submit }
						disabled={ submitting }
					>
						{ submitting
							? __( 'Reserving…', 'timeslate' )
							: __( 'Confirm booking', 'timeslate' ) }
					</button>
				) }
			</div>

			{ /* Honeypot — visually hidden from humans, visible to bots. */ }
			<div aria-hidden="true" style={ { position: 'absolute', left: '-9999px', top: 'auto', width: 1, height: 1, overflow: 'hidden' } }>
				<label>
					{ __( 'Website', 'timeslate' ) }
					<input
						type="text"
						name="website"
						tabIndex="-1"
						autoComplete="off"
						value={ form.website }
						onChange={ ( e ) => updateForm( { website: e.target.value } ) }
					/>
				</label>
			</div>
		</div>
	);
}

function isStepValid( step, form ) {
	if ( step === 1 ) {
		return form.date && form.people >= 1 && form.time;
	}
	if ( step === 2 ) {
		return (
			form.name.trim().length > 0 &&
			/^\S+@\S+\.\S+$/.test( form.email ) &&
			form.phone.trim().length > 0
		);
	}
	return true; // step 3 (notes) is optional; step 4 uses submit button
}

// ---- Step components --------------------------------------------------

function Stepper( { step, total } ) {
	const labels = [
		__( 'When & who', 'timeslate' ),
		__( 'Contact',    'timeslate' ),
		__( 'Notes',      'timeslate' ),
		__( 'Review',     'timeslate' ),
	];
	return (
		<ol className="timeslate-form__steps" aria-label={ __( 'Booking steps', 'timeslate' ) }>
			{ labels.map( ( label, i ) => {
				const num = i + 1;
				const state = num < step ? 'done' : num === step ? 'active' : 'pending';
				return (
					<li
						key={ num }
						className={ `timeslate-form__step timeslate-form__step--${ state }` }
						aria-current={ num === step ? 'step' : undefined }
					>
						<span className="timeslate-form__step-num">{ num }</span>
						<span className="timeslate-form__step-label">{ label }</span>
					</li>
				);
			} ) }
		</ol>
	);
}

function StepWhen( { form, updateForm, availability, availLoading, config } ) {
	const today   = useMemo( () => todayISO(), [] );
	const maxDate = useMemo( () => addDaysISO( today, config.maxDays ), [ today, config.maxDays ] );

	return (
		<div className="timeslate-form__step-body">
			<h3 className="timeslate-form__step-heading">
				{ __( 'When & who', 'timeslate' ) }
			</h3>

			<div className="timeslate-form__when">
				<div className="timeslate-form__when-left">
					<Calendar
						selected={ form.date }
						onSelect={ ( iso ) => updateForm( { date: iso, time: '' } ) }
						minISO={ today }
						maxISO={ maxDate }
						today={ today }
					/>
				</div>

				<div className="timeslate-form__when-right">
					{ ! form.date && (
						<p className="timeslate-form__hint">
							{ __( 'Pick a date to see number of people and available times.', 'timeslate' ) }
						</p>
					) }

					{ form.date && (
						<>
							<div className="timeslate-form__field">
								<span className="timeslate-form__field-label">
									{ __( 'Number of people', 'timeslate' ) }
								</span>
								<PartyPicker
									value={ form.people }
									max={ config.maxPeople }
									onChange={ ( n ) => updateForm( { people: n, time: '' } ) }
								/>
							</div>

							<div className="timeslate-form__field">
								<span className="timeslate-form__field-label">
									{ __( 'Available times', 'timeslate' ) }
								</span>
								<SlotPicker
									form={ form }
									updateForm={ updateForm }
									availability={ availability }
									availLoading={ availLoading }
								/>
							</div>
						</>
					) }
				</div>
			</div>
		</div>
	);
}

// Month-grid date picker. Pure state + ISO-string I/O — no Date objects leak out.
function Calendar( { selected, onSelect, minISO, maxISO, today } ) {
	const initial = parseISO( selected || today );
	const [ viewYear, setViewYear ]   = useState( initial.year );
	const [ viewMonth, setViewMonth ] = useState( initial.month );

	const minParts = parseISO( minISO );
	const maxParts = parseISO( maxISO );

	const canPrev = ymCompare( viewYear, viewMonth, minParts.year, minParts.month ) > 0;
	const canNext = ymCompare( viewYear, viewMonth, maxParts.year, maxParts.month ) < 0;

	const cells = useMemo(
		() => buildMonthCells( viewYear, viewMonth ),
		[ viewYear, viewMonth ]
	);

	const monthLabel = new Date( viewYear, viewMonth, 1 ).toLocaleDateString( undefined, {
		month: 'long',
		year: 'numeric',
	} );

	const weekdays = useMemo( () => {
		// Sunday-first, short names from the runtime locale.
		const base = new Date( 2023, 0, 1 ); // Jan 1 2023 was a Sunday
		return Array.from( { length: 7 } ).map( ( _, i ) => {
			const d = new Date( base );
			d.setDate( base.getDate() + i );
			return d.toLocaleDateString( undefined, { weekday: 'short' } );
		} );
	}, [] );

	function gotoPrev() {
		if ( ! canPrev ) return;
		if ( viewMonth === 0 ) {
			setViewYear( viewYear - 1 );
			setViewMonth( 11 );
		} else {
			setViewMonth( viewMonth - 1 );
		}
	}
	function gotoNext() {
		if ( ! canNext ) return;
		if ( viewMonth === 11 ) {
			setViewYear( viewYear + 1 );
			setViewMonth( 0 );
		} else {
			setViewMonth( viewMonth + 1 );
		}
	}

	return (
		<div className="timeslate-form__cal" role="group" aria-label={ __( 'Choose a date', 'timeslate' ) }>
			<div className="timeslate-form__cal-header">
				<button
					type="button"
					className="timeslate-form__cal-nav"
					onClick={ gotoPrev }
					disabled={ ! canPrev }
					aria-label={ __( 'Previous month', 'timeslate' ) }
				>
					‹
				</button>
				<div className="timeslate-form__cal-label" aria-live="polite">{ monthLabel }</div>
				<button
					type="button"
					className="timeslate-form__cal-nav"
					onClick={ gotoNext }
					disabled={ ! canNext }
					aria-label={ __( 'Next month', 'timeslate' ) }
				>
					›
				</button>
			</div>

			<div className="timeslate-form__cal-weekdays" aria-hidden="true">
				{ weekdays.map( ( w, i ) => (
					<div key={ i } className="timeslate-form__cal-weekday">{ w }</div>
				) ) }
			</div>

			<div className="timeslate-form__cal-grid" role="grid">
				{ cells.map( ( cell ) => {
					const outOfMonth = cell.month !== viewMonth;
					const beforeMin  = isoCompare( cell.iso, minISO ) < 0;
					const afterMax   = isoCompare( cell.iso, maxISO ) > 0;
					const disabled   = outOfMonth || beforeMin || afterMax;
					const isSelected = selected && cell.iso === selected;
					const isToday    = cell.iso === today;

					const classes = [
						'timeslate-form__cal-day',
						outOfMonth && 'timeslate-form__cal-day--outside',
						disabled && 'timeslate-form__cal-day--disabled',
						isToday && 'timeslate-form__cal-day--today',
						isSelected && 'timeslate-form__cal-day--selected',
					].filter( Boolean ).join( ' ' );

					return (
						<button
							key={ cell.iso }
							type="button"
							role="gridcell"
							className={ classes }
							disabled={ disabled }
							aria-selected={ isSelected ? 'true' : 'false' }
							aria-current={ isToday ? 'date' : undefined }
							onClick={ () => ! disabled && onSelect( cell.iso ) }
						>
							{ cell.day }
						</button>
					);
				} ) }
			</div>
		</div>
	);
}

function PartyPicker( { value, max, onChange } ) {
	// Pill row for small max; fall back to a <select> once maxPeople grows past
	// what fits cleanly in the right-pane width.
	if ( max > 10 ) {
		const opts = [];
		for ( let i = 1; i <= max; i++ ) opts.push( i );
		return (
			<select
				className="timeslate-form__party-select"
				value={ value }
				onChange={ ( e ) => onChange( parseInt( e.target.value, 10 ) || 1 ) }
			>
				{ opts.map( ( n ) => (
					<option key={ n } value={ n }>
						{ sprintf(
							n === 1 ? __( '%d person', 'timeslate' ) : __( '%d people', 'timeslate' ),
							n
						) }
					</option>
				) ) }
			</select>
		);
	}

	const opts = [];
	for ( let i = 1; i <= max; i++ ) opts.push( i );
	return (
		<div className="timeslate-form__party" role="radiogroup" aria-label={ __( 'Number of people', 'timeslate' ) }>
			{ opts.map( ( n ) => {
				const selected = n === value;
				return (
					<button
						key={ n }
						type="button"
						role="radio"
						aria-checked={ selected }
						className={ `timeslate-form__party-pill${ selected ? ' timeslate-form__party-pill--selected' : '' }` }
						onClick={ () => onChange( n ) }
					>
						{ n }
					</button>
				);
			} ) }
		</div>
	);
}

function SlotPicker( { form, updateForm, availability, availLoading } ) {
	if ( ! form.date ) {
		return (
			<p className="timeslate-form__hint">
				{ __( 'Pick a date to see available times.', 'timeslate' ) }
			</p>
		);
	}
	if ( availLoading ) {
		// Skeleton placeholder — six shimmering tiles matching the real
		// slot grid's shape, so the layout doesn't jump when the real
		// slots land.
		return (
			<div
				className="timeslate-form__slots"
				aria-busy="true"
				aria-label={ __( 'Loading times…', 'timeslate' ) }
			>
				{ Array.from( { length: 6 } ).map( ( _, i ) => (
					<div
						key={ i }
						className="timeslate-form__slot timeslate-form__slot--skeleton"
						aria-hidden="true"
					/>
				) ) }
			</div>
		);
	}
	if ( ! availability ) {
		return null;
	}

	if ( availability.status !== 'open' ) {
		return (
			<p className="timeslate-form__hint timeslate-form__hint--warn">
				{ availabilityStatusMessage( availability.status ) }
			</p>
		);
	}

	if ( ! Array.isArray( availability.slots ) || availability.slots.length === 0 ) {
		return (
			<p className="timeslate-form__hint timeslate-form__hint--warn">
				{ __( 'No times available for that date and number of people. Try another date.', 'timeslate' ) }
			</p>
		);
	}

	return (
		<div className="timeslate-form__slots" role="radiogroup" aria-label={ __( 'Available times', 'timeslate' ) }>
			{ availability.slots.map( ( slot ) => {
				const selected = form.time === slot.time;
				return (
					<button
						key={ slot.time + ':' + slot.period }
						type="button"
						role="radio"
						aria-checked={ selected }
						className={ `timeslate-form__slot${ selected ? ' timeslate-form__slot--selected' : '' }` }
						onClick={ () => updateForm( { time: slot.time } ) }
					>
						<span className="timeslate-form__slot-time">{ formatTime( slot.time ) }</span>
						<span className="timeslate-form__slot-cap">
							{ sprintf(
								/* translators: %d = seats */
								__( '%d places left', 'timeslate' ),
								slot.places_remaining
							) }
						</span>
					</button>
				);
			} ) }
		</div>
	);
}

function StepContact( { form, updateForm } ) {
	return (
		<div className="timeslate-form__step-body">
			<h3 className="timeslate-form__step-heading">
				{ __( 'Your details', 'timeslate' ) }
			</h3>

			<label className="timeslate-form__field">
				<span className="timeslate-form__field-label">
					{ __( 'Name', 'timeslate' ) }
				</span>
				<input
					type="text"
					required
					autoComplete="name"
					value={ form.name }
					onChange={ ( e ) => updateForm( { name: e.target.value } ) }
				/>
			</label>

			<label className="timeslate-form__field">
				<span className="timeslate-form__field-label">
					{ __( 'Email', 'timeslate' ) }
				</span>
				<input
					type="email"
					required
					autoComplete="email"
					value={ form.email }
					onChange={ ( e ) => updateForm( { email: e.target.value } ) }
				/>
			</label>

			<label className="timeslate-form__field">
				<span className="timeslate-form__field-label">
					{ __( 'Phone', 'timeslate' ) }
				</span>
				<input
					type="tel"
					required
					autoComplete="tel"
					value={ form.phone }
					onChange={ ( e ) => updateForm( { phone: e.target.value } ) }
				/>
			</label>
		</div>
	);
}

function StepNotes( { form, updateForm } ) {
	return (
		<div className="timeslate-form__step-body">
			<h3 className="timeslate-form__step-heading">
				{ __( 'Anything we should know?', 'timeslate' ) }
			</h3>
			<p className="timeslate-form__hint">
				{ __( 'Optional — allergies, accessibility needs, special occasion.', 'timeslate' ) }
			</p>
			<label className="timeslate-form__field">
				<span className="timeslate-form__field-label">
					{ __( 'Notes', 'timeslate' ) }
				</span>
				<textarea
					rows={ 4 }
					maxLength={ 500 }
					value={ form.notes }
					onChange={ ( e ) => updateForm( { notes: e.target.value } ) }
				/>
				<span className="timeslate-form__hint timeslate-form__hint--right">
					{ sprintf( __( '%d / 500', 'timeslate' ), form.notes.length ) }
				</span>
			</label>
		</div>
	);
}

function StepReview( { form } ) {
	const summary = [
		[ __( 'Date', 'timeslate' ),  form.date ],
		[ __( 'Time', 'timeslate' ),  formatTime( form.time ) ],
		[ __( 'People', 'timeslate' ), sprintf(
			form.people === 1 ? __( '%d person', 'timeslate' ) : __( '%d people', 'timeslate' ),
			form.people
		) ],
		[ __( 'Name', 'timeslate' ),  form.name ],
		[ __( 'Email', 'timeslate' ), form.email ],
		[ __( 'Phone', 'timeslate' ), form.phone ],
	];
	if ( form.notes ) {
		summary.push( [ __( 'Notes', 'timeslate' ), form.notes ] );
	}
	return (
		<div className="timeslate-form__step-body">
			<h3 className="timeslate-form__step-heading">
				{ __( 'Review', 'timeslate' ) }
			</h3>
			<dl className="timeslate-form__summary">
				{ summary.map( ( [ label, value ] ) => (
					<div key={ label } className="timeslate-form__summary-row">
						<dt>{ label }</dt>
						<dd>{ value }</dd>
					</div>
				) ) }
			</dl>
		</div>
	);
}

function SuccessView( { result, form } ) {
	return (
		<div className="timeslate-form timeslate-form--success" role="status">
			<div className="timeslate-form__success-icon" aria-hidden="true">✓</div>
			<h3 className="timeslate-form__step-heading">
				{ result.status === 'confirmed'
					? __( 'You\'re booked.', 'timeslate' )
					: __( 'Request received.', 'timeslate' ) }
			</h3>
			<p>{ result.message }</p>
			<dl className="timeslate-form__summary">
				<div className="timeslate-form__summary-row">
					<dt>{ __( 'Date', 'timeslate' ) }</dt>
					<dd>{ form.date }</dd>
				</div>
				<div className="timeslate-form__summary-row">
					<dt>{ __( 'Time', 'timeslate' ) }</dt>
					<dd>{ formatTime( form.time ) }</dd>
				</div>
				<div className="timeslate-form__summary-row">
					<dt>{ __( 'People', 'timeslate' ) }</dt>
					<dd>{ form.people }</dd>
				</div>
				<div className="timeslate-form__summary-row">
					<dt>{ __( 'Reference', 'timeslate' ) }</dt>
					<dd>#{ result.id }</dd>
				</div>
			</dl>
		</div>
	);
}

// ---- Pure helpers -----------------------------------------------------

function availabilityStatusMessage( status ) {
	switch ( status ) {
		case 'closed':
			return __( 'We are closed on that day.', 'timeslate' );
		case 'blackout':
			return __( 'Bookings are not accepted on that date.', 'timeslate' );
		case 'past':
			return __( 'Please choose a future date.', 'timeslate' );
		case 'too_far':
			return __( 'That date is beyond our booking window.', 'timeslate' );
		case 'too_many_people':
			return __( 'For parties that large, please call us directly.', 'timeslate' );
		case 'invalid_date':
			return __( 'Please pick a valid date.', 'timeslate' );
		case 'error':
			return __( 'Could not load availability. Please try again.', 'timeslate' );
		default:
			return __( 'No times available.', 'timeslate' );
	}
}

function formatTime( hhmm ) {
	if ( ! hhmm ) {
		return '';
	}
	// Keep it simple — server supplies 24h, browser locale can fancy it up
	// later if we add Intl formatting. Right now a plain string is clearest.
	return hhmm;
}

function todayISO() {
	const d = new Date();
	return (
		d.getFullYear() +
		'-' +
		String( d.getMonth() + 1 ).padStart( 2, '0' ) +
		'-' +
		String( d.getDate() ).padStart( 2, '0' )
	);
}

function addDaysISO( isoDate, days ) {
	const [ y, m, d ] = isoDate.split( '-' ).map( ( x ) => parseInt( x, 10 ) );
	const dt = new Date( y, m - 1, d );
	dt.setDate( dt.getDate() + days );
	return (
		dt.getFullYear() +
		'-' +
		String( dt.getMonth() + 1 ).padStart( 2, '0' ) +
		'-' +
		String( dt.getDate() ).padStart( 2, '0' )
	);
}

function parseISO( iso ) {
	const [ y, m, d ] = iso.split( '-' ).map( ( x ) => parseInt( x, 10 ) );
	return { year: y, month: m - 1, day: d };
}

function ymCompare( aY, aM, bY, bM ) {
	if ( aY !== bY ) return aY - bY;
	return aM - bM;
}

function isoCompare( a, b ) {
	// ISO YYYY-MM-DD strings sort lexically, so plain string compare is enough.
	if ( a < b ) return -1;
	if ( a > b ) return 1;
	return 0;
}

// Returns a 42-cell grid (6 weeks) starting on the Sunday before (or on) the
// first of the target month. Each cell is { iso, day, month }.
function buildMonthCells( year, month ) {
	const first = new Date( year, month, 1 );
	const offset = first.getDay(); // 0=Sun … 6=Sat
	const start = new Date( year, month, 1 - offset );
	const cells = [];
	for ( let i = 0; i < 42; i++ ) {
		const d = new Date( start );
		d.setDate( start.getDate() + i );
		const iso =
			d.getFullYear() +
			'-' +
			String( d.getMonth() + 1 ).padStart( 2, '0' ) +
			'-' +
			String( d.getDate() ).padStart( 2, '0' );
		cells.push( { iso, day: d.getDate(), month: d.getMonth() } );
	}
	return cells;
}
