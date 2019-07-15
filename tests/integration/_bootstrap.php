<?php

use MailPoet\Config\Database;

if ((boolean)getenv('MULTISITE') === true) {
  // REQUEST_URI needs to be set for WP to load the proper subsite where MailPoet is activated
  $_SERVER['REQUEST_URI'] = '/' . getenv('WP_TEST_MULTISITE_SLUG');
  $wp_load_file = getenv('WP_ROOT_MULTISITE') . '/wp-load.php';
} else {
  $wp_load_file = getenv('WP_ROOT') . '/wp-load.php';
}
require_once($wp_load_file);

$console = new \Codeception\Lib\Console\Output([]);
$console->writeln('Loading WP core... (' . $wp_load_file . ')');

$console->writeln('Cleaning up database...');
$models = [
  'CustomField',
  'Form',
  'Newsletter',
  'NewsletterLink',
  'NewsletterPost',
  'NewsletterSegment',
  'NewsletterTemplate',
  'NewsletterOption',
  'NewsletterOptionField',
  'Segment',
  'Log',
  'ScheduledTask',
  'ScheduledTaskSubscriber',
  'SendingQueue',
  'Setting',
  'Subscriber',
  'SubscriberCustomField',
  'SubscriberSegment',
  'SubscriberIP',
  'StatisticsOpens',
  'StatisticsClicks',
  'StatisticsNewsletters',
  'StatisticsUnsubscribes',
];
$destroy = function($model) {
  $class = new \ReflectionClass('\MailPoet\Models\\' . $model);
  $table = $class->getStaticPropertyValue('_table');
  $db = ORM::getDb();
  $db->beginTransaction();
  $db->exec('TRUNCATE ' . $table);
  $db->commit();
};
array_map($destroy, $models);

$cacheDir = '/tmp';
if (is_dir(getenv('WP_TEST_CACHE_PATH'))) {
  $cacheDir = getenv('WP_TEST_CACHE_PATH');
}

$console->writeln('Clearing AspectMock cache...');
exec('rm -rf ' . $cacheDir . '/_transformation.cache');

$console->writeln('Initializing AspectMock library...');
$kernel = \AspectMock\Kernel::getInstance();
$kernel->init(
  [
    'debug' => true,
    'appDir' => __DIR__ . '/../../',
    'cacheDir' => $cacheDir,
    'includePaths' => [__DIR__ . '/../../lib'],
  ]
);

abstract class MailPoetTest extends \Codeception\TestCase\Test {
  protected $backupGlobals = false;
  protected $backupStaticAttributes = false;

  function runBare() {
    $pipe_result = tempnam('/tmp', 'mailpoet-integration-tests-result');
    $pipe_exception = tempnam('/tmp', 'mailpoet-integration-tests-exception');

    \ORM::resetConfig();
    \ORM::resetDb();

    $pid = pcntl_fork();
    if ($pid === -1) {
      throw new \Exception();
    }

    // parent
    if ($pid > 0) {
      pcntl_wait($status);
      $exception = file_get_contents($pipe_exception);
      if ($exception) {
        $e = unserialize($exception);
        throw $e;
      }
      return unserialize(file_get_contents($pipe_result));
    }

    // child
    try {
      $database = new Database();
      $database->init();
      $result = parent::runBare();
      file_put_contents($pipe_result, serialize($result));
    } catch (\Throwable $e) {
      if ($e instanceof \PDOException && !is_int($e->getCode())) {
        $reflectionClass = new \ReflectionClass($e);
        $reflectionProperty = $reflectionClass->getProperty('code');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($e, (int) $reflectionProperty->getValue($e));
        $reflectionProperty->setAccessible(false);
      }
      $fe = \Symfony\Component\Debug\Exception\FlattenException::create($e);
      $reflectionClass = new \ReflectionClass($e instanceof \Exception ? 'Exception' : 'Error');
      $reflectionProperty = $reflectionClass->getProperty('trace');
      $reflectionProperty->setAccessible(true);
      $reflectionProperty->setValue($e, $fe->getTrace());
      $reflectionProperty->setAccessible(false);
      file_put_contents($pipe_exception, serialize($e));
    } finally {
      posix_kill(getmypid(), SIGKILL);
    }
  }

  function setUp() {
    parent::setUp();
    \MailPoet\Settings\SettingsController::resetCache();
  }

  /**
   * Call protected/private method of a class.
   *
   * @param object &$object Instantiated object that we will run method on.
   * @param string $methodName Method name to call
   * @param array $parameters Array of parameters to pass into method.
   *
   * @return mixed Method return.
   */
  public function invokeMethod(&$object, $methodName, array $parameters = []) {
    $reflection = new \ReflectionClass(get_class($object));
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);

    return $method->invokeArgs($object, $parameters);
  }
}

function asCallable($fn) {
  return function() use(&$fn) {
    return call_user_func_array($fn, func_get_args());
  };
}

include '_fixtures.php';
