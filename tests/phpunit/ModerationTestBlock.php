<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015 Edward Chernenko.

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
	@brief Verifies that modaction=(un)block works as expected.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationActionBlock
*/
class ModerationTestBlock extends MediaWikiTestCase
{
	public function testBlock() {
		$t = new ModerationTestsuite();
		$entry = $t->getSampleEntry('Test page 1');

		$this->assertNotNull($entry->blockLink,
			"testBlock(): Block link not found for non-blocked user");
		$this->assertNull($entry->unblockLink,
			"testBlock(): Unblock link found for non-blocked user");

		$req = $t->makeHttpRequest($entry->blockLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		# Now that the user is blocked, try to edit
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit('Test page 2');

		$t->fetchSpecial();
		$this->assertCount(0, $t->new_entries,
			"testBlock(): Something was added into Pending folder when queueing an edit from spammer");
		$this->assertCount(0, $t->deleted_entries,
			"testBlock(): Something was deleted from Pending folder when queueing an edit from spammer");

		$t->fetchSpecial('spam');
		$this->assertCount(1, $t->new_entries,
			"testBlock(): One edit from spammer was queued for moderation, but number of added entries in Spam folder isn't 1");
		$this->assertCount(0, $t->deleted_entries,
			"testBlock(): Something was deleted from Spam folder during the queueing");

		$entry = $t->new_entries[0];
		$this->assertEquals($t->lastEdit['User'], $entry->user);
		$this->assertEquals($t->lastEdit['Title'], $entry->title);

		$this->assertFalse($entry->rejected_batch,
			"testBlock(): Edit rejected automatically has rejected_batch flag ON");
		$this->assertTrue($entry->rejected_auto,
			"testBlock(): Edit rejected automatically edit has rejected_auto flag OFF");

		$this->assertNull($entry->blockLink,
			"testBlock(): Block link found for blocked user");
		$this->assertNotNull($entry->unblockLink,
			"testBlock(): Unblock link not found for blocked user");

		$this->assertNull($entry->rejectLink,
			"testBlock(): Reject link found for already rejected edit");
		$this->assertNull($entry->rejectAllLink,
			"testBlock(): RejectAll link found for already rejected edit");
		$this->assertNull($entry->approveAllLink,
			"testBlock(): ApproveAll link found for already rejected edit");

		$req = $t->makeHttpRequest($entry->unblockLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		# Checking that the user is no longer considered a spammer...
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit('Test page 3');

		$t->fetchSpecial('spam');
		$this->assertCount(0, $t->new_entries,
			"testBlock(): Something was added into Spam folder when queueing an edit from non-spammer");
		$this->assertCount(0, $t->deleted_entries,
			"testBlock(): Something was deleted from Spam folder when queueing an edit from non-spammer");

		$t->fetchSpecial();

		$this->assertCount(1, $t->new_entries,
			"testBlock(): One edit from non-spammer was queued for moderation, but number of added entries in Pending folder isn't 1");
		$this->assertCount(0, $t->deleted_entries,
			"testBlock(): Something was deleted from Pending folder when queueing an edit from non-spammer");

		$entry = $t->new_entries[0];
		$this->assertEquals($t->lastEdit['User'], $entry->user);
		$this->assertEquals($t->lastEdit['Title'], $entry->title);

		$this->assertNotNull($entry->blockLink,
			"testBlock(): Block link not found for no-longer-blocked user");
		$this->assertNull($entry->unblockLink,
			"testBlock(): Unblock link found for no-longer-blocked user");
	}
}
