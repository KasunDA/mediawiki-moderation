<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017-2018 Edward Chernenko.

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
	@brief Performs database query that is not rolled back by MWException.
*/

class RollbackResistantQuery {

	protected static $initialized = false; /**< Becomes true after initialize() */
	protected static $performedQueries = []; /**< array of all created RollbackResistantQuery objects */

	protected $dbw; /**< IDatabase */
	protected $methodName; /**< Either 'insert' or 'update' */
	protected $args; /**< Array of parameters to be passed to $dbw->insert() or $dbw->update() */

	/**
		@brief Perform $dbw->insert() that won't be undone by $dbw->rollback().
		@param $dbw Database object.
		@param $args Arguments of $dbw->insert() call.
	*/
	public static function insert( IDatabase $dbw, array $args ) {
		new self( 'insert', $dbw, $args );
	}

	/**
		@brief Perform $dbw->update() that won't be undone by $dbw->rollback().
		@param $dbw Database object.
		@param $args Arguments of $dbw->update() call.
	*/
	public static function update( IDatabase $dbw, array $args ) {
		new self( 'update', $dbw, $args );
	}

	/**
		@brief Create and immediately execute a new query.
		@param $methodName One of the following strings: 'insert', 'update'.
		@param $dbw Database object.
		@param $args Arguments of $dbw->update() call.
	*/
	protected function __construct( $methodName, IDatabase $dbw, array $args ) {
		if ( $methodName != 'insert' && $methodName != 'update' ) {
			throw new MWException( 'Unknown action, only insert or update are supported' );
		}

		$this->dbw = $dbw;
		$this->methodName = $methodName;
		$this->args = $args;

		/* Install the hooks (only happens once) */
		$this->initialize();

		/* The query is invoked immediately.
			If rollback() happens, the query will be repeated. */
		$this->executeNow();

		self::$performedQueries[] = $this; /* All $performedQueries will be re-run after rollback() */
	}

	/**
		@brief Install hooks that can detect a database rollback.
	*/
	protected function initialize() {
		if ( !self::$initialized ) {
			self::$initialized = true;

			$query = $this;

			/* MediaWiki 1.28+ calls TransactionListener callback after rollback() */
			if ( defined( 'Database::TRIGGER_ROLLBACK' ) ) {
				$this->dbw->setTransactionListener( 'moderation-on-rollback', function( $trigger ) use ( $query ) {
					if ( $trigger == Database::TRIGGER_ROLLBACK ) {
						$query->onRollback();
					}
				}, __METHOD__ );
			}
			else {
				/* MediaWiki 1.27 doesn't call any callbacks after rollback(),
					but we can at least detect MWException - what usually causes the rolback
					in MWExceptionHandler::handleException() */

				Hooks::register( 'LogException', function( $e, $suppressed ) use ( $query ) {
					if (
						!( $e instanceof DBError ) && /* DBError likely means that rollback failed */
						!( $e instanceof JobQueueError ) /* Non-fatal error in JobQueue, doesn't cause rollback */
					) {
						$query->onRollback();
					}

					return true;
				} );
			}
		}
	}

	/**
		@brief Re-run all $performedQueries. Called after the database rollback.
	*/
	protected function onRollback() {
		foreach ( self::$performedQueries as $query ) {
			$query->executeNow();
		}

		self::$performedQueries = [];
	}

	/**
		@brief Run the scheduled query immediately.
	*/
	protected function executeNow() {
		call_user_func_array(
			[ $this->dbw, $this->methodName ],
			$this->args
		);
	}
}
