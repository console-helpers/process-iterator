<?php
/**
 * This file is part of the ProcessIterator library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/process-iterator
 */

namespace Tests\ConsoleHelpers\ProcessIterator;


use ConsoleHelpers\ProcessIterator\ProcessIterator;
use Symfony\Component\Process\Process;

class ProcessIteratorTest extends \PHPUnit_Framework_TestCase
{

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage The $processes argument must be an array of non-running instances of "\Symfony\Component\Process\Process" class.
	 */
	public function testCreateWithNonProcess()
	{
		new ProcessIterator(array('test'));
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage The $processes argument must be an array of non-running instances of "\Symfony\Component\Process\Process" class.
	 */
	public function testCreateWithRunningProcess()
	{
		$process = new Process('echo 1');
		$process->start();

		new ProcessIterator(array($process));
	}

}
