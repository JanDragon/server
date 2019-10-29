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

namespace OCA\Files\Controller;

use OCA\Files\BackgroundJob\TransferOwnership;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\Notification\IManager as NotificationManager;

class TransferOwnershipController extends OCSController {

	/** @var string */
	private $userId;
	/** @var NotificationManager */
	private $notificationManager;
	/** @var ITimeFactory */
	private $timeFactory;
	/** @var IJobList */
	private $jobList;

	public function __construct(string $appName,
								IRequest $request,
								string $userId,
								NotificationManager $notificationManager,
								ITimeFactory $timeFactory,
								IJobList $jobList) {
		parent::__construct($appName, $request);

		$this->userId = $userId;
		$this->notificationManager = $notificationManager;
		$this->timeFactory = $timeFactory;
		$this->jobList = $jobList;
	}


	/**
	 * @NoAdminRequired
	 *
	 * TODO: more checks
	 */
	public function transfer(string $recipient, string $path): DataResponse {
		$notification = $this->notificationManager->createNotification();

		$notification->setUser($recipient)
			->setApp('files')
			->setDateTime($this->timeFactory->getDateTime())
			->setSubject('transferownershipRequest', [
				'sourceUser' => $this->userId,
				'targetUser' => $recipient,
				'path' => $path
			])
			->setObject('transfer', $this->userId . '::' . $path);

		$this->notificationManager->notify($notification);

		return new DataResponse([]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function accept(string $params): DataResponse {
		$data = json_decode(base64_decode($params), true);

		// TODO: Mark notification as done
		$this->jobList->add(TransferOwnership::class, [
			'source-user' => $data['sourceUser'],
			'destination-user' => $data['targetUser'],
			'path' => $data['path'],
		]);
	}

	public function reject(string $params): DataResponse {
		$data = json_decode(base64_decode($params), true);

		// TODO: Mark notification as done
		// TODO: Send notification to initiator of rejection
	}

}
