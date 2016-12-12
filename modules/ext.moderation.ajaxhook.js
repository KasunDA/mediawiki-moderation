/*
	This code handles VisualEditor and other API-based JavaScript editors.

	A. Why is this needed?

	1) api.php?action=edit returns error when we abort onPageContentSave hook,
	so VisualEditor panics "The edit wasn't saved! Unknown error!".
	2) when JavaScript-based editors load the main <textarea>
	(their analogue of #wpTextbox1 in index.php?action=edit),
	they ignore Preload hooks like onEditFormInitialText, so if this user
	has a pending change, it won't be preloaded at all.

	B. Why such an unusual implementation?

	It turns out, neither API (in MediaWiki core) nor VisualEditor provide
	any means of altering the API response via a PHP hook.
	Until this solution was found, it looked impossible to make Moderation
	work with VisualEditor by any changes on the Moderation side.

	C. How does this solution work?

	We intercept all AJAX responses (sic!), and if we determine that this
	response is either (1) or (2) (see above), WE FAKE THE AJAX RESPONSE.

	In (1), we replace error with "edit saved successfully!"
	In (2), we inject preloaded text from showUnmoderatedEdit().

	See also: [ext.moderation.preload.visualeditor.js].
*/

( function ( mw, $ ) {
	'use strict';

	/* Make an API response for action=edit */
	function successEdit() {
		var ret = {},
			timestamp = "2016-12-08T12:33:23Z"; /* TODO: recalculate */

		ret.edit = {
			"result": "Success", /* Uppercase */
			"pageid": mw.config.get( 'wgArticleId' ),
			"title": mw.config.get( 'wgTitle' ),
			"contentmodel": mw.config.get( 'wgPageContentModel' ),
			"oldrevid": mw.config.get( 'wgRevisionId' ),
			"newrevid": 0, /* NOTE: change if this causes problems in any API-based editors */
			"newtimestamp": timestamp
		};

		if(ret.edit.pageid) {
			ret.edit.new = "";
		}

		return ret;
	}

	/* Make an API response for action=visualeditoredit */
	function successVEEdit() {
		var ret = {};

		ret.visualeditoredit = {
			"result": "success", /* Lowercase */

			/* rewrevid is "undefined" on purpose:
				in this case, ve.init.mw.DesktopArticleTarget.js doesn't do much,
				most importantly - doesn't fire 'postEdit' hook
				(which is good, because we need to show another text there).
				We invoke postEdit ourselves in [ext.moderation.notify.js].
			*/
			"newrevid": undefined,

			/* Provide things we already know */
			"isRedirect": mw.config.get( 'wgIsRedirect' ),

			/* Fields which are ok to leave empty
				(because VisualEditor doesn't use them if they are empty)
			*/
			"displayTitleHtml": "",
			"lastModified": "",
			"contentSub": "",
			"modules": "",
			"jsconfigvars": "",

			/* We don't really care about VisualEditor receiving this HTML.
				It simply displays it on the page without reloading it.

				Certainly not worth doing a synchronous XHR request
				(which is long deprecated and may be ignored by modern browsers)

				We can do this later in postEdit hook.
				See ApiQueryModerationPreload.
			*/
			"content": "<div id='moderation-ajaxhook'></div>",
			"categorieshtml": "<div id='catlinks'></div>",
		};

		/*
			VisualEditor may choose not to reload the page,
			but instead to display content/categorieshtml without reload.

			We must detect the appearance of #moderation-ajaxhook,
			and then call mw.moderationNotifyQueued().
		*/
		mw.hook( 'wikipage.content' ).add( function( $content ) {
			if ( $content.find( '#moderation-ajaxhook' ).length != 0 ) {
				mw.loader.using('ext.moderation.notify', function() {
					mw.moderationNotifyQueued( {
						/* Force re-rendering of #mw-content-text */
						showParsed: true
					} );
				});
			}
		} );

		return ret;
	}

	/* This hook is called on "readystatechange" event of every (!) XMLHttpRequest.
		It runs in "capture mode" (will be called before any other
		readystatechange callbacks, unless they are also in capture mode).
	*/
	function on_readystatechange_global( query ) {

		if ( this.readyState != 4 ) {
			return; /* Not ready yet */
		}

		/* Get JSON response from API */
		var ret;
		try {
			ret = JSON.parse( this.responseText );
		}
		catch(e) {
			return; /* Not a JSON, nothing to overwrite */
		}

		/* Check whether we need to overwrite this AJAX response or not */
		if ( ret.error && ret.error.info.indexOf( 'moderation-edit-queued' ) != -1 ) {

			/*
				Error from api.php?action=edit: edit was queued for moderation.
				We must replace this response with "Edit saved successfully!".
			*/

			/* Fake a successful API response */
			if ( query.action == 'edit' ) {
				ret = successEdit();
			}
			else if ( query.action == 'visualeditoredit' ) {
				ret = successVEEdit();
			}
			else {
				return; /* Nothing to overwrite */
			}

			/* Set cookie for [ext.moderation.notify.js].
				It means "edit was just queued for moderation".
			*/
			$.cookie( 'modqueued', '1', { path: '/' } );

			/* Overwrite readonly fields in this XMLHttpRequest */
			Object.defineProperty( this, 'responseText', { writable: true } );
			Object.defineProperty( this, 'status', { writable: true } );
			this.responseText = JSON.stringify( ret );
			this.status = 200;
		}
	};

	/*
		Helper function used in XMLHttpRequest.prototype.send() below.
		Extracts all key/value pairs from the parameter of send(),
		e.g. { action: "edit", "title": "Testpage1", ... }.
	*/
	function parseXHRSendBody( sendBody ) {
		/* Get original request as array, e.g. { action: "edit", "title": "Testpage1", ... } */
		var query = {}, pair;
		if ( sendBody instanceof FormData )  {
			/* FormData: from "mw.api" with enforced multipart/form-data, used by VisualEditor */
			for ( pair of sendBody.entries() ) {
				query[pair[0]] = pair[1];
			}
		}
		else if( $.type( sendBody ) == 'string' ) {
			/* Querystring: from "mw.api" with default behavior, used by MobileFrontend, etc. */
			for ( pair of String.split( sendBody, '&' ) ) {
				var kv = pair.split('='),
					key = decodeURIComponent( kv[0] ),
					val = decodeURIComponent( kv[1] );
				query[key] = val;
			}
		}
		else {
			/* "mw.api" module uses only FormData or querystring,
				so we don't need to support other formats.
			*/
			return false; /* Couldn't obtain the original query */
		}

		return query;
	}

	/*
		Install on_readystatechange_global() callback which will be
		called for every XMLHttpRequest, regardless of who sent it.
	*/
	var oldSend = XMLHttpRequest.prototype.send;

	XMLHttpRequest.prototype.send = function( sendBody ) {
		/* Make parsed sendBody accessible in on_readystatechange_global() */
		var query = parseXHRSendBody( sendBody );
		if ( query ) {
			this.addEventListener( "readystatechange",
				on_readystatechange_global.bind( this, query ),
				true // capture mode
			);
		}

		oldSend.apply( this, arguments );
	};

}( mediaWiki, jQuery ) );
