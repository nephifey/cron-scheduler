<?php

namespace CronScheduler;

interface JobInterface {

	/**
	 * Executes the job code.
	 * @return void
	 */
	public function run(): void;
}