<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2018 Edward Chernenko.

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
	@brief Implements modaction=approve(all) on [[Special:Moderation]].
*/

class ModerationActionApprove extends ModerationAction {

	public function execute() {
		$ret = ( $this->actionName == 'approve' ) ?
			$this->executeApproveOne() :
			$this->executeApproveAll();

		if ( $ret['approved'] ) {
			/* Clear the cache of "Most recent mod_timestamp of pending edit"
				- could have changed */
			ModerationNotifyModerator::invalidatePendingTime();
		}

		return $ret;
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$out->addWikiMsg(
			'moderation-approved-ok',
			count( $result['approved'] )
		);

		if ( !empty( $result['failed'] ) ) {
			$out->addWikiMsg(
				'moderation-approved-errors',
				count( $result['failed'] )
			);
		}
	}

	public function executeApproveOne() {
		$this->approveEditById( $this->id );
		return [
			'approved' => [ $this->id ]
		];
	}

	public function executeApproveAll() {
		$userpage = $this->getUserpageOfPerformer();
		if ( !$userpage ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$dbw = wfGetDB( DB_MASTER ); # Need latest data without lag
		$res = $dbw->select( 'moderation',
			[ 'mod_id AS id' ],
			[
				'mod_user_text' => $userpage->getText(),
				'mod_rejected' => 0, # Previously rejected edits are not approved by "Approve all"
				'mod_conflict' => 0 # No previously detected conflicts (they need manual merging).
			],
			__METHOD__,
			[
				# Images are approved first.
				# Otherwise the page can be rendered with the
				# image redlink, because the image didn't exist
				# when the edit to this page was approved.
				'ORDER BY' => 'mod_stash_key IS NULL',
				'USE INDEX' => 'moderation_approveall'
			]
		);
		if ( !$res || $res->numRows() == 0 ) {
			throw new ModerationError( 'moderation-nothing-to-approveall' );
		}

		$approved = [];
		$failed = [];
		foreach ( $res as $row ) {
			try {
				$this->approveEditById( $row->id );
				$approved[$row->id] = '';
			} catch ( ModerationError $e ) {
				$msg = $e->status->getMessage();
				$failed[$row->id] = [
					'code' => $msg->getKey(),
					'info' => $msg->plain()
				];
			}
		}

		if ( $approved ) {
			$logEntry = new ManualLogEntry( 'moderation', 'approveall' );
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( $userpage );
			$logEntry->setParameters( [ '4::count' => count( $approved ) ] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}

		return [
			'approved' => $approved,
			'failed' => $failed
		];
	}

	function approveEditById( $id ) {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			[
				'mod_id AS id',
				'mod_timestamp AS timestamp',
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_cur_id AS cur_id',
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_comment AS comment',
				'mod_minor AS minor',
				'mod_bot AS bot',
				'mod_last_oldid AS last_oldid',
				'mod_ip AS ip',
				'mod_header_xff AS header_xff',
				'mod_header_ua AS header_ua',
				'mod_text AS text',
				'mod_merged_revid AS merged_revid',
				'mod_rejected AS rejected',
				'mod_stash_key AS stash_key'
			],
			[ 'mod_id' => $id ],
			__METHOD__
		);

		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		if ( $row->merged_revid ) {
			throw new ModerationError( 'moderation-already-merged' );
		}

		if ( $row->rejected && $row->timestamp < SpecialModeration::getEarliestReapprovableTimestamp() ) {
			throw new ModerationError( 'moderation-rejected-long-ago' );
		}

		# Disable moderation hook (ModerationEditHooks::onPageContentSave),
		# so that it won't queue this edit again.
		ModerationCanSkip::enterApproveMode();

		# Prepare everything
		$title = Title::makeTitle( $row->namespace, $row->title );
		$model = $title->getContentModel();

		$user = $row->user ?
			User::newFromId( $row->user ) :
			User::newFromName( $row->user_text, false );

		/* User could have been recently renamed or deleted.
			Make sure we have the correct data. */
		$user->load( User::READ_LATEST );

		$displayName = $user->getName();
		if ( $user->getId() == 0 && $row->user != 0 ) {
			/* User was deleted,
				e.g. via [maintenance/removeUnusedAccounts.php] */
			$displayName = $row->user_text;
		}

		$flags = EDIT_DEFER_UPDATES | EDIT_AUTOSUMMARY;
		if ( $row->bot && $user->isAllowed( 'bot' ) ) {
			$flags |= EDIT_FORCE_BOT;
		}
		if ( $row->minor ) { # doEditContent() checks the right
			$flags |= EDIT_MINOR;
		}

		# Install hooks which affect postedit behavior of doEditContent().
		ModerationApproveHook::install( $title, $user, [
			# For CheckUser extension to work properly, IP, XFF and UA
			# should be set to the correct values for the original user
			# (not from the moderator)
			'ip' => $row->ip,
			'xff' => $row->header_xff,
			'ua' => $row->header_ua,

			'revisionUpdate' => [
				# Here we set the timestamp of this edit to $row->timestamp
				# (this is needed because doEditContent() always uses current timestamp).
				#
				# NOTE: timestamp in recentchanges table is not updated on purpose:
				# users would want to see new edits as they appear,
				# without the edits surprisingly appearing somewhere in the past.
				'rev_timestamp' => $dbw->timestamp( $row->timestamp ),

				# performUpload() mistakenly tags image reuploads as made by moderator (rather than $user).
				# Let's fix this here.
				'rev_user' => $user->getId(),
				'rev_user_text' => $displayName
			]
		] );

		$status = Status::newGood();
		if ( $row->stash_key ) {
			# This is the upload from stash.

			$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash( $user );
			$upload = new UploadFromStash( $user, $stash );

			try {
				$upload->initialize( $row->stash_key, $title->getText() );
			} catch ( UploadStashFileNotFoundException $e ) {
				throw new ModerationError( 'moderation-missing-stashed-image' );
			}

			$status = $upload->performUpload( $row->comment, $row->text, 0, $user );
		} else {
			# This is normal edit (not an upload).
			$new_content = ContentHandler::makeContent( $row->text, null, $model );

			$page = new WikiPage( $title );
			if ( !$page->exists() ) {
				# New page
				$status = $page->doEditContent(
					$new_content,
					$row->comment,
					$flags,
					false,
					$user
				);
			} else {
				# Existing page
				$latest = $page->getLatest();

				if ( $latest == $row->last_oldid ) {
					# Page hasn't changed since this edit was queued for moderation.
					$status = $page->doEditContent(
						$new_content,
						$row->comment,
						$flags,
						$row->last_oldid,
						$user
					);
				} else {
					# Page has changed!
					# Let's attempt merging, as MediaWiki does in private EditPage::mergeChangesIntoContent().

					$base_content = $row->last_oldid ?
						Revision::newFromId( $row->last_oldid )->getContent( Revision::RAW ) :
						ContentHandler::makeContent( '', null, $model );

					$latest_content = Revision::newFromId( $latest )->getContent( Revision::RAW );

					$handler = ContentHandler::getForModelID( $base_content->getModel() );
					$merged_content = $handler->merge3( $base_content, $new_content, $latest_content );

					if ( $merged_content ) {
						$status = $page->doEditContent(
							$merged_content,
							$row->comment,
							$flags,
							$latest, # Because $merged_content goes after $latest
							$user
						);
					} else {
						$dbw = wfGetDB( DB_MASTER );
						$dbw->update( 'moderation',
							[ 'mod_conflict' => 1 ],
							[ 'mod_id' => $id ],
							__METHOD__
						);
						$dbw->commit( __METHOD__ );

						throw new ModerationError( 'moderation-edit-conflict' );
					}
				}
			}
		}

		if ( !$status->isGood() ) {
			throw new ModerationError( $status->getMessage() );
		}

		$logEntry = new ManualLogEntry( 'moderation', 'approve' );
		$logEntry->setPerformer( $this->moderator );
		$logEntry->setTarget( $title );
		$logEntry->setParameters( [ 'revid' => ModerationApproveHook::getLastRevId() ] );
		$logid = $logEntry->insert();
		$logEntry->publish( $logid );

		# Approved edits are removed from "moderation" table,
		# because they already exist in page history, recentchanges etc.

		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'moderation', [ 'mod_id' => $id ], __METHOD__ );
	}
}
