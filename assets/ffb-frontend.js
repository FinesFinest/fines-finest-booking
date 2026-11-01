/* global FFB_CONFIG */
( function () {
	'use strict';

	var config = window.FFB_CONFIG || {};
	var session = null;
	var sessionPromise = null;

	var MONTHS = [ 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' ];
	var DAYS_SHORT = [ 'So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa' ];
	var DAYS_LONG = [ 'Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag' ];

	function api( path, options ) {
		return fetch( config.root + path, options ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					var error = new Error( ( body && body.message ) || 'Da ist etwas schiefgelaufen.' );
					error.code = body && body.code;
					throw error;
				}
				return body;
			} );
		} );
	}

	function getSession() {
		if ( session ) {
			return Promise.resolve( session );
		}
		if ( ! sessionPromise ) {
			sessionPromise = api( '/session', { credentials: 'same-origin' } ).then( function ( data ) {
				session = data;
				return session;
			} );
		}
		return sessionPromise;
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function parseDate( value ) {
		var parts = value.split( '-' );
		return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );
	}

	function longDate( value ) {
		var date = parseDate( value );
		return DAYS_LONG[ date.getDay() ] + ', ' + date.getDate() + '. ' + MONTHS[ date.getMonth() ];
	}

	function Widget( root ) {
		this.root = root;
		this.slug = root.getAttribute( 'data-event' ) || '';
		this.days = parseInt( root.getAttribute( 'data-days' ), 10 ) || 45;
		this.quelle = root.getAttribute( 'data-quelle' ) || '';
		this.data = null;
		this.selectedDate = null;
		this.selectedSlot = null;
	}

	Widget.prototype.load = function ( slug ) {
		var self = this;

		if ( slug ) {
			this.slug = slug;
		}
		if ( ! this.slug ) {
			return;
		}

		this.root.hidden = false;
		this.root.innerHTML = '';
		this.root.appendChild( el( 'div', 'ffb-loading', 'Termine werden geladen …' ) );

		getSession().catch( function () {} );

		api( '/slots?event=' + encodeURIComponent( this.slug ) + '&days=' + this.days )
			.then( function ( data ) {
				self.data = data;
				self.selectedDate = data.days.length ? data.days[ 0 ].date : null;
				self.selectedSlot = null;
				self.render();
			} )
			.catch( function ( error ) {
				self.root.innerHTML = '';
				self.root.appendChild( el( 'div', 'ffb-message ffb-message--error', error.message ) );
			} );
	};

	Widget.prototype.render = function () {
		var self = this;
		var data = this.data;

		this.root.innerHTML = '';

		var head = el( 'div', 'ffb-head' );
		head.appendChild( el( 'h3', 'ffb-head__title', data.event.title ) );

		var meta = el( 'p', 'ffb-head__meta' );
		meta.appendChild( el( 'span', 'ffb-chip', data.event.duration + ' Minuten' ) );
		if ( 'meet' === data.event.location ) {
			meta.appendChild( el( 'span', 'ffb-chip', 'Google Meet' ) );
		} else if ( 'phone' === data.event.location ) {
			meta.appendChild( el( 'span', 'ffb-chip', 'Telefon' ) );
		}
		meta.appendChild( el( 'span', 'ffb-chip', data.timezone.replace( '_', ' ' ) ) );
		head.appendChild( meta );

		if ( data.event.description ) {
			var desc = el( 'div', 'ffb-head__desc' );
			desc.innerHTML = data.event.description;
			head.appendChild( desc );
		}

		this.root.appendChild( head );

		if ( ! data.days.length ) {
			this.root.appendChild( el( 'div', 'ffb-message', 'Aktuell ist kein Termin frei. Schreib mir eine Mail, dann finden wir einen.' ) );
			return;
		}

		var picker = el( 'div', 'ffb-picker' );

		var dayList = el( 'div', 'ffb-days' );
		data.days.forEach( function ( day ) {
			var date = parseDate( day.date );
			var button = el( 'button', 'ffb-day' );
			button.type = 'button';
			button.setAttribute( 'data-date', day.date );
			button.setAttribute( 'aria-pressed', day.date === self.selectedDate ? 'true' : 'false' );
			if ( day.date === self.selectedDate ) {
				button.classList.add( 'is-active' );
			}
			button.appendChild( el( 'span', 'ffb-day__weekday', DAYS_SHORT[ date.getDay() ] ) );
			button.appendChild( el( 'span', 'ffb-day__num', String( date.getDate() ) ) );
			button.appendChild( el( 'span', 'ffb-day__month', MONTHS[ date.getMonth() ].substring( 0, 3 ) ) );
			button.appendChild( el( 'span', 'ffb-day__count', day.slots.length + ( 1 === day.slots.length ? ' Termin' : ' Termine' ) ) );
			button.addEventListener( 'click', function () {
				self.selectedDate = day.date;
				self.selectedSlot = null;
				self.render();
			} );
			dayList.appendChild( button );
		} );
		picker.appendChild( dayList );

		var current = data.days.filter( function ( day ) {
			return day.date === self.selectedDate;
		} )[ 0 ];

		if ( current ) {
			var slotBox = el( 'div', 'ffb-slots' );
			slotBox.appendChild( el( 'p', 'ffb-slots__label', longDate( current.date ) ) );

			var grid = el( 'div', 'ffb-slots__grid' );
			current.slots.forEach( function ( slot ) {
				var button = el( 'button', 'ffb-slot', slot.label );
				button.type = 'button';
				if ( self.selectedSlot && self.selectedSlot.start === slot.start ) {
					button.classList.add( 'is-active' );
				}
				button.addEventListener( 'click', function () {
					self.selectedSlot = slot;
					self.render();
					var form = self.root.querySelector( '.ffb-form' );
					if ( form ) {
						form.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
						var first = form.querySelector( 'input, textarea' );
						if ( first ) {
							first.focus( { preventScroll: true } );
						}
					}
				} );
				grid.appendChild( button );
			} );

			slotBox.appendChild( grid );
			picker.appendChild( slotBox );
		}

		this.root.appendChild( picker );

		if ( this.selectedSlot ) {
			this.root.appendChild( this.buildForm() );
		}
	};

	Widget.prototype.buildForm = function () {
		var self = this;
		var data = this.data;

		var form = el( 'form', 'ffb-form' );
		form.noValidate = true;

		var summary = el( 'div', 'ffb-summary' );
		summary.appendChild( el( 'span', 'ffb-summary__label', 'Dein Termin' ) );
		summary.appendChild( el( 'strong', 'ffb-summary__value', longDate( this.selectedDate ) + ', ' + this.selectedSlot.label + ' Uhr' ) );
		form.appendChild( summary );

		form.appendChild( field( 'text', 'name', 'Name', true ) );
		form.appendChild( field( 'email', 'email', 'E-Mail', true ) );
		form.appendChild( field( 'tel', 'phone', 'Telefon (optional)', false ) );

		data.event.questions.forEach( function ( question ) {
			form.appendChild( field( question.type, 'answers[' + question.key + ']', question.label, !! question.required ) );
		} );

		// Honeypot.
		var trap = el( 'div', 'ffb-trap' );
		trap.setAttribute( 'aria-hidden', 'true' );
		var trapInput = el( 'input' );
		trapInput.type = 'text';
		trapInput.name = 'website_url';
		trapInput.tabIndex = -1;
		trapInput.autocomplete = 'off';
		trap.appendChild( trapInput );
		form.appendChild( trap );

		var consentRow = el( 'label', 'ffb-consent' );
		var consentBox = el( 'input' );
		consentBox.type = 'checkbox';
		consentBox.name = 'consent';
		consentRow.appendChild( consentBox );

		var consentText = el( 'span' );
		consentText.textContent = config.consentText || 'Ich bin mit der Verarbeitung meiner Daten einverstanden.';
		if ( config.privacyUrl ) {
			consentText.appendChild( document.createTextNode( ' ' ) );
			var link = el( 'a', null, 'Datenschutzerklärung' );
			link.href = config.privacyUrl;
			link.target = '_blank';
			link.rel = 'noopener';
			consentText.appendChild( link );
		}
		consentRow.appendChild( consentText );
		form.appendChild( consentRow );

		var submit = el( 'button', 'ffb-btn ffb-btn--primary', 'Termin verbindlich buchen' );
		submit.type = 'submit';
		form.appendChild( submit );

		var message = el( 'div', 'ffb-message' );
		message.hidden = true;
		form.appendChild( message );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.submit( form, submit, message );
		} );

		return form;
	};

	function field( type, name, label, required ) {
		var row = el( 'div', 'ffb-field' );
		var id = 'ffb-' + name.replace( /[^a-z0-9]+/gi, '-' ) + '-' + Math.random().toString( 36 ).substring( 2, 7 );

		var labelNode = el( 'label', 'ffb-field__label', label + ( required ? ' *' : '' ) );
		labelNode.setAttribute( 'for', id );
		row.appendChild( labelNode );

		var input;
		if ( 'textarea' === type ) {
			input = el( 'textarea', 'ffb-field__input' );
			input.rows = 4;
		} else {
			input = el( 'input', 'ffb-field__input' );
			input.type = 'tel' === type || 'url' === type || 'email' === type ? type : 'text';
		}

		input.id = id;
		input.name = name;
		if ( required ) {
			input.setAttribute( 'data-required', '1' );
		}
		row.appendChild( input );

		return row;
	}

	Widget.prototype.submit = function ( form, submit, message ) {
		var self = this;

		var answers = {};
		var payload = {
			event: this.slug,
			start: this.selectedSlot.start,
			name: '',
			email: '',
			phone: '',
			consent: form.querySelector( 'input[name="consent"]' ).checked ? 1 : 0,
			source: this.quelle,
			website_url: form.querySelector( 'input[name="website_url"]' ).value
		};

		var missing = null;
		Array.prototype.forEach.call( form.querySelectorAll( '.ffb-field__input' ), function ( input ) {
			var value = input.value.trim();
			input.classList.remove( 'has-error' );

			if ( input.getAttribute( 'data-required' ) && ! value ) {
				input.classList.add( 'has-error' );
				if ( ! missing ) {
					missing = input;
				}
			}

			var match = input.name.match( /^answers\[(.+)\]$/ );
			if ( match ) {
				answers[ match[ 1 ] ] = value;
			} else {
				payload[ input.name ] = value;
			}
		} );

		payload.answers = answers;

		if ( missing ) {
			showMessage( message, 'Bitte fülle die Pflichtfelder aus.', true );
			missing.focus();
			return;
		}

		if ( ! payload.consent ) {
			showMessage( message, 'Ohne dein Einverständnis zur Datenverarbeitung kann ich den Termin nicht anlegen.', true );
			return;
		}

		submit.disabled = true;
		submit.textContent = 'Wird gebucht …';
		message.hidden = true;

		getSession()
			.then( function ( data ) {
				payload.token = data.token;
				payload.issued = data.issued;

				return api( '/book', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( payload )
				} );
			} )
			.then( function ( result ) {
				self.renderSuccess( result );
			} )
			.catch( function ( error ) {
				submit.disabled = false;
				submit.textContent = 'Termin verbindlich buchen';
				showMessage( message, error.message, true );

				if ( 'ffb_slot_gone' === error.code || 'ffb_taken' === error.code ) {
					window.setTimeout( function () {
						self.load();
					}, 2500 );
				}
			} );
	};

	Widget.prototype.renderSuccess = function ( result ) {
		this.root.innerHTML = '';

		var box = el( 'div', 'ffb-success' );
		box.appendChild( el( 'h3', 'ffb-success__title', 'Termin steht.' ) );
		box.appendChild( el( 'p', 'ffb-success__when', result.when + ' Uhr' ) );
		box.appendChild( el( 'p', null, 'Ich habe dir eine Bestätigung an ' + result.email + ' geschickt, mit Kalendereintrag zum Anklicken.' ) );

		if ( result.meet_url ) {
			var link = el( 'a', 'ffb-btn ffb-btn--primary', 'Zum Videocall' );
			link.href = result.meet_url;
			link.target = '_blank';
			link.rel = 'noopener';
			box.appendChild( link );
		} else if ( 'phone' === result.location ) {
			box.appendChild( el( 'p', null, 'Ich rufe dich zur vereinbarten Zeit an.' ) );
		}

		this.root.appendChild( box );
		this.root.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	};

	function showMessage( node, text, isError ) {
		node.textContent = text;
		node.className = 'ffb-message' + ( isError ? ' ffb-message--error' : ' ffb-message--ok' );
		node.hidden = false;
	}

	function initCancel( box ) {
		var button = box.querySelector( '[data-ffb-cancel-confirm]' );
		var message = box.querySelector( '[data-ffb-cancel-message]' );

		if ( ! button ) {
			return;
		}

		getSession().catch( function () {} );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.textContent = 'Wird abgesagt …';

			getSession()
				.then( function ( data ) {
					return api( '/cancel', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( {
							cancel_token: box.getAttribute( 'data-token' ),
							token: data.token,
							issued: data.issued
						} )
					} );
				} )
				.then( function ( result ) {
					button.remove();
					showMessage( message, result.message, false );
				} )
				.catch( function ( error ) {
					button.disabled = false;
					button.textContent = 'Ja, Termin absagen';
					showMessage( message, error.message, true );
				} );
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ffb-widget]' ), function ( node ) {
			var widget = new Widget( node );
			node.ffbWidget = widget;
			if ( node.getAttribute( 'data-event' ) ) {
				widget.load();
			}
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-ffb-pick]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var wrapper = button.closest( '.ffb-chooser' );
				var target = wrapper ? wrapper.querySelector( '[data-ffb-widget]' ) : null;
				if ( ! target || ! target.ffbWidget ) {
					return;
				}
				Array.prototype.forEach.call( wrapper.querySelectorAll( '[data-ffb-pick]' ), function ( other ) {
					other.classList.toggle( 'is-active', other === button );
				} );
				target.ffbWidget.load( button.getAttribute( 'data-ffb-pick' ) );
			} );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-ffb-cancel]' ), initCancel );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
