<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004, 2011 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Nette\Config;

use Nette,
	Nette\Caching\Cache,
	Nette\DI,
	Nette\DI\ContainerBuilder,
	Nette\Utils\Validators;



/**
 * Initial system DI container generator.
 *
 * @author     David Grudl
 *
 * @property-read \SystemContainer $container
 */
class Configurator extends Nette\Object
{
	/** config file sections */
	const DEVELOPMENT = 'development',
		PRODUCTION = 'production',
		CONSOLE = 'console';

	/** @var Nette\DI\Container */
	private $container;

	/** @var array */
	private $params;



	public function __construct()
	{
		$this->params = $this->getDefaultParameters();
		Nette\Environment::setConfigurator($this); // back compatibility
	}



	public function setCacheDirectory($path)
	{
		$this->params['tempDir'] = $path;
		return $this;
	}



	public function addParameters(array $params)
	{
		$this->params = $params + $this->params;
		return $this;
	}



	protected function getDefaultParameters()
	{
		$trace = debug_backtrace(FALSE);
		return array(
			'appDir' => isset($trace[1]['file']) ? dirname($trace[1]['file']) : NULL,
			'wwwDir' => isset($_SERVER['SCRIPT_FILENAME']) ? dirname($_SERVER['SCRIPT_FILENAME']) : NULL,
			'productionMode' => static::detectProductionMode(),
			'consoleMode' => PHP_SAPI === 'cli',
		);
	}



	/**
	 * @return Nette\Loaders\RobotLoader
	 */
	public function createRobotLoader()
	{
		if (empty($this->params['tempDir'])) {
			throw new Nette\InvalidStateException("Set path to temporary directory using setCacheDirectory().");
		}
		$loader = new Nette\Loaders\RobotLoader;
		$loader->setCacheStorage(new Nette\Caching\Storages\FileStorage($this->params['tempDir']));
		$loader->autoRebuild = !$this->params['productionMode'];
		return $loader;
	}



	/**
	 * Returns system DI container.
	 * @return \SystemContainer
	 */
	public function getContainer()
	{
		if (!$this->container) {
			$this->createContainer();
		}
		return $this->container;
	}



	/**
	 * Loads configuration from file and process it.
	 * @return \SystemContainer
	 */
	public function loadConfig($file, $section = NULL)
	{
		if ($section === NULL) {
			if (PHP_SAPI === 'cli') {
				$section = self::CONSOLE;
			} else {
				$section = $this->params['productionMode'] ? self::PRODUCTION : self::DEVELOPMENT;
			}
		}

		$this->createContainer($file, $section);
		return $this->container;
	}



	private function createContainer($file = NULL, $section = NULL)
	{
		if ($this->container) {
			throw new Nette\InvalidStateException('Container has already been created. Make sure you did not call getContainer() before loadConfig().');
		}

		$this->params['environment'] = $section;

		if (!empty($this->params['tempDir'])) {
			$cache = new Cache(new Nette\Caching\Storages\PhpFileStorage($this->params['tempDir']), 'Nette.Configurator');
			$cacheKey = array($this->params, $file, $section);
			$cached = $cache->load($cacheKey);
			if (!$cached) {
				$loader = new Config;
				$config = $file ? $loader->load($file, $section) : array();
				$dependencies = $loader->getDependencies();
				$code = "<?php\n// source file $file $section\n\n"
					. $this->buildContainer($config, $dependencies);

				$cache->save($cacheKey, $code, array(
					Cache::FILES => $this->params['productionMode'] ? NULL : $dependencies,
				));
				$cached = $cache->load($cacheKey);
			}
			Nette\Utils\LimitedScope::load($cached['file']);

		} elseif ($file) {
			throw new Nette\InvalidStateException("Set path to temporary directory using setCacheDirectory().");

		} else {
			Nette\Utils\LimitedScope::evaluate('<?php ' . $this->buildContainer(array()));
		}

		$class = $this->formatContainerClassName();
		$this->container = new $class;
		$this->container->initialize();
	}



	private function buildContainer(array $config, array & $dependencies = array())
	{
		$this->checkCompatibility($config);

		// consolidate parameters
		if (!isset($config['parameters'])) {
			$config['parameters'] = array();
		}
		foreach ($config as $key => $value) {
			if (!in_array($key, array('parameters', 'services', 'php', 'constants'))) {
				$config['parameters'][$key] = $value;
			}
		}

		// pre-expand parameters at compile-time
		$parameters = $config['parameters'];
		array_walk_recursive($config, function(&$val) use ($parameters) {
			$val = Configurator::preExpand($val, $parameters);
		});

		// build DI container
		$container = new ContainerBuilder;
		$container->parameters = $this->params;

		$this->configureCore($container, $config);
		$this->parseDI($container, $config);

		$class = $container->generateClass();
		$class->setName($this->formatContainerClassName());

		$initialize = $class->addMethod('initialize');

		// PHP settings
		if (isset($config['php'])) {
			$this->configurePhp($container, $class, $config['php']);
		}

		// define constants
		if (isset($config['constants'])) {
			$this->configureConstants($container, $class, $config['constants']);
		}

		// auto-start services
		foreach ($container->findByTag('run') as $name => $foo) {
			$initialize->body .= $container->formatPhp('$this->getService(?);', array($name));
		}

		// pre-loading
		if (isset($this->params['tempDir'])) {
			$initialize->body .= $this->checkTempDir();
		}

		$dependencies = array_merge($dependencies, $container->getDependencies());
		return (string) $class;
	}



	/**
	 * Parses 'services' and 'parameters' parts of config
	 * @return void
	 */
	public static function parseDI(ContainerBuilder $container, array $config)
	{
		if (isset($config['parameters'])) {
			$container->parameters = $config['parameters'] + $container->parameters;
		}

		if (isset($config['services'])) {
			uasort($config['services'], function($a, $b) {
				return strcmp(Config::isInheriting($a), Config::isInheriting($b));
			});
			foreach ($config['services'] as $name => $def) {
				if ($parent = Config::takeParent($def)) {
					$container->removeDefinition($name);
					$definition = $container->addDefinition($name);
					if ($parent !== Config::OVERWRITE) {
						foreach ($container->getDefinition($parent) as $k => $v) {
							$definition->$k = $v;
						}
					}
				} elseif ($container->hasDefinition($name)) {
					$definition = $container->getDefinition($name);
				} else {
					$definition = $container->addDefinition($name);
				}
				try {
					static::parseService($definition, $def);
				} catch (\Exception $e) {
					throw new DI\ServiceCreationException("Service $name: " . $e->getMessage()/**/, NULL, $e/**/);
				}
			}
		}
	}



	public static function parseService(Nette\DI\ServiceDefinition $definition, $config)
	{
		if (!is_array($config)) {
			$config = array('class' => $config);
		}

		$known = array('class', 'factory', 'arguments', 'autowired', 'setup', 'run', 'tags');
		if ($error = array_diff(array_keys($config), $known)) {
			throw new Nette\InvalidStateException("Unknown key '" . implode("', '", $error) . "' in definition of service.");
		}

		if (isset($config['class'])) {
			Validators::assertField($config, 'class', 'string');
			$definition->setClass($config['class']);
		}

		if (isset($config['factory'])) {
			Validators::assertField($config, 'factory', 'callable');
			$definition->setFactory($config['factory']);
			if (!isset($config['arguments'])) {
				$config['arguments'][] = '@container';
			}
		}

		if (isset($config['arguments'])) {
			Validators::assertField($config, 'arguments', 'array');
			$definition->setArguments(array_diff($config['arguments'], array('...')));
		}

		if (isset($config['setup'])) {
			Validators::assertField($config, 'setup', 'array');
			if (Config::takeParent($config['setup'])) {
				$definition->setup = array();
			}
			foreach ($config['setup'] as $member => $args) {
				if (is_int($member)) {
					Validators::assert($args, 'list:1..2', "setup item #$member");
					$member = $args[0];
					$args = isset($args[1]) ? $args[1] : NULL;
				}
				if (strpos($member, '$') === FALSE && $args !== NULL) {
					Validators::assert($args, 'array', "setup arguments for '$member'");
					$args = array_diff($args, array('...'));
				}
				$definition->addSetup($member, $args);
			}
		}

		if (isset($config['autowired'])) {
			Validators::assertField($config, 'autowired', 'bool|string');
			$definition->setAutowired($config['autowired']);
		}

		if (isset($config['run'])) {
			$config['tags']['run'] = (bool) $config['run'];
		}

		if (isset($config['tags'])) {
			Validators::assertField($config, 'tags', 'array');
			if (Config::takeParent($config['tags'])) {
				$definition->tags = array();
			}
			foreach ($config['tags'] as $tag => $attrs) {
				if (is_int($tag) && is_string($attrs)) {
					$definition->addTag($attrs);
				} else {
					$definition->addTag($tag, $attrs);
				}
			}
		}
	}



	private function checkCompatibility(array $config)
	{
		foreach (array('service' => 'services', 'variable' => 'parameters', 'variables' => 'parameters', 'mode' => 'parameters', 'const' => 'constants') as $old => $new) {
			if (isset($config[$old])) {
				throw new Nette\DeprecatedException(basename($file) . ": Section '$old' is deprecated; use '$new' instead.");
			}
		}
		if (isset($config['services'])) {
			foreach ($config['services'] as $key => $def) {
				foreach (array('option' => 'arguments', 'methods' => 'setup') as $old => $new) {
					if (is_array($def) && isset($def[$old])) {
						throw new Nette\DeprecatedException(basename($file) . ": Section '$old' in service definition is deprecated; refactor it into '$new'.");
					}
				}
			}
		}
	}



	public function formatContainerClassName()
	{
		return 'SystemContainer';
	}



	/********************* tools ****************d*g**/



	/**
	 * Detects production mode by IP address.
	 * @return bool
	 */
	public static function detectProductionMode()
	{
		$addrs = array();
		if (PHP_SAPI === 'cli') {
			$addrs[] = getHostByName(php_uname('n'));
		} else {
			if (!isset($_SERVER['SERVER_ADDR']) && !isset($_SERVER['LOCAL_ADDR'])) {
				return TRUE;
			}
			if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { // proxy server detected
				$addrs = preg_split('#,\s*#', $_SERVER['HTTP_X_FORWARDED_FOR']);
			}
			if (isset($_SERVER['REMOTE_ADDR'])) {
				$addrs[] = $_SERVER['REMOTE_ADDR'];
			}
			$addrs[] = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : $_SERVER['LOCAL_ADDR'];
		}

		foreach ($addrs as $addr) {
			$oct = explode('.', $addr);
			// 10.0.0.0/8   Private network
			// 127.0.0.0/8  Loopback
			// 169.254.0.0/16 & ::1  Link-Local
			// 172.16.0.0/12  Private network
			// 192.168.0.0/16  Private network
			if ($addr !== '::1' && (count($oct) !== 4 || ($oct[0] !== '10' && $oct[0] !== '127' && ($oct[0] !== '172' || $oct[1] < 16 || $oct[1] > 31)
				&& ($oct[0] !== '169' || $oct[1] !== '254') && ($oct[0] !== '192' || $oct[1] !== '168')))
			) {
				return TRUE;
			}
		}
		return FALSE;
	}



	private function configurePhp(ContainerBuilder $container, Nette\Utils\PhpGenerator\ClassType $class, $config)
	{
		$body = & $class->methods['initialize']->body;

		foreach ($config as $name => $value) { // back compatibility - flatten INI dots
			if (is_array($value)) {
				unset($config[$name]);
				foreach ($value as $k => $v) {
					$config["$name.$k"] = $v;
				}
			}
		}

		foreach ($config as $name => $value) {
			if (!is_scalar($value)) {
				throw new Nette\InvalidStateException("Configuration value for directive '$name' is not scalar.");

			} elseif ($name === 'include_path') {
				$body .= $container->formatCall('set_include_path', array(str_replace(';', PATH_SEPARATOR, $value)));

			} elseif ($name === 'ignore_user_abort') {
				$body .= $container->formatCall('ignore_user_abort', array($value));

			} elseif ($name === 'max_execution_time') {
				$body .= $container->formatCall('set_time_limit', array($value));

			} elseif ($name === 'date.timezone') {
				$body .= $container->formatCall('date_default_timezone_set', array($value));

			} elseif (function_exists('ini_set')) {
				$body .= $container->formatCall('ini_set', array($name, $value));

			} elseif (ini_get($name) != $value && !Nette\Framework::$iAmUsingBadHost) { // intentionally ==
				throw new Nette\NotSupportedException('Required function ini_set() is disabled.');
			}
		}
	}



	private function configureConstants(ContainerBuilder $container, Nette\Utils\PhpGenerator\ClassType $class, $config)
	{
		$body = & $class->methods['initialize']->body;
		foreach ($config as $name => $value) {
			$body .= $container->formatCall('define', array($name, $value));
		}
	}



	private function configureCore(ContainerBuilder $container, & $config)
	{
		// cache
		$container->addDefinition('cacheJournal')
			->setClass('Nette\Caching\Storages\FileJournal', array('%tempDir%'));

		$container->addDefinition('cacheStorage')
			->setClass('Nette\Caching\Storages\FileStorage', array('%tempDir%/cache'));

		$container->addDefinition('templateCacheStorage')
			->setClass('Nette\Caching\Storages\PhpFileStorage', array('%tempDir%/cache'))
			->setAutowired(FALSE);

		// http
		$container->addDefinition('httpRequestFactory')
			->setClass('Nette\Http\RequestFactory')
			->addSetup('setEncoding', array('UTF-8'));

		$container->addDefinition('httpRequest')
			->setClass('Nette\Http\Request')
			->setFactory('@httpRequestFactory::createHttpRequest');

		$container->addDefinition('httpResponse')
			->setClass('Nette\Http\Response');

		$container->addDefinition('httpContext')
			->setClass('Nette\Http\Context');

		$session = $container->addDefinition('session')
			->setClass('Nette\Http\Session');

		if (isset($config['session'])) {
			$session->addSetup('setOptions', $config['session']);
		}
		if (isset($config['session']['expiration'])) {
			$session->addSetup('setExpiration', $config['session']['expiration']);
		}

		$container->addDefinition('user')
			->setClass('Nette\Http\User');

		// application
		$application = $container->addDefinition('application')
			->setClass('Nette\Application\Application')
			->addSetup('$catchExceptions', '%productionMode%');

		if (empty($config['productionMode'])) {
			$application->addSetup('Nette\Application\Diagnostics\RoutingPanel::initialize'); // enable routing debugger
		}

		$container->addDefinition('router')
			->setClass('Nette\Application\Routers\RouteList');

		$container->addDefinition('presenterFactory')
			->setClass('Nette\Application\PresenterFactory', array(
				isset($container->parameters['appDir']) ? $container->parameters['appDir'] : NULL
			));

		// mailer
		if (empty($config['mailer']['smtp'])) {
			$container->addDefinition('mailer')
				->setClass('Nette\Mail\SendmailMailer');
		} else {
			$container->addDefinition('mailer')
				->setClass('Nette\Mail\SmtpMailer', $config['mailer']);
		}
	}



	/**
	 * Pre-expands %placeholders% in string.
	 * @internal
	 */
	public static function preExpand($s, array $params, $check = array())
	{
		if (!is_string($s)) {
			return $s;
		}

		$parts = preg_split('#%([\w.-]*)%#i', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
		$res = '';
		foreach ($parts as $n => $part) {
			if ($n % 2 === 0) {
				$res .= str_replace('%', '%%', $part);

			} elseif ($part === '') {
				$res .= '%%';

			} elseif (isset($check[$part])) {
				throw new Nette\InvalidArgumentException('Circular reference detected for variables: ' . implode(', ', array_keys($check)) . '.');

			} else {
				try {
					$val = Nette\Utils\Arrays::get($params, explode('.', $part));
				} catch (Nette\InvalidArgumentException $e) {
					$res .= "%$part%";
					continue;
				}
				$val = self::preExpand($val, $params, $check + array($part => 1));
				if (strlen($part) + 2 === strlen($s)) {
					if (is_array($val)) {
						array_walk_recursive($val, function(&$val) use ($params, $check, $part) {
							$val = Configurator::preExpand($val, $params, $check + array($part => 1));
						});
					}
					return $val;
				}
				if (!is_scalar($val)) {
					throw new Nette\InvalidArgumentException("Unable to concatenate non-scalar parameter '$part' into '$s'.");
				}
				$res .= $val;
			}
		}
		return $res;
	}



	/********************* service factories ****************d*g**/



	private function checkTempDir()
	{
		$code = '';
		$dir = $this->params['tempDir'] . '/cache';
		umask(0000);
		@mkdir($dir, 0777); // @ - directory may exists

		// checks whether directory is writable
		$uniq = uniqid('_', TRUE);
		umask(0000);
		if (!@mkdir("$dir/$uniq", 0777)) { // @ - is escalated to exception
			throw new Nette\InvalidStateException("Unable to write to directory '$dir'. Make this directory writable.");
		}

		// tests subdirectory mode
		$useDirs = @file_put_contents("$dir/$uniq/_", '') !== FALSE; // @ - error is expected
		@unlink("$dir/$uniq/_");
		@rmdir("$dir/$uniq"); // @ - directory may not already exist

		return 'Nette\Caching\Storages\FileStorage::$useDirectories = ' . ($useDirs ? 'TRUE' : 'FALSE') . ";\n";
	}

}