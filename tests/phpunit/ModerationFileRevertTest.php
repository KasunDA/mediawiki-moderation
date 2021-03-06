<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
	@file
	@brief Ensures that reverting image to old revision doesn't bypass Moderation.
*/

require_once( __DIR__ . "/framework/ModerationTestsuite.php" );

class ModerationTestFileRevert extends MediaWikiTestCase
{
	/**
		@brief Check that index.php?action=revert can't be used to bypass Moderation.
		@covers ModerationUploadHooks::ongetUserPermissionsErrors
	*/
	public function testFileRevert() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$req = $t->httpPost( wfScript( 'index' ), [
			'action' => 'revert'
		] );
		$t->html->loadFromReq( $req );

		$this->assertRegExp( '/\(moderation-revert-not-allowed\)/',
			$t->html->getMainText(),
			"testFileRevert(): Revert page doesn't contain (moderation-revert-not-allowed)" );
	}

	/**
		@brief Check that api.php?action=filerevert can't be used to bypass Moderation.
	*/
	public function testApiFileRevert() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$ret = $t->query( [
			'action' => 'filerevert',
			'filename' => 'whatever',
			'archivename' => 'whatever',
			'token' => null
		] );

		/* File revert shouldn't be allowed (this user is not automoderated) */
		$this->assertArrayHasKey( 'error', $ret );
		$this->assertContains( $ret['error']['code'], [
			'unknownerror', # MediaWiki 1.28 and older
			'moderation-revert-not-allowed' # MediaWiki 1.29+
		] );
		if ( $ret['error']['code'] == 'unknownerror' ) {
			$this->assertRegExp( '/moderation-revert-not-allowed/',
				$ret['error']['info'] );
		}
	}
}
