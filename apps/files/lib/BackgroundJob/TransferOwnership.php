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

use OCA\Files\Db\TransferOwnership as Transfer;
use OCA\Files\Db\TransferOwnershipMapper;
use OCA\Files\Exception\TransferOwnershipException;
use OCA\Files\Service\OwnershipTransferService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as NotificationManager;
use function ltrim;

class TransferOwnership extends QueuedJob {

	/** @var IUserManager $userManager */
	private $userManager;

	/** @var OwnershipTransferService */
	private $transferService;

	/** @var ILogger */
	private $logger;

	/** @var NotificationManager */
	private $notificationManager;

	/** @var TransferOwnershipMapper */
	private $mapper;

	public function __construct(ITimeFactory $timeFactory,
								IUserManager $userManager,
								OwnershipTransferService $transferService,
								ILogger $logger,
								NotificationManager $notificationManager,
								TransferOwnershipMapper $mapper) {
		parent::__construct($timeFactory);

		$this->userManager = $userManager;
		$this->transferService = $transferService;
		$this->logger = $logger;
		$this->notificationManager = $notificationManager;
		$this->mapper = $mapper;
	}

	protected function run($argument) {
		$id = $argument['id'];

		$transfer = $this->mapper->getById($id);
		$sourceUser = $transfer->getSourceUser();
		$destinationUser = $transfer->getTargetUser();
		$path = $transfer->getPath();
		$sourceUserObject = $this->userManager->get($sourceUser);
		$destinationUserObject = $this->userManager->get($destinationUser);

		if (!$sourceUserObject instanceof IUser) {
			$this->logger->alert('Could not transfer ownership: Unknown source user ' . $sourceUser);
			$this->failedNotication($transfer);
			return;
		}

		if (!$destinationUserObject instanceof IUser) {
			$this->logger->alert("Unknown destination user $destinationUser");
			$this->failedNotication($transfer);
			return;
		}

		try {
			$this->transferService->transfer(
				$sourceUserObject,
				$destinationUserObject,
				ltrim($path, '/')
			);
		} catch (TransferOwnershipException $e) {
			$this->failedNotication($transfer);
			return;
		}

		$this->successNotification($transfer);
	}

	private function failedNotication(Transfer $transfer): void {
		// Send notification to source user
		$notification = $this->notificationManager->createNotification();
		$notification->setUser($transfer->getSourceUser())
			->setApp('files')
			->setDateTime($this->time->getDateTime())
			->setSubject('transferOwnershipFailedSource', [
				'sourceUser' => $transfer->getSourceUser(),
				'targetUser' => $transfer->getTargetUser(),
				'path' => $transfer->getPath(),
			])
			->setObject('transfer', (string)$transfer->getId());
		$this->notificationManager->notify($notification);

		// Send notification to source user
		$notification = $this->notificationManager->createNotification();
		$notification->setUser($transfer->getTargetUser())
			->setApp('files')
			->setDateTime($this->time->getDateTime())
			->setSubject('transferOwnershipFailedTarget', [
				'sourceUser' => $transfer->getSourceUser(),
				'targetUser' => $transfer->getTargetUser(),
				'path' => $transfer->getPath(),
			])
			->setObject('transfer', (string)$transfer->getId());
		$this->notificationManager->notify($notification);
	}

	private function successNotification(Transfer $transfer): void {
		// Send notification to source user
		$notification = $this->notificationManager->createNotification();
		$notification->setUser($transfer->getSourceUser())
			->setApp('files')
			->setDateTime($this->time->getDateTime())
			->setSubject('transferOwnershipDoneSource', [
				'sourceUser' => $transfer->getSourceUser(),
				'targetUser' => $transfer->getTargetUser(),
				'path' => $transfer->getPath(),
			])
			->setObject('transfer', (string)$transfer->getId());
		$this->notificationManager->notify($notification);

		// Send notification to source user
		$notification = $this->notificationManager->createNotification();
		$notification->setUser($transfer->getTargetUser())
			->setApp('files')
			->setDateTime($this->time->getDateTime())
			->setSubject('transferOwnershipDoneTarget', [
				'sourceUser' => $transfer->getSourceUser(),
				'targetUser' => $transfer->getTargetUser(),
				'path' => $transfer->getPath(),
			])
			->setObject('transfer', (string)$transfer->getId());
		$this->notificationManager->notify($notification);
	}
}
