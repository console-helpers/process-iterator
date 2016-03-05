<?php
/**
 * This file is part of the ProcessIterator library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/process-iterator
 */

namespace ConsoleHelpers\ProcessIterator;


use Symfony\Component\Process\Process;

/**
 * ProcessIterator aggregates processes and allows you to respond to them
 * in the order they are executed. This is useful because it minimizes the amount of
 * time your program spends waiting on parallel processes.
 *
 *   $processes = array(
 *     'a.txt' => new \Symfony\Component\Process\Process('wc -c a.txt'),
 *     'b.txt' => new \Symfony\Component\Process\Process('wc -c b.txt'),
 *     'c.txt' => new \Symfony\Component\Process\Process('wc -c c.txt'),
 *   );
 *
 *   foreach (new ProcessIterator($processes) as $key => $process) {
 *     // IMPORTANT: Keys are preserved, but the order of elements is not.
 *     // Iteration is done over the processes in the order they are executed,
 *     // so the fastest process is the one you'll get first. This allows you
 *     // to start doing followup processing as soon as possible.
 *
 *     $stderr = $process->getErrorOutput();
 *     $stdout = $process->getOutput();
 *     do_some_processing($stdout);
 *   }
 */
class ProcessIterator implements \Iterator
{

	/**
	 * Key of the processes, that are waiting to be executed.
	 *
	 * @var array
	 */
	protected $waitingQueue = array();

	/**
	 * Keys of the processes, that are currently running.
	 *
	 * @var array
	 */
	protected $runningQueue = array();

	/**
	 * Processes to iterate over.
	 *
	 * @var Process[]
	 */
	protected $processes = array();

	/**
	 * Last exception, thrown by each process.
	 *
	 * @var \Exception[]
	 */
	protected $exceptions = array();

	/**
	 * Current process key.
	 *
	 * @var mixed
	 */
	protected $key;

	/**
	 * Maximal number of simultaneously executing processes.
	 *
	 * @var integer
	 */
	protected $limit;

	/**
	 * Maximal amount of time to wait before iterator will yield the result.
	 *
	 * @var integer
	 */
	protected $timeout;

	/**
	 * Result waiting timeout was reached.
	 *
	 * @var boolean
	 */
	protected $isTimeout = false;

	/**
	 * Create a new iterator over a list of processes.
	 *
	 * @param array $processes List of processes to execute.
	 *
	 * @throws \InvalidArgumentException When unknown elements are present in $processes array.
	 */
	public function __construct(array $processes)
	{
		$filtered_processes = array_filter($processes, function ($process) {
			return ($process instanceof Process) && !$process->isRunning();
		});

		if ( count($filtered_processes) !== count($processes) ) {
			throw new \InvalidArgumentException(sprintf(
				'The $processes argument must be an array of non-running instances of "%s" class.',
				'\Symfony\Component\Process\Process'
			));
		}

		$this->processes = $processes;
	}

	/**
	 * Block until all processes have executed.
	 *
	 * @return void
	 */
	public function runAll()
	{
		foreach ( $this as $process ) {
			$process->wait();
		}
	}

	/**
	 * Add another process to the set of processes. This is useful if you have a
	 * set of processes to run mostly in parallel, but some processes depend on
	 * others.
	 *
	 * @param Process $process Process to add to iterator.
	 * @param mixed   $key     Key.
	 *
	 * @return self
	 * @throws \InvalidArgumentException When given key is already in use.
	 */
	public function addProcess(Process $process, $key = null)
	{
		if ( $key === null ) {
			$this->processes[] = $process;
			end($this->processes);
			$this->waitingQueue[] = key($this->processes);
		}
		else {
			if ( !isset($this->processes[$key]) ) {
				$this->processes[$key] = $process;
				$this->waitingQueue[] = $key;
			}
			else {
				throw new \InvalidArgumentException('The "' . $key . '" key is already in use.');
			}
		}

		// Start running the process if we don't have $this->limit processes running already.
		$this->updateWorkingSet();

		return $this;
	}

	/**
	 * Set a maximum amount of time you want to wait before the iterator will
	 * yield a result. If no process has executed yet, the iterator will yield
	 * null for key and value. Among other potential uses, you can use this to
	 * show some busy indicator:
	 *   $processes = (new ProcessIterator($processes))
	 *     ->setUpdateInterval(1);
	 *   foreach ($processes as $process) {
	 *     if ($process === null) {
	 *       echo "Still working...\n";
	 *     } else {
	 *       // ...
	 *     }
	 *   }
	 * This will echo "Still working..." once per second as long as processes are
	 * resolving. By default, ProcessIterator never yields null.
	 *
	 * @param float $interval Maximum number of seconds to block waiting on processes before yielding null.
	 *
	 * @return self
	 */
	public function setUpdateInterval($interval)
	{
		$this->timeout = $interval;

		return $this;
	}

	/**
	 * Limit the number of simultaneously executing processes.
	 *  $processes = (new ProcessIterator($processes))
	 *    ->limit(4);
	 *  foreach ($processes as $process) {
	 *    // Run no more than 4 processes simultaneously.
	 *  }
	 *
	 * @param integer $max Maximum number of simultaneously running processes allowed.
	 *
	 * @return self
	 */
	public function limit($max)
	{
		$this->limit = $max;

		return $this;
	}

	/**
	 * Rewind the Iterator to the first element.
	 *
	 * @return void
	 */
	public function rewind()
	{
		$this->waitingQueue = array_keys($this->processes);
		$this->runningQueue = array();
		$this->updateWorkingSet();
		$this->next();
	}

	/**
	 * Move forward to next element.
	 *
	 * @return void
	 */
	public function next()
	{
		$this->key = null;

		if ( !count($this->waitingQueue) ) {
			return;
		}

		$start = microtime(true);
		$timeout = $this->timeout;
		$this->isTimeout = false;

		$executed_index = null;

		do {
			foreach ( $this->runningQueue as $index => $process_key ) {
				$process = $this->processes[$process_key];

				try {
					if ( $this->getProcessException($process_key) ) {
						$executed_index = $index;
						continue;
					}

					$process->checkTimeout();

					if ( $process->isTerminated() ) {
						if ( $executed_index === null ) {
							$executed_index = $index;
						}

						continue;
					}
				}
				catch ( \Exception $exception ) {
					$this->setProcessException($process_key, $exception);
					$executed_index = $index;
					break;
				}
			}

			if ( $executed_index === null ) {
				// Check for a setUpdateInterval() timeout.
				if ( $timeout !== null ) {
					$elapsed = microtime(true) - $start;

					if ( $elapsed > $timeout ) {
						$this->isTimeout = true;

						return;
					}
				}

				usleep(1000);
			}
		} while ( $executed_index === null );

		$this->key = $this->waitingQueue[$executed_index];
		unset($this->waitingQueue[$executed_index]);
		$this->updateWorkingSet();
	}

	/**
	 * Remembers exception, associated with a process.
	 *
	 * @param mixed      $key       Process key.
	 * @param \Exception $exception Exception.
	 *
	 * @return void
	 */
	protected function setProcessException($key, \Exception $exception)
	{
		$this->exceptions[$key] = $exception;
	}

	/**
	 * Gets exception, associated with a process.
	 *
	 * @param mixed $key Process key.
	 *
	 * @return \Exception|null
	 */
	public function getProcessException($key = null)
	{
		if ( $key === null ) {
			$key = $this->key();
		}

		return isset($this->exceptions[$key]) ? $this->exceptions[$key] : null;
	}

	/**
	 * Return the current element.
	 *
	 * @return Process|null
	 */
	public function current()
	{
		if ( $this->isTimeout ) {
			return null;
		}

		return $this->processes[$this->key];
	}

	/**
	 * Return the key of the current element.
	 *
	 * @return mixed|null
	 */
	public function key()
	{
		if ( $this->isTimeout ) {
			return null;
		}

		return $this->key;
	}

	/**
	 * Checks if current position is valid.
	 *
	 * @return boolean
	 */
	public function valid()
	{
		if ( $this->isTimeout ) {
			return true;
		}

		return ($this->key !== null);
	}

	/**
	 * Ensure, that needed number of processes are running in parallel.
	 *
	 * @return void
	 */
	protected function updateWorkingSet()
	{
		$old_running_queue = $this->runningQueue;

		if ( $this->limit ) {
			$this->runningQueue = array_slice($this->waitingQueue, 0, $this->limit, true);
		}
		else {
			$this->runningQueue = $this->waitingQueue;
		}

		// Start processes, that were just added to the running queue.
		foreach ( $this->runningQueue as $index => $process_key ) {
			if ( !isset($old_running_queue[$index]) ) {
				$this->processes[$process_key]->start();
			}
		}
	}

}
