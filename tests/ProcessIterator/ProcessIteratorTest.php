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
		$process = new Process('sleep 1');
		$process->start();

		new ProcessIterator(array($process));
	}

	public function testProcessFailureIsIgnoredWithoutMustRun()
	{
		/** @var Process[] $processes */
		$processes = array(
			new Process('exit 64'),
			new Process('exit 0'),
		);

		$iterator = new ProcessIterator($processes);
		$exit_codes = array();

		foreach ( $iterator as $process ) {
			$this->assertNull($iterator->getProcessException(), 'No exception on process failure.');
			$exit_codes[] = $process->getExitCode();
		}

		$this->assertCount(count($processes), $exit_codes, 'All processes were executed.');

		// Order of results isn't guaranteed, because execution happens in parallel.
		$this->assertContains(0, $exit_codes, 'The successful process was executed.');
		$this->assertContains(64, $exit_codes, 'The failed process was executed.');
	}

	public function testProcessFailureIsRecordedWithMustRun()
	{
		/** @var Process[] $processes */
		$processes = array(
			new Process('exit 64'),
			new Process('exit 0'),
		);

		$iterator = new ProcessIterator($processes, true);
		$processes_executed = 0;

		foreach ( $iterator as $index => $process ) {
			if ( $index === 0 ) {
				$this->assertEquals(64, $process->getExitCode());
				$this->assertInstanceOf(
					'Symfony\Component\Process\Exception\ProcessFailedException',
					$iterator->getProcessException()
				);
			}
			else {
				$this->assertEquals(0, $process->getExitCode());
				$this->assertNull($iterator->getProcessException(), 'No exception on process failure.');
			}

			$processes_executed++;
		}

		$this->assertEquals(count($processes), $processes_executed, 'All processes were executed.');
	}

	public function testNoProcessFailuresWithMustRun()
	{
		/** @var Process[] $processes */
		$processes = array(
			new Process('echo "A"'),
			new Process('echo "B"'),
		);

		$iterator = new ProcessIterator($processes, true);
		$processes_executed = 0;

		foreach ( $iterator as $index => $process ) {
			$this->assertNull($iterator->getProcessException(), 'No exception on process success.');
			$processes_executed++;
		}

		$this->assertEquals(count($processes), $processes_executed, 'All processes were executed.');
	}

	/**
	 * @medium
	 */
	public function testRunAll()
	{
		/** @var Process[] $processes */
		$processes = array(
			new Process('echo 1; sleep 1'),
			new Process('echo 2; sleep 1'),
			new Process('echo 3; sleep 1'),
		);

		$iterator = new ProcessIterator($processes);

		$start = microtime(true);
		$this->assertSame($iterator, $iterator->runAll());
		$duration = microtime(true) - $start;

		$this->assertTrue($duration < count($processes) * 1, 'Processes did run in parallel.');

		foreach ( $processes as $index => $process ) {
			$this->assertEquals($index + 1, trim($process->getOutput()), 'Process was executed.');
		}
	}

	/**
	 * @dataProvider addProcessSuccessDataProvider
	 */
	public function testAddProcessSuccess($in_key1, $out_key1, $output1, $in_key2, $out_key2, $output2)
	{
		$process1 = new Process('echo "' . $output1 . '"');
		$process2 = new Process('echo "' . $output2 . '"');

		$processes = isset($in_key1) ? array($in_key1 => $process1) : array($process1);
		$iterator = new ProcessIterator($processes);
		$iterator->limit(2);

		$results = array();

		foreach ( $iterator as $index => $process ) {
			if ( $process === $process1 ) {
				$this->assertSame($iterator, $iterator->addProcess($process2, $in_key2));
			}

			$results[$index] = trim($process->getOutput());
		}

		$this->assertCount(2, $results, 'Process was added during iteration.');
		$this->assertArrayHasKey($out_key1, $results, 'Process 1 result present.');
		$this->assertEquals($output1, $results[$out_key1], 'Process 1 result correct.');
		$this->assertArrayHasKey($out_key2, $results, 'Process 2 result present.');
		$this->assertEquals($output2, $results[$out_key2], 'Process 2 result correct.');
	}

	public function addProcessSuccessDataProvider()
	{
		return array(
			'without keys' => array(null, 0, 'A', null, 1, 'B'),
			'with keys' => array('K1', 'K1', 'A', 'K2', 'K2', 'B'),
		);
	}

	/**
	 * @dataProvider addProcessFailureDataProvider
	 */
	public function testAddProcessFailure($existing_key, $add_key)
	{
		$process1 = new Process('echo "A"');
		$processes = isset($existing_key) ? array($existing_key => $process1) : array($process1);
		$iterator = new ProcessIterator($processes);

		$this->setExpectedException('InvalidArgumentException', 'The "' . $add_key . '" key is already in use.');

		$iterator->addProcess($process1, $add_key);
	}

	public function addProcessFailureDataProvider()
	{
		return array(
			array(null, 0),
			array('K', 'K'),
		);
	}

	/**
	 * @large
	 */
	public function testSetUpdateInterval()
	{
		/** @var Process[] $processes */
		$processes = array(
			new Process('echo "A"; sleep 2'),
			new Process('echo "B"; sleep 3'),
			new Process('echo "C"; sleep 4'),
		);

		$iterator = new ProcessIterator($processes);
		$this->assertSame($iterator, $iterator->setUpdateInterval(1));

		$waiting_count = $normal_count = 0;

		foreach ( $iterator as $index => $process ) {
			if ( $index === '' && $process === null ) {
				$waiting_count++;
			}
			else {
				$normal_count++;
			}
		}

		$this->assertTrue($waiting_count > 0, 'Waiting happened at least once.');
		$this->assertEquals(count($processes), $normal_count, 'All processes were executed.');
	}

	/**
	 * @large
	 */
	public function testLimit()
	{
		/** @var Process[] $processes */
		$processes = array(
			'A' => new Process('echo "A"; sleep 1'),
			'B' => new Process('echo "B"; sleep 2'),
			'C' => new Process('echo "C"; sleep 3'),
			'D' => new Process('echo "D"; sleep 4'),
		);

		$iterator = new ProcessIterator($processes);
		$this->assertSame($iterator, $iterator->limit(2));

		$expected_statuses = array(
			'A' => array(
				'A' => Process::STATUS_TERMINATED,
				'B' => Process::STATUS_STARTED,
				'C' => Process::STATUS_STARTED,
				'D' => Process::STATUS_READY,
			),
			'B' => array(
				'A' => Process::STATUS_TERMINATED,
				'B' => Process::STATUS_TERMINATED,
				'C' => Process::STATUS_STARTED,
				'D' => Process::STATUS_STARTED,
			),
			'C' => array(
				'A' => Process::STATUS_TERMINATED,
				'B' => Process::STATUS_TERMINATED,
				'C' => Process::STATUS_TERMINATED,
				'D' => Process::STATUS_STARTED,
			),
			'D' => array(
				'A' => Process::STATUS_TERMINATED,
				'B' => Process::STATUS_TERMINATED,
				'C' => Process::STATUS_TERMINATED,
				'D' => Process::STATUS_TERMINATED,
			),
		);

		foreach ( $iterator as $index => $process ) {
			$this->assertEquals(
				$expected_statuses[$index],
				$this->getProcessesStatus($processes),
				'Process statuses when ' . $index . ' process was yielded.'
			);
		}
	}

	/**
	 * Returns status for each process.
	 *
	 * @param Process[] $processes Processes.
	 *
	 * @return array
	 */
	protected function getProcessesStatus(array $processes)
	{
		$ret = array();

		foreach ( $processes as $index => $process ) {
			$ret[$index] = $process->getStatus();
		}

		return $ret;
	}

	/**
	 * @large
	 */
	public function testFastestProcessReturnedFirst()
	{
		$processes = array(
			new Process('sleep 3; echo "C"'),
			new Process('sleep 2; echo "B"'),
			new Process('sleep 1; echo "A"'),
		);

		$iterator = new ProcessIterator($processes);

		$output = '';

		foreach ( $iterator as $process ) {
			$output .= trim($process->getOutput());
		}

		$this->assertEquals('ABC', $output, 'Fastest process finished earlier.');
	}

	/**
	 * @medium
	 */
	public function testTimeouts()
	{
		$long_process = new Process('sleep 20');
		$long_process->setTimeout(1);

		$normal_process = new Process('sleep 1');

		$iterator = new ProcessIterator(array('long' => $long_process, 'normal' => $normal_process));

		foreach ( $iterator as $index => $process ) {
			if ( $index === 'long' ) {
				$this->assertInstanceOf(
					'Symfony\Component\Process\Exception\ProcessTimedOutException',
					$iterator->getProcessException()
				);
			}
			elseif ( $index === 'normal' ) {
				$this->assertNull($iterator->getProcessException());
			}
		}
	}

}
