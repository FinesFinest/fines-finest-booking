/* global FFBOOKING_ADMIN */
( function () {
	'use strict';

	var texte = window.FFBOOKING_ADMIN || {};

	document.addEventListener( 'click', function ( event ) {
		// Hilfetexte auf- und zuklappen.
		var toggle = event.target.closest( '.ffb-hint-toggle' );
		if ( toggle ) {
			var ziel = document.getElementById( toggle.getAttribute( 'aria-controls' ) );
			if ( ziel ) {
				var offen = toggle.getAttribute( 'aria-expanded' ) === 'true';
				toggle.setAttribute( 'aria-expanded', offen ? 'false' : 'true' );
				ziel.hidden = offen;
			}
			return;
		}

		// Text in die Zwischenablage kopieren.
		var copy = event.target.closest( '.ffb-copy' );
		if ( ! copy ) {
			return;
		}

		var quelle = document.querySelector( copy.getAttribute( 'data-ffb-copy' ) );
		if ( ! quelle ) {
			return;
		}

		var text = quelle.textContent.trim();
		var fertig = function () {
			var alt = copy.textContent;
			copy.textContent = texte.copied || 'Copied';
			window.setTimeout( function () {
				copy.textContent = alt;
			}, 1500 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( fertig, function () {} );
			return;
		}

		// Rückfallweg für ältere Browser.
		var feld = document.createElement( 'textarea' );
		feld.value = text;
		feld.setAttribute( 'readonly', '' );
		feld.style.position = 'absolute';
		feld.style.left = '-9999px';
		document.body.appendChild( feld );
		feld.select();
		try {
			document.execCommand( 'copy' );
			fertig();
		} catch ( e ) {}
		document.body.removeChild( feld );
	} );

	// Client-ID und Client-Secret schon beim Tippen auf typische Fehler pruefen.
	var SUFFIX = '.apps.googleusercontent.com';

	function meldung( feld ) {
		var hinweis = feld.parentNode.querySelector( '.ffb-livecheck' );
		if ( ! hinweis ) {
			hinweis = document.createElement( 'p' );
			hinweis.className = 'description ffb-livecheck';
			hinweis.setAttribute( 'role', 'status' );
			feld.parentNode.appendChild( hinweis );
		}
		return hinweis;
	}

	function pruefen( feld, art ) {
		var wert = feld.value.trim();
		var text = '';

		if ( wert ) {
			var endetAufId = wert.slice( -SUFFIX.length ) === SUFFIX;
			var istSecret = wert.indexOf( 'GOCSPX-' ) === 0;

			if ( /\s/.test( wert ) ) {
				text = texte.spaces;
			} else if ( 'id' === art && istSecret ) {
				text = texte.idSwapped;
			} else if ( 'id' === art && ! endetAufId ) {
				text = texte.idSuffix;
			} else if ( 'secret' === art && endetAufId ) {
				text = texte.secretIsId;
			}
		}

		var hinweis = meldung( feld );
		hinweis.textContent = text;
		hinweis.hidden = ! text;
		feld.setAttribute( 'aria-invalid', text ? 'true' : 'false' );
	}

	[ [ 'ffb-cid', 'id' ], [ 'ffb-cs', 'secret' ] ].forEach( function ( paar ) {
		var feld = document.getElementById( paar[ 0 ] );
		if ( ! feld ) {
			return;
		}
		feld.addEventListener( 'input', function () {
			pruefen( feld, paar[ 1 ] );
		} );
		pruefen( feld, paar[ 1 ] );
	} );

	// Reiter auf der Einstellungsseite. Alle Felder bleiben in einem Formular, die Reiter blenden nur aus.
	( function () {
		var wrap = document.querySelector( '.ffb-admin' );
		var nav = wrap ? wrap.querySelector( '.ffb-tabs' ) : null;
		if ( ! nav ) {
			return;
		}

		var tabs = Array.prototype.slice.call( nav.querySelectorAll( '[data-ffb-tab]' ) );
		var panels = Array.prototype.slice.call( wrap.querySelectorAll( '[data-ffb-panel]' ) );
		var submit = wrap.querySelector( 'form p.submit' );
		var KEY = 'ffbookingSettingsTab';

		function exists( key ) {
			return tabs.some( function ( tab ) {
				return tab.getAttribute( 'data-ffb-tab' ) === key;
			} );
		}

		function remember( key ) {
			try {
				window.localStorage.setItem( KEY, key );
			} catch ( e ) {}
		}

		function remembered() {
			try {
				return window.localStorage.getItem( KEY ) || '';
			} catch ( e ) {
				return '';
			}
		}

		function show( key ) {
			if ( ! exists( key ) ) {
				key = tabs[ 0 ].getAttribute( 'data-ffb-tab' );
			}
			tabs.forEach( function ( tab ) {
				var aktiv = tab.getAttribute( 'data-ffb-tab' ) === key;
				tab.classList.toggle( 'nav-tab-active', aktiv );
				if ( aktiv ) {
					tab.setAttribute( 'aria-current', 'page' );
				} else {
					tab.removeAttribute( 'aria-current' );
				}
			} );
			panels.forEach( function ( panel ) {
				panel.classList.toggle( 'is-active', panel.getAttribute( 'data-ffb-panel' ) === key );
			} );
			// Im Fehlerprotokoll gibt es nichts zu speichern.
			if ( submit ) {
				submit.hidden = 'log' === key;
			}
			remember( key );
		}

		// Reiter zu einem Element, etwa einem Sprungziel aus dem Einrichtungsstand.
		function tabOf( node ) {
			var panel = node && node.closest ? node.closest( '[data-ffb-panel]' ) : null;
			return panel ? panel.getAttribute( 'data-ffb-panel' ) : '';
		}

		function fromHash() {
			var hash = window.location.hash.replace( /^#/, '' );
			if ( ! hash ) {
				return '';
			}
			if ( 0 === hash.indexOf( 'ffb-tab-' ) ) {
				return hash.substring( 8 );
			}
			var ziel = document.getElementById( hash );
			var key = tabOf( ziel );
			if ( key ) {
				show( key );
				ziel.scrollIntoView();
			}
			return key;
		}

		nav.hidden = false;
		wrap.classList.add( 'ffb-has-tabs' );
		show( fromHash() || remembered() );

		nav.addEventListener( 'click', function ( event ) {
			var tab = event.target.closest( '[data-ffb-tab]' );
			if ( ! tab ) {
				return;
			}
			event.preventDefault();
			show( tab.getAttribute( 'data-ffb-tab' ) );
			// Den Reiter in die Adresse schreiben. Ein altes Sprungziel dort wuerde sonst
			// nach dem Speichern wieder den alten Reiter oeffnen.
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#ffb-tab-' + tab.getAttribute( 'data-ffb-tab' ) );
			}
		} );

		window.addEventListener( 'hashchange', fromHash );

		// Lehnt der Browser beim Speichern ein Feld in einem verborgenen Reiter ab, diesen Reiter zeigen.
		// Sonst passiert beim Klick auf Speichern scheinbar nichts.
		wrap.addEventListener( 'invalid', function ( event ) {
			var key = tabOf( event.target );
			if ( key ) {
				show( key );
			}
		}, true );
	}() );
}() );
