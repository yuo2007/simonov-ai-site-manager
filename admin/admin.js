/**
 * Settings page behaviour: copy buttons and the select-all / deselect-all
 * toggles for the tool list. Every block is a no-op when its elements are
 * not on the page (the token panel, for one, only renders right after a
 * token is generated).
 */
( function () {
	var flash = function ( btn ) {
		btn.textContent = 'Copied ✓';
		setTimeout( function () { btn.textContent = 'Copy'; }, 1500 );
	};

	// Copy the value of the field sitting next to the button.
	document.querySelectorAll( '.wpmcp-copy' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var src = btn.parentNode.querySelector( '.wpmcp-copy-src' );
			var done = function () { flash( btn ); };
			src.select();
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( src.value ).then( done, function () { document.execCommand( 'copy' ); done(); } );
			} else {
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );

	// Copy the button's own data-copy attribute.
	document.querySelectorAll( '.wpmcp-copy2' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var v = btn.getAttribute( 'data-copy' );
			var done = function () { flash( btn ); };
			if ( navigator.clipboard ) { navigator.clipboard.writeText( v ).then( done, done ); } else { done(); }
		} );
	} );

	// Readonly fields select their whole content on click.
	document.querySelectorAll( '.wpmcp-copy-src' ).forEach( function ( el ) {
		el.addEventListener( 'click', function () { el.select(); } );
	} );

	var set = function ( on ) {
		document.querySelectorAll( '.wpmcp-tool' ).forEach( function ( cb ) {
			cb.checked = on;
			cb.parentNode.querySelector( 'span' ).style.textDecoration = on ? 'none' : 'line-through';
		} );
	};
	var all  = document.getElementById( 'wpmcp-check-all' );
	var none = document.getElementById( 'wpmcp-uncheck-all' );
	if ( all ) {
		all.addEventListener( 'click', function () { set( true ); } );
	}
	if ( none ) {
		none.addEventListener( 'click', function () { set( false ); } );
	}
}() );
