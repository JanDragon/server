<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2019, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Files\BackgroundJob;

use OC\Files\Filesystem;
use OC\Files\View;
use OCA\Files\Service\OwnershipTransferService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Encryption\IManager as EncryptionManager;
use OCP\Files\FileInfo;
use OCP\Files\IHomeStorage;
use OCP\Files\Mount\IMountManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use function ltrim;

class TransferOwnership extends QueuedJob {

	/** @var IUserManager $userManager */
	private $userManager;

	/** @var OwnershipTransferService */
	private $transferService;

	/** @var ILogger */
	private $logger;

	public function __construct(ITimeFactory $timeFactory,
								IUserManager $userManager,
								OwnershipTransferService $transferService,
								ILogger $logger) {
		parent::__construct($timeFactory);

		$this->userManager = $userManager;
		$this->transferService = $transferService;
		$this->logger = $logger;
	}

	protected function run($argument) {
		$sourceUser = $argument['source-user'];
		$destinationUser = $argument['destination-user'];
		$path = $argument['path'];
		$sourceUserObject = $this->userManager->get($sourceUser);
		$destinationUserObject = $this->userManager->get($destinationUser);

		if (!$sourceUserObject instanceof IUser) {
			$this->logger->alert('Could not transfer ownership: Unknown source user ' . $sourceUser);
			//TODO send notification
			return;
		}

		if (!$destinationUserObject instanceof IUser) {
			$this->logger->alert("Unknown destination user $destinationUser");
			//TODO send notification
			return;
		}

		$this->transferService->transfer(
			$sourceUserObject,
			$destinationUserObject,
			ltrim($path, '/')
		);
	}

}
