<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Joas Schilling <coding@schilljs.com>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files\Tests\Service;

use OC\Tags;
use OCA\Files\Service\TagService;
use OCP\Activity\IManager;
use OCP\IUser;
use OCP\IUserSession;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class TagServiceTest
 *
 * @group DB
 *
 * @package OCA\Files
 */
class TagServiceTest extends \Test\TestCase {

	/**
	 * @var string
	 */
	private $user;

	/** @var IUserSession|\PHPUnit_Framework_MockObject_MockObject */
	private $userSession;

	/** @var IManager|\PHPUnit_Framework_MockObject_MockObject */
	private $activityManager;

	/**
	 * @var \OCP\Files\Folder
	 */
	private $root;

	/** @var EventDispatcherInterface|\PHPUnit_Framework_MockObject_MockObject */
	private $dispatcher;

	/**
	 * @var \OCA\Files\Service\TagService|\PHPUnit_Framework_MockObject_MockObject
	 */
	private $tagService;

	/**
	 * @var \OCP\ITags
	 */
	private $tagger;

	protected function setUp() {
		parent::setUp();
		$this->user = static::getUniqueID('user');
		$this->activityManager = $this->createMock(IManager::class);
		\OC::$server->getUserManager()->createUser($this->user, 'test');
		\OC_User::setUserId($this->user);
		\OC_Util::setupFS($this->user);
		$user = $this->createMock(IUser::class);
		/**
		 * @var \OCP\IUserSession
		 */
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->expects($this->any())
			->method('getUser')
			->withAnyParameters()
			->will($this->returnValue($user));

		$this->root = \OC::$server->getUserFolder();
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->tagger = \OC::$server->getTagManager()->load('files');
		$this->tagService = $this->getTagService(['addActivity']);
	}

	/**
	 * @param array $methods
	 * @return TagService|\PHPUnit_Framework_MockObject_MockObject
	 */
	protected function getTagService(array $methods = []) {
		return $this->getMockBuilder(TagService::class)
			->setConstructorArgs([
				$this->userSession,
				$this->activityManager,
				$this->tagger,
				$this->root,
				$this->dispatcher,
			])
			->setMethods($methods)
			->getMock();

	}

	protected function tearDown() {
		\OC_User::setUserId('');
		$user = \OC::$server->getUserManager()->get($this->user);
		if ($user !== null) { $user->delete(); }
	}

	public function testUpdateFileTags() {
		$tag1 = 'tag1';
		$tag2 = 'tag2';

		$this->tagService->expects($this->never())
			->method('addActivity');

		$subdir = $this->root->newFolder('subdir');
		$testFile = $subdir->newFile('test.txt');
		$testFile->putContent('test contents');

		$fileId = $testFile->getId();

		// set tags
		$this->tagService->updateFileTags('subdir/test.txt', array($tag1, $tag2));

		$this->assertEquals(array($fileId), $this->tagger->getIdsForTag($tag1));
		$this->assertEquals(array($fileId), $this->tagger->getIdsForTag($tag2));

		// remove tag
		$this->tagService->updateFileTags('subdir/test.txt', array($tag2));
		$this->assertEquals(array(), $this->tagger->getIdsForTag($tag1));
		$this->assertEquals(array($fileId), $this->tagger->getIdsForTag($tag2));

		// clear tags
		$this->tagService->updateFileTags('subdir/test.txt', array());
		$this->assertEquals(array(), $this->tagger->getIdsForTag($tag1));
		$this->assertEquals(array(), $this->tagger->getIdsForTag($tag2));

		// non-existing file
		$caught = false;
		try {
			$this->tagService->updateFileTags('subdir/unexist.txt', array($tag1));
		} catch (\OCP\Files\NotFoundException $e) {
			$caught = true;
		}
		$this->assertTrue($caught);

		$subdir->delete();
	}

	public function testFavoriteActivity() {

		$subdir = $this->root->newFolder('subdir');
		$file = $subdir->newFile('test.txt');

		$this->tagService->expects($this->exactly(2))
			->method('addActivity')
			->withConsecutive(
				[true, $file->getId(), 'subdir/test.txt'],
				[false, $file->getId(), 'subdir/test.txt']
			);

		// set tags
		$this->tagService->updateFileTags('subdir/test.txt', [Tags::TAG_FAVORITE]);

		// remove tag
		$this->tagService->updateFileTags('subdir/test.txt', []);


		$subdir->delete();
	}
}
