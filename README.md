# ProcessIterator

[![Build Status](https://travis-ci.org/console-helpers/process-iterator.svg?branch=master)](https://travis-ci.org/console-helpers/process-iterator)
[![Coverage Status](https://coveralls.io/repos/github/console-helpers/process-iterator/badge.svg?branch=master)](https://coveralls.io/github/console-helpers/process-iterator?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/console-helpers/process-iterator/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/console-helpers/process-iterator/?branch=master)


[![Latest Stable Version](https://poser.pugx.org/console-helpers/process-iterator/v/stable)](https://packagist.org/packages/console-helpers/process-iterator)
[![Total Downloads](https://poser.pugx.org/console-helpers/process-iterator/downloads)](https://packagist.org/packages/console-helpers/process-iterator)
[![License](https://poser.pugx.org/console-helpers/process-iterator/license)](https://packagist.org/packages/console-helpers/process-iterator)

ProcessIterator is a PHP class, that allows writing sequential code to handle processes, that run in parallel.

This is a modified version of the `FutureIterator` class from [Phabricator](http://phabricator.org/) project adapted to with with [Symfony Process](http://symfony.com/doc/current/components/process.html) component.

## Installation

* execute this command to add dependencies: `php composer.phar require console-helpers/process-iterator:dev-master`

## Usage

**IMPORTANT:** Keys are preserved, but the order of elements is not. Iteration is done over the processes in the order they are executed, so the fastest process is the one you'll get first. This allows you to start doing followup processing as soon as possible.

```php
<?php

use ConsoleHelpers\ProcessIterator\ProcessIterator;
use Symfony\Component\Process\Process;

$processes = array(
	'a.txt' => new Process('wc -c a.txt'),
	'b.txt' => new Process('wc -c b.txt'),
	'c.txt' => new Process('wc -c c.txt'),
);


// All of the processes will be started at once, when "foreach" line is executed.
$process_iterator = new ProcessIterator($processes);

foreach ($process_iterator as $key => $process) {
	$stderr = $process->getErrorOutput();
	$stdout = $process->getOutput();
	do_some_processing($stdout);
}


// Will only run 2 processes in parallel at a time.
$process_iterator = new ProcessIterator($processes);
$process_iterator->limit(2);

foreach ($process_iterator as $key => $process) {
	$stderr = $process->getErrorOutput();
	$stdout = $process->getOutput();
	do_some_processing($stdout);
}


// Will run all processes in parallel. The $processes array can be inspected later 
// to see execution results.
$process_iterator = new ProcessIterator($processes);
$process_iterator->runAll();


// Allows to add more processes in real time as they are processed.
$process_iterator = new ProcessIterator($processes);

foreach ($process_iterator as $key => $process) {
	$stderr = $process->getErrorOutput();
	$stdout = $process->getOutput();
	do_some_processing($stdout);

	if ( $key === 'b.txt' ) {
		$process_iterator->addProcess(
			new Process('wc -c d.txt'),
			'd.txt'
		);
	}
}


// Show "processing ..." message at if no process was finished executing after 
// given time has passed. This can happen several times as well.
$process_iterator = new ProcessIterator($processes);
$process_iterator->setUpdateInterval(1);

foreach ($process_iterator as $key => $process) {
	if ($process === null) {
		echo "Still working...\n";
	}
	else {
		$stderr = $process->getErrorOutput();
		$stdout = $process->getOutput();
		do_some_processing($stdout);
	}
}


// Safe process exception detection. When exception happens during process 
// execution it's recorded and that process is immediately yielded. Then
// the $process_iterator->getProcessException() method can be used to 
// handle it gracefully (e.g. re-add back to queue).
$process_iterator = new ProcessIterator($processes);

foreach ($process_iterator as $key => $process) {
	$process_exception = $process_iterator->getException();

	if ( $process_exception instanceof ProcessTimedOutException ) {
		echo "The $key process timed out.\n";
	}
	elseif ( $process_exception instanceof ProcessFailedException ) {
		echo "The $key process has failed.\n";
	}
	else {
		$stderr = $process->getErrorOutput();
		$stdout = $process->getOutput();
		do_some_processing($stdout);
	}
}
```

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md) file.

## License

ProcessIterator is released under the BSD-3-Clause License. See the bundled [LICENSE](LICENSE) file for details.
