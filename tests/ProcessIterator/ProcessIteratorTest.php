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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Yoast\PHPUnitPolyfills\Polyfills\ExpectException;

class ProcessIteratorTest extends TestCase
{

	use ExpectException;

	public function testCreateWithNonProcess()
	{
		$this->expectException('InvalidArgumentException');

		$message = 'The $processes argument must be an array of non-running instances of ';
		$message .= '"\Symfony\Component\Process\Process" class.';
		$this->expectExceptionMessage($message);

		new ProcessIterator(array('test'));
	}

	public function testCreateWithRunningProcess()
	{
		$this->expectException('InvalidArgumentException');

		$message = 'The $processes argument must be an array of non-running instances of ';
		$message .= '"\Symfony\Component\Process\Process" class.';
		$this->expectExceptionMessage($message);

		$process = $this->createProcess('SLEEP', array('sleep_interval' => 1));
		$process->start();

		new ProcessIterator(array($process));
	}

	public function testProcessFailureIsIgnoredWithoutMustRun()
	{
		/** @var Process[] $processes */
		$processes = array(
			$this->createProcess('EXIT', array('exit_code' => 64)),
			$this->createProcess('EXIT', array('exit_code' => 0)),
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
			$this->createProcess('EXIT', array('exit_code' => 64)),
			$this->createProcess('EXIT', array('exit_code' => 0)),
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
			$this->createProcess('OUTPUT', array('output_text' => 'A')),
			$this->createProcess('OUTPUT', array('output_text' => 'B')),
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
			$this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => '1', 'sleep_interval' => 1)),
			$this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => '2', 'sleep_interval' => 1)),
			$this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => '3', 'sleep_interval' => 1)),
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
		$process1 = $this->createProcess('OUTPUT', array('output_text' => $output1));
		$process2 = $this->createProcess('OUTPUT', array('output_text' => $output2));

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
		$process1 = $this->createProcess('OUTPUT', array('output_text' => 'A'));
		$processes = isset($existing_key) ? array($existing_key => $process1) : array($process1);
		$iterator = new ProcessIterator($processes);

		$this->expectException('InvalidArgumentException');
		$this->expectExceptionMessage('The "' . $add_key . '" key is already in use.');

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
			$this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => 'A', 'sleep_interval' => 2)),
			$this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => 'B', 'sleep_interval' => 3)),
			$this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => 'C', 'sleep_interval' => 4)),
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
			'A' => $this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => 'A', 'sleep_interval' => 1)),
			'B' => $this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => 'B', 'sleep_interval' => 2)),
			'C' => $this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => 'C', 'sleep_interval' => 3)),
			'D' => $this->createProcess('OUTPUT_AND_SLEEP', array('output_text' => 'D', 'sleep_interval' => 4)),
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
			$this->createProcess('SLEEP_AND_OUTPUT', array('output_text' => 'C', 'sleep_interval' => 3)),
			$this->createProcess('SLEEP_AND_OUTPUT', array('output_text' => 'B', 'sleep_interval' => 2)),
			$this->createProcess('SLEEP_AND_OUTPUT', array('output_text' => 'A', 'sleep_interval' => 1)),
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
		$long_process = $this->createProcess('SLEEP', array('sleep_interval' => 20));
		$long_process->setTimeout(1);

		$normal_process = $this->createProcess('SLEEP', array('sleep_interval' => 1));

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

	/**
	 * Creates a process.
	 *
	 * @param string  $mode           Mode.
	 * @param string  $output_text    Output text.
	 * @param integer $sleep_interval Sleep interval.
	 * @param integer $exit_code      Exit code.
	 *
	 * @return Process
	 */
	protected function createProcess($mode, array $arguments)
	{
		$defaults = array(
			'output_text' => '',
			'sleep_interval' => 0,
			'exit_code' => 0,
		);
		$arguments = array_merge($defaults, $arguments);

		$command_line = array(
			dirname(__DIR__) . '/wrapper.sh',
			$mode,
			$arguments['output_text'],
			$arguments['sleep_interval'],
			$arguments['exit_code'],
		);

		if ( !method_exists('Symfony\Component\Process\Process', 'escapeArgument') ) {
			$command_line = implode(
				' ',
				array_map(array('Symfony\Component\Process\ProcessUtils', 'escapeArgument'), $command_line)
			);
		}

		return new Process($command_line);
	}

}
