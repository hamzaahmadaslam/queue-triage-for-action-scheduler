/* Copies the support report to the clipboard. */
( function () {
	var button = document.getElementById( 'queue-triage-copy' );
	if ( ! button ) {
		return;
	}
	var status = document.getElementById( 'queue-triage-copy-status' );
	var strings = window.queueTriage || { copied: 'Copied', failed: 'Select the text and copy it' };

	button.addEventListener( 'click', function () {
		var field = document.getElementById( button.getAttribute( 'data-target' ) );
		if ( ! field ) {
			return;
		}
		var done = function () {
			status.textContent = strings.copied;
		};
		var fallback = function () {
			field.focus();
			field.select();
			try {
				if ( document.execCommand( 'copy' ) ) {
					done();
					return;
				}
			} catch ( e ) {}
			status.textContent = strings.failed;
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( field.value ).then( done, fallback );
		} else {
			fallback();
		}
	} );
} )();
