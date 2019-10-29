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

class TransferOwnership extends QueuedJob {

	/** @var IUserManager $userManager */
	private $userManager;

	/** @var IManager */
	private $shareManager;

	/** @var IMountManager */
	private $mountManager;

	/** @var FileInfo[] */
	private $allFiles = [];

	/** @var FileInfo[] */
	private $encryptedFiles = [];

	/** @var IShare[] */
	private $shares = [];

	/** @var string */
	private $sourceUser;

	/** @var string */
	private $destinationUser;

	/** @var string */
	private $sourcePath;

	/** @var string */
	private $finalTarget;

	/** @var ILogger */
	private $logger;
	/** @var EncryptionManager */
	private $encryptionManager;

	public function __construct(ITimeFactory $timeFactory,
								IUserManager $userManager,
								IManager $shareManager,
								IMountManager $mountManager,
								ILogger $logger,
								EncryptionManager $encryptionManager) {
		parent::__construct($timeFactory);

		$this->userManager = $userManager;
		$this->shareManager = $shareManager;
		$this->mountManager = $mountManager;
		$this->logger = $logger;
		$this->encryptionManager = $encryptionManager;
	}

	protected function run($argument) {
		$this->sourceUser = $argument['source-user'];
		$this->destinationUser = $argument['destination-user'];
		$path = $argument['path'];
		$sourceUserObject = $this->userManager->get($this->sourceUser);
		$destinationUserObject = $this->userManager->get($this->destinationUser);

		if (!$sourceUserObject instanceof IUser) {
			$this->logger->alert('Could not transfer ownership: Unknown source user ' . $this->sourceUser);
			//TODO send notification
			return 1;
		}

		if (!$destinationUserObject instanceof IUser) {
			$this->logger->alert("Unknown destination user $this->destinationUser");
			//TODO send notification
			return 1;
		}

		$sourcePathOption = ltrim($path, '/');
		$this->sourcePath = rtrim($this->sourceUser . '/files/' . $sourcePathOption, '/');

		// target user has to be ready
		if (!$this->encryptionManager->isReadyForUser($this->destinationUser)) {
			$this->logger->alert("The target user is not ready to accept files. The user has at least to be logged in once.");
			//TODO send notification
			return 2;
		}

		$date = date('Y-m-d H-i-s');
		$this->finalTarget = "$this->destinationUser/files/transferred from $this->sourceUser on $date";

		// setup filesystem
		Filesystem::initMountPoints($this->sourceUser);
		Filesystem::initMountPoints($this->destinationUser);

		$view = new View();
		if (!$view->is_dir($this->sourcePath)) {
			$this->logger->alert("Unknown path provided: $sourcePathOption");
			//TODO send notification
			return 1;
		}

		try {
			// analyse source folder
			$this->analyse();

			// collect all the shares
			$this->collectUsersShares();

			// transfer the files
			$this->transfer();

			// restore the shares
			$this->restoreShares();
		} catch (\Exception $e) {
			// Something went wrong
			//TODO: send notification
		}
	}

	private function walkFiles(View $view, $path, \Closure $callBack) {
		foreach ($view->getDirectoryContent($path) as $fileInfo) {
			if (!$callBack($fileInfo)) {
				return;
			}
			if ($fileInfo->getType() === FileInfo::TYPE_FOLDER) {
				$this->walkFiles($view, $fileInfo->getPath(), $callBack);
			}
		}
	}

	/**
	 * @throws \Exception
	 */
	protected function analyse() {
		$view = new View();
		$this->logger->info("Analysing files of $this->sourceUser ...");

		$this->walkFiles($view, $this->sourcePath,
			function (FileInfo $fileInfo) {
				if ($fileInfo->getType() === FileInfo::TYPE_FOLDER) {
					// only analyze into folders from main storage,
					if (!$fileInfo->getStorage()->instanceOfStorage(IHomeStorage::class)) {
						return false;
					}
					return true;
				}
				$this->allFiles[] = $fileInfo;
				if ($fileInfo->isEncrypted()) {
					$this->encryptedFiles[] = $fileInfo;
				}
				return true;
			});

		// no file is allowed to be encrypted
		if (!empty($this->encryptedFiles)) {
			$this->logger->info("Some files are encrypted - please decrypt them first");
			foreach($this->encryptedFiles as $encryptedFile) {
				/** @var FileInfo $encryptedFile */
				$this->logger->debug('encrypted file: ' . $encryptedFile->getPath());
			}
			throw new \Exception('Execution terminated.');
		}
	}

	private function collectUsersShares() {
		$this->logger->info("Collecting all share information for files and folder of $this->sourceUser ...");

		foreach([\OCP\Share::SHARE_TYPE_GROUP, \OCP\Share::SHARE_TYPE_USER, \OCP\Share::SHARE_TYPE_LINK, \OCP\Share::SHARE_TYPE_REMOTE, \OCP\Share::SHARE_TYPE_ROOM] as $shareType) {
			$offset = 0;
			while (true) {
				$sharePage = $this->shareManager->getSharesBy($this->sourceUser, $shareType, null, true, 50, $offset);
				if (empty($sharePage)) {
					break;
				}
				$this->shares = array_merge($this->shares, $sharePage);
				$offset += 50;
			}
		}
	}

	protected function transfer() {
		$view = new View();
		$this->logger->info("Transferring files to $this->finalTarget ...");

		// This change will help user to transfer the folder specified using --path option.
		// Else only the content inside folder is transferred which is not correct.
		if($this->sourcePath !== "$this->sourceUser/files") {
			$view->mkdir($this->finalTarget);
			$this->finalTarget = $this->finalTarget . '/' . basename($this->sourcePath);
		}
		$view->rename($this->sourcePath, $this->finalTarget);
		if (!is_dir("$this->sourceUser/files")) {
			// because the files folder is moved away we need to recreate it
			$view->mkdir("$this->sourceUser/files");
		}
	}

	private function restoreShares() {
		$this->logger->info("Restoring shares ...");

		foreach($this->shares as $share) {
			try {
				if ($share->getShareType() === \OCP\Share::SHARE_TYPE_USER &&
					$share->getSharedWith() === $this->destinationUser) {
					// Unmount the shares before deleting, so we don't try to get the storage later on.
					$shareMountPoint = $this->mountManager->find('/' . $this->destinationUser . '/files' . $share->getTarget());
					if ($shareMountPoint) {
						$this->mountManager->removeMount($shareMountPoint->getMountPoint());
					}
					$this->shareManager->deleteShare($share);
				} else {
					if ($share->getShareOwner() === $this->sourceUser) {
						$share->setShareOwner($this->destinationUser);
					}
					if ($share->getSharedBy() === $this->sourceUser) {
						$share->setSharedBy($this->destinationUser);
					}

					$this->shareManager->updateShare($share);
				}
			} catch (\OCP\Files\NotFoundException $e) {
				$this->logger->info('Share with id ' . $share->getId() . ' points at deleted file, skipping');
			} catch (\Exception $e) {
				$this->logger->info('Could not restore share with id ' . $share->getId() . ':' . $e->getTraceAsString());
			}
		}
	}
}
