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
}() );
