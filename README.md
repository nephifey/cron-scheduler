# cron-scheduler

Cron scheduler to execute code and/or processes at specific times. Utilizes 
[nephifey/cron-expression](https://github.com/nephifey/cron-expression), [symfony/process](https://github.com/symfony/process), and [symfony/yaml](https://github.com/symfony/yaml).

## YAML File Format

```yaml
"* * * * *":
  jobs:
    - ClassImplementingJobInterface
  commands:
    - [your command to run]
```

## Getting Started

Install the package into your project using composer via the command below.

```
composer require nephifey/cron-scheduler
```

## Usage

### Basic Example

```php
require_once "vendor/autoload.php";

$job1 = new class implements \CronScheduler\JobInterface {
	public function run() : void{
		echo "this will run every minute (job1).\r\n";
	}
};

$job2 = new class implements \CronScheduler\JobInterface {
	public function run() : void{
		echo "this will run every minute (job2).\r\n";
	}
};

$job3 = new class implements \CronScheduler\JobInterface {
	public function run() : void{
		echo "this will run every five minutes (job3).\r\n";
	}
};

$scheduler = new \CronScheduler\Scheduler();
$scheduler->schedule("* * * * *", $job1);
$scheduler->schedule("* * * * *", $job2);
$scheduler->schedule("*/5 * * * *", $job3);
$scheduler->run();
```

### YAML Example

```yaml
# crontab.yaml
"* * * * *":
  jobs:
    - ExampleClass1
    - ExampleClass2
"*/5 * * * *":
  jobs:
    - ExampleClass3
"@daily":
  commands:
    - php expensive_task.php # ran in background
```

```php
# crontab.php
require_once "vendor/autoload.php";

class ExampleClass1 implements \CronScheduler\JobInterface {
    public function run() : void{
        echo "this will run every minute (job1).\r\n";
    }
}

class ExampleClass2 implements \CronScheduler\JobInterface {
    public function run() : void{
        echo "this will run every minute (job2).\r\n";
    }
}

class ExampleClass3 implements \CronScheduler\JobInterface {
    public function run() : void{
        echo "this will run every five minutes (job3).\r\n";
    }
}

(\CronScheduler\Scheduler::createFromYamlFile("crontab.yaml"))->run();
```

```php
# expensive_task.php

// do your expensive task in a separate script execution asynchronously.
```