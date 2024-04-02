<?php

namespace CronScheduler;

use CronExpression\Parser;
use DateTime;
use Exception;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Scheduler {

	/**
	 * @var Parser The cron expression parser.
	 */
	private Parser $parser;

	/**
	 * @var array The arguments for the cron expression parser.
	 */
	private array $parserArgs;

	/**
	 * @var array Cached parsed expressions.
	 */
	private array $cachedExpressions;

	/**
	 * @var array Scheduled items that need ran.
	 */
	private array $scheduledItems;

	/**
	 * @var array Scheduled item options, related to the scheduled item by index.
	 */
	private array $scheduledItemsOpts;

	/**
	 * @var array<array{
	 *      process: Process,
	 *      wait_callback: null|callable,
	 *  }> Stores all the processes running in the background.
	 */
	private array $waitBackgroundProcesses;

	/**
	 * @param DateTime|null $date Cron expression parser control arguments.
	 * @param bool $strict Cron expression parser control argument.
	 */
	public function __construct(?DateTime $date = null, bool $strict = false) {
		$this->parser = new Parser();
		$this->parserArgs = [$date, $strict];
	}

	/**
	 * Attempts to schedule an item if the cron expression is valid and due to run.
	 * @param string $expression The cron expression to parse.
	 * @param JobInterface|Process $item The item to be scheduled.
	 * @param array $opts Configurable options.
	 * @return void
	 * @throws Exception|InvalidArgumentException
	 */
	public function schedule(string $expression, $item, array $opts = []): self {
		$this->assertValidItem($item);

		$this->cachedExpressions[$expression] = $this->cachedExpressions[$expression]
			?? $this->parser->parse($expression, $this->parserArgs[0]);

		if ($this->cachedExpressions[$expression]->isDue(...$this->parserArgs)) {
			$this->scheduledItems[] = $item;
			$this->scheduledItemsOpts[] = $opts;
		}

		return $this;
	}

	/**
	 * Runs all the scheduled items.
	 * @return void
	 * @throws RuntimeException|LogicException|ProcessTimedOutException|ProcessSignaledException
	 */
	public function run(): void {
		$this->waitBackgroundProcesses = [];

		foreach ($this->scheduledItems as $index => $item) {
			if ($item instanceof JobInterface)
				$this->runJob($item, $this->scheduledItemsOpts[$index]);
			elseif ($item instanceof Process)
				$this->runProcess($item, $this->scheduledItemsOpts[$index]);
		}

		foreach ($this->waitBackgroundProcesses as $backgroundProcess) {
			$backgroundProcess["process"]->wait($backgroundProcess["wait_callback"]);
		}

		unset($this->waitBackgroundProcesses);
	}

	/**
	 * {@see self::run()}
	 * @param JobInterface $job The job to run.
	 * @param array $opts Configurable options.
	 * @return void
	 */
	private function runJob(JobInterface $job, array $opts = []): void {
		$job->run();
	}

	/**
	 * {@see self::run()}
	 * @param Process $process The process to run.
	 * @param array $opts Configurable options.
	 * @return void
	 * @throws RuntimeException|LogicException|ProcessTimedOutException|ProcessSignaledException
	 */
	private function runProcess(Process $process, array $opts = []): void {
		$runCallback = (isset($opts["run_callback"]) && is_callable($opts["run_callback"])
			? $opts["run_callback"]
			: null
		);

		$waitCallback = (isset($opts["wait_callback"]) && is_callable($opts["wait_callback"])
			? $opts["wait_callback"]
			: null
		);

		if ($process->isRunning())
			return;

		if (isset($opts["background"])) {
			$process->start($runCallback);

			if (isset($opts["wait_background"]) || !is_null($waitCallback)) {
				$this->waitBackgroundProcesses[] = [
					"process" => $process,
					"wait_callback" => $waitCallback,
				];
			}
		} else
			$process->run($runCallback);

		if (!$process->isRunning()) {
			throw new ProcessFailedException($process);
		}
	}

	/**
	 * Asserts the item has a valid type.
	 * @param JobInterface|Process $item The item to be scheduled.
	 * @return void
	 * @throws InvalidArgumentException
	 */
	private function assertValidItem($item): void {
		if ($item instanceof JobInterface || $item instanceof Process)
			return;

		throw new InvalidArgumentException(sprintf(
			"The item type \"%s\" is not of type %s",
			gettype($item), implode("|", [JobInterface::class, Process::class]),
		));
	}

	/**
	 * Creates a scheduler from a valid YAML file format.
	 *
	 * "* * * * *":
	 *      jobs:
	 *          - ClassImplementingJobInterface
	 * "* * * * SAT,SUN":
	 * 		jobs:
	 * 			- ClassImplementingJobInterface
	 *      commands:
	 *          - php test2.php
	 *
	 * @param string $filename The path to the YAML file to be parsed.
	 * @param int $flags A bit field of PARSE_* constants to customize the YAML parser behavior.
	 * @return self
	 * @throws Exception|InvalidArgumentException|ParseException
	 */
	static public function createFromYamlFile(string $filename, int $flags = 0): self {
		$value = Yaml::parseFile($filename, $flags);
		$self = new self();

		if (!is_array($value)) {
			throw new Exception(sprintf(
				"The YAML file \"%s\" is not in the correct format, see %s.",
				$filename, __FUNCTION__,
			));
		}

		foreach ($value as $expression => $values) {
			$values = is_array($values)
				? array_intersect_key($values, array_flip(["commands", "jobs"]))
				: null;

			if (empty($values)) {
				throw new Exception(sprintf(
					"The YAML file \"%s\" is not in the correct format, commands|jobs not found for \"%s\" expression.",
					$filename, $expression,
				));
			}

			foreach ($values as $key => $things) {
				switch ($key) {
					case "jobs" === $key:
						foreach ($things as $job) {
							if (!($reflectionClass = new ReflectionClass($job))->implementsInterface(JobInterface::class)) {
								throw new Exception(sprintf(
									"The YAML file \"%s\" is not in the correct format, %s does not implement %s.",
									$filename, $job, JobInterface::class,
								));
							}

							$self->schedule($expression, $reflectionClass->newInstance());
						}
						break;
					case "commands" === $key:
						foreach ($things as $command)
							$self->schedule($expression, Process::fromShellCommandline($command, null, null, null, null), [
								"background" => true,
							]);
						break;
				}
			}
		}

		return $self;
	}
}