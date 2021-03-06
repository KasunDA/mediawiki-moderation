'use strict';
const Page = require( './page' );

class CreateAccountPage extends Page {

	get username() { return browser.element( '#wpName2' ); }
	get password() { return browser.element( '#wpPassword2' ); }
	get confirmPassword() { return browser.element( '#wpRetype' ); }
	get create() { return browser.element( '#wpCreateaccount' ); }
	get heading() { return browser.element( '#firstHeading' ); }

	open() {
		super.open( 'Special:CreateAccount' );
		this.username.waitForVisible(); /* In Edge, browser.url() may return before DOM is ready */
	}

	createAccount( username, password ) {
		this.open();
		this.username.setValue( username );
		this.password.setValue( password );
		this.confirmPassword.setValue( password );
		this.submitAndWait( this.create );
	}

}
module.exports = new CreateAccountPage();
