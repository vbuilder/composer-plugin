<?php

/**
 * This file is part of vBuilder Framework (vBuilder FW).
 *
 * Copyright (c) 2011 Adam Staněk <adam.stanek@v3net.cz>
 *
 * For more information visit http://www.vbuilder.cz
 *
 * vBuilder FW is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * vBuilder FW is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with vBuilder FW. If not, see <http://www.gnu.org/licenses/>.
 */

namespace vBuilder\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\ScriptEvents;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\RootPackage;

/**
 * Composer plugin for vBuilder libraries
 *
 * Reads extra.vbuilder settings from composer.json.
 *
 * @author Adam Staněk (velbloud)
 * @since Jun 5, 2014
 */
class Plugin implements PluginInterface, EventSubscriberInterface {

	/** @var Composer */
	private $composer;

	/** @var IOInterface */
	private $io;

	/** @var FileSystem */
	private $fs;

	/** @var ProcessExecutor */
	private $process;

	/** @var Config */
	private $config;

	public function activate(Composer $composer, IOInterface $io) {
		$this->composer = $composer;
		$this->io = $io;
		$this->fs = new FileSystem;
		$this->process = new ProcessExecutor($this->io);
		$this->config = $this->composer->getConfig();
	}

	public static function getSubscribedEvents() {
		return array(
			ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
		);
	}

	/**
	 * Returns all installed packages (including root)
	 *
	 * @return array
	 */
	private function getPackages() {

		$ourPackages = array();

		// Dependencies
		$installedPackages = $this->composer->getRepositoryManager()
			->getLocalRepository()->getPackages();

		// Root package
		array_unshift($installedPackages, $this->composer->getPackage());

		foreach($installedPackages as $pkg) {

			// Resolve aliases
			while($pkg instanceof AliasPackage)
				$pkg = $pkg->getAliasOf();

			if(!in_array($pkg, $ourPackages))
				$ourPackages[] = $pkg;
		}

		return $ourPackages;
	}

	/**
	 * Returns absolute path to root package
	 *
	 * @return string
	 */
	private function getBasePath() {
		return $this->fs->normalizePath(realpath('.'));
	}

	/**
	 * Returns absolute path to vendor directory
	 *
	 * @return string
	 */
	private function getVendorDirPath() {
		return $this->fs->normalizePath(
			realpath($this->config->get('vendor-dir'))
		);
	}

	/**
	 * Returns absolute path to given package
	 *
	 * @param Package
	 * @return string
	 */
	private function getInstallPath(BasePackage $package) {
		return ($package instanceof RootPackage)
			? $this->getBasePath()
			: $this->composer->getInstallationManager()->getInstallPath($package);
	}

	/**
	 * On Autoloader dump
	 *
	 * @param Event
	 */
	public function onPostAutoloadDump(Event $event) {

		$config = $this->config;

		$basePath = $this->getBasePath();
		$vendorDirPath = $this->getVendorDirPath();

		// Bootstrap - Container parameters - will be passed to Configurator by
		// Nette\Configurator::addParameters()
		$bootstrapParameters = array(
			'pkg' => array()
		);

		// Bootstrap - NEON config files - will be passed to Configurator by
		// Nette\Configurator::addConfig()
		$bootstrapConfigFiles = array();

		// Bootstrap - Nette extensions - will be passed to Configurator like
		// Nette\Configurator::onCompile[] = function ($configurator, $complier) {
		//		$compiler->addExtension('name', new Class)
		// }
		$bootstrapNetteExtensions = array();

		// ---------------------------------------------------------------------

		foreach($this->getPackages() as $pkg) {

			$extra = $pkg->getExtra();
			$installPath = $this->getInstallPath($pkg);

			// -----------------------------------------------------------------

			// Prepare package name and path with placeholder
			$tokens = explode('/', $pkg->getName());

			// Prepare key in $bootstrapParameters['pkg']
			if(count($tokens) == 2) {
				if(!isset($bootstrapParameters['pkg'][$tokens[0]]))
					$bootstrapParameters['pkg'][$tokens[0]] = array();

				$bootstrapPkgInfo = &$bootstrapParameters['pkg'][$tokens[0]][$tokens[1]];
			} else
				$bootstrapPkgInfo = &$bootstrapParameters['pkg'][$tokens[0]];

			// Write package info to boostrap as a parameter
			$bootstrapPkgInfo = array(
				'dir' => $installPath
			);

			// Do we have NEON config file?
			$configFile = $installPath . '/config.neon';
			if(file_exists($configFile))
				$bootstrapConfigFiles[] = $configFile;

			// Do we need any Nette extension?
			if(isset($extra['vbuilder']['extensions'])) {
				$extensions = (array) $extra['vbuilder']['extensions'];
				foreach($extensions as $name => $className) {
					$bootstrapNetteExtensions[$name] = $className;
				}
			}

			// Generate fake autoloader files
			if(isset($extra['vbuilder']['fake-autoloader-files'])) {
				$files = (array) $extra['vbuilder']['fake-autoloader-files'];
				foreach($files as $path)
					$this->generateFakeAutoloadFile($pkg, $path);
			}
		}

		// ---------------------------------------------------------------------

		ksort($bootstrapParameters['pkg']);

		// Generate vBuilder bootstrap
		$this->generateBootstrap(
			$bootstrapParameters,
			$bootstrapConfigFiles,
			$bootstrapNetteExtensions
		);
	}


	protected function translateBootstrapPath($str) {
		return str_replace(
			"'" . $this->getVendorDirPath() . '/',
			"\$vendorDir . '/",
			$str
		);
	}

	protected function generateBootstrap(array $parameters, array $configFiles, array $extensions) {

		// Path to generated bootstrap file
		$path = $this->getVendorDirPath() . '/composer/vbuilder_bootstrap.php';
		$displayPath = $this->fs->findShortestPath($this->getBasePath(), $path);

		// Suffix
		$suffix = md5(uniqid('', true));

		// Parameters (we need to substitute absolute paths for relative)
		$exportedParameters = var_export($parameters, TRUE);
		$exportedParameters = $this->translateBootstrapPath($exportedParameters);
		$exportedParameters = str_replace("\n", "\n\t\t", $exportedParameters);

		// Config files
		if(count($configFiles)) {
			$exportedConfigFiles = '';
			foreach($configFiles as $file)
				$exportedConfigFiles .= "\$configurator->addConfig(" . var_export($file, TRUE) . ");\n\t\t";

			$exportedConfigFiles = $this->translateBootstrapPath($exportedConfigFiles);
		} else {
			$exportedConfigFiles = "// None.\n\t\t";
		}

		// Nette extensions
		if(count($extensions)) {
			$exportedExtensions =
				"\$configurator->onCompile[] = function (\$configurator, \$complier) {";

			foreach($extensions as $name => $className)
				$exportedExtensions .= ""
					. "\n\t\t\t"
					. "\$complier->addExtension(" . var_export($name, TRUE) . ", new $className);";


			$exportedExtensions .= "\n\t\t};\n\t\t";

		} else {
			$exportedExtensions = "// None.\n\t\t";
		}

		$content = <<<BOOTSTRAP_END
<?php

/**
 * @warning This file is automatically generated by Composer.
 * @see https://github.com/vbuilder/composer-plugin
 */

class vBuilderBootstrap$suffix {

	static function init(Nette\Configurator \$configurator) {

		\$vendorDir = __DIR__ . '/..';

		// Container parameters
		\$configurator->addParameters($exportedParameters);

		// NEON config files
		$exportedConfigFiles

		// Nette extensions
		$exportedExtensions
	}

}

vBuilderBootstrap$suffix::init(\$configurator);


BOOTSTRAP_END
;

		// Write
		$this->io->write("Generating $displayPath");
		file_put_contents($path, $content);
	}

	// -------------------------------------------------------------------------

	/**
	 * Generates fake autoload file pointing to real autoload.php
	 *
	 * @param BasePackage packager
	 * @param string target file path
	 */
	protected function generateFakeAutoloadFile(BasePackage $package, $targetPath) {

		// Prepare paths
		$packageInstallPath = $this->getInstallPath($package);
		$targetDir = $this->fs->normalizePath($packageInstallPath . '/' . dirname($targetPath));
		$targetBasename = basename($targetPath);

		// Create short path to fake autoload file (for display purposes)
		$displayFilePath = $this->fs->findShortestPath(
			$this->getBasePath(),
			"$targetDir/$targetBasename"
		);

		// If target directory does not exist, skip
		if(!is_dir($targetDir)) {
			$this->io->write('Skiping generation of fake autoload file: ' . $displayFilePath);
			return ;
		}

		// Find relative path from directory of fake autoload file to real autoload.php
		$relativePath = $this->fs->findShortestPath($targetDir, $this->getVendorDirPath(), TRUE);
		$autoloadPath = var_export('/' . rtrim($relativePath, '/') . '/autoload.php', TRUE);

		// Create fake autoload content
		$content = <<<BOOTSTRAP_END
<?php

/**
 * @warning This file is automatically generated by Composer.
 * @see https://github.com/vbuilder/composer-plugin
 */

return include __DIR__ . $autoloadPath;

BOOTSTRAP_END
;

		// Write
		$this->io->write('Generating fake autoload in: ' . $displayFilePath);
		file_put_contents($targetPath, $content);

		// Mark file as unchanged for Git
		if(is_dir("$packageInstallPath/.git")) {
			$exitCode = $this->process->execute(
				'git --git-dir ' . escapeshellarg("$packageInstallPath/.git") .
				' --work-tree ' . escapeshellarg($packageInstallPath) .
				' update-index --assume-unchanged ' . escapeshellarg($targetPath)
			);

			if($exitCode)
				throw new \RuntimeException('Failed to mark ' . $displayFilePath . ' as unchanged');
		}
	}

}