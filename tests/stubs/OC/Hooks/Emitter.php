<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * For Psalm only, never loaded at run time: OCP\Files\IRootFolder extends
 * this server interface, which the nextcloud/ocp package does not ship, so
 * without it every use of IRootFolder is a MissingDependency. The signatures
 * are the server's (lib/private/Hooks/Emitter.php, stable33).
 */

namespace OC\Hooks;

interface Emitter
{

//  other non-static methods

	/**
	 * @param  string  $scope
	 * @param  string  $method
	 *
	 * @return void
	 */
	public function listen( $scope, $method, callable $callback );

	/**
	 * @param  string|null  $scope
	 * @param  string|null  $method
	 *
	 * @return void
	 */
	public function removeListener( $scope = null, $method = null, ?callable $callback = null );
}
