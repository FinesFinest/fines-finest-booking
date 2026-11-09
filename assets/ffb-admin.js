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
}() );
