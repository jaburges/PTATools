/* "Find your school's PTA": the directory page is opened with ?pta=<name>. */
( function () {
	var query = new URLSearchParams( window.location.search ).get( 'pta' );
	if ( ! query ) {
		return;
	}
	var content = document.querySelector( '.wp-block-post-content, .entry-content' );
	if ( ! content ) {
		return;
	}
	var words = query
		.toLowerCase()
		.replace( /\b(ptsa|pta|school|elementary|middle|high)\b/g, ' ' )
		.split( /\s+/ )
		.filter( Boolean );
	if ( ! words.length ) {
		return;
	}

	var walker = document.createTreeWalker( content, NodeFilter.SHOW_TEXT );
	var hits = [];
	while ( walker.nextNode() ) {
		var text = walker.currentNode.nodeValue.toLowerCase();
		if ( words.every( function ( w ) { return text.indexOf( w ) !== -1; } ) ) {
			hits.push( walker.currentNode );
		}
	}

	var marks = hits.map( function ( node ) {
		var mark = document.createElement( 'mark' );
		mark.className = 'lw-found';
		node.parentNode.insertBefore( mark, node );
		mark.appendChild( node );
		return mark;
	} );

	var note = document.createElement( 'p' );
	note.className = 'lw-find-note';
	note.setAttribute( 'role', 'status' );
	if ( marks.length ) {
		note.textContent = marks.length === 1 ? 'Highlighted the match for “' + query + '”.' : marks.length + ' matches for “' + query + '” are highlighted.';
		marks[ 0 ].scrollIntoView( { block: 'center' } );
	} else {
		note.textContent = 'No PTA matched “' + query + '”. Here is the full list.';
	}
	content.insertBefore( note, content.firstChild );
} )();
