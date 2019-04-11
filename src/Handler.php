<?php

namespace Grasmash\ComposerScaffold;

use Composer\Package\Package;
use Composer\Script\Event;
use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Core class of the plugin, contains all logic which files should be fetched.
 */
class Handler {

  const PRE_COMPOSER_SCAFFOLD_CMD = 'pre-composer-scaffold-cmd';
  const POST_COMPOSER_SCAFFOLD_CMD = 'post-composer-scaffold-cmd';

  /**
   * The Composer service.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * Composer's IO service.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * An array of allowed packages keyed by package name.
   *
   * @var \Composer\Package\Package[]
   */
  protected $allowedPackages;

  /**
   * Handler constructor.
   *
   * @param \Composer\Composer $composer
   *   The Composer service.
   * @param \Composer\IO\IOInterface $io
   *   The Composer io service.
   */
  public function __construct(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * Post install command event to execute the scaffolding.
   *
   * @param \Composer\Script\Event $event
   *   The Composer event.
   */
  public function onPostCmdEvent(Event $event) {
    $this->moveAllFiles();
    // Generate the autoload.php file after generating the scaffold files.
    // TODO: This should only happen if drupal/core is scaffolded.
    // Maybe this should be done in response to some metadata in the
    // scaffold extra data rather than auto-magicly.
    // $this->generateAutoload();
  }

  /**
   * Gets the array of file mappings provided by a given package.
   *
   * @param \Composer\Package\Package $package
   *   The Composer package from which to get the file mappings.
   *
   * @return array
   *   An associative array of file mappings, keyed by relative source file
   *   path. For example:
   *   [
   *     'path/to/source/file' => 'path/to/destination',
   *     'path/to/source/file' => false,
   *   ]
   */
  public function getPackageFileMappings(Package $package) {
    $package_extra = $package->getExtra();
    if (!array_key_exists('composer-scaffold', $package_extra) || !array_key_exists('file-mapping', $package_extra['composer-scaffold'])) {
      $this->io->writeError("The allowed package {$package->getName()} does not provide a file mapping for Composer Scaffold.");
      $package_file_mappings = [];
    }
    else {
      $package_file_mappings = $package_extra['composer-scaffold']['file-mapping'];
    }

    return $package_file_mappings;
  }

  /**
   * Copies all scaffold files from source to destination.
   */
  public function moveAllFiles() {
    // Call any pre-scaffold scripts that may be defined.
    $dispatcher = new EventDispatcher($this->composer, $this->io);
    $dispatcher->dispatch(self::PRE_COMPOSER_SCAFFOLD_CMD);

    $this->allowedPackages = $this->getAllowedPackages();
    $file_mappings = $this->getFileMappingsFromPackages($this->allowedPackages);
    $file_mappings = $this->replaceWebRootToken($file_mappings);
    $this->moveFiles($file_mappings);

    // Call post-scaffold scripts.
    $dispatcher->dispatch(self::POST_COMPOSER_SCAFFOLD_CMD);
  }

  /**
   * Generate the autoload file at the project root.
   *
   * Include the autoload file that Composer generated.
   */
  public function generateAutoload() {
    $vendorPath = $this->getVendorPath();
    $webroot = $this->getWebRoot();

    // Calculate the relative path from the webroot (location of the
    // project autoload.php) to the vendor directory.
    $fs = new SymfonyFilesystem();
    $relativeVendorPath = $fs->makePathRelative($vendorPath, realpath($webroot));

    $fs->dumpFile($webroot . "/autoload.php", $this->autoLoadContents($relativeVendorPath));
  }

  /**
   * Build the contents of the autoload file.
   *
   * @return string
   *   Return the contents for the autoload.php.
   */
  protected function autoLoadContents($relativeVendorPath) {
    $relativeVendorPath = rtrim($relativeVendorPath, '/');

    $autoloadContents = <<<EOF
<?php

/**
 * @file
 * Includes the autoloader created by Composer.
 *
 * This file was generated by drupal-composer/drupal-scaffold.
 * https://github.com/drupal-composer/drupal-scaffold
 *
 * @see composer.json
 * @see index.php
 * @see core/install.php
 * @see core/rebuild.php
 * @see core/modules/statistics/statistics.php
 */

return require __DIR__ . '/$relativeVendorPath/autoload.php';

EOF;
    return $autoloadContents;
  }

  /**
   * Get the path to the 'vendor' directory.
   *
   * @return string
   *   The file path of the vendor directory.
   */
  public function getVendorPath() {
    $config = $this->composer->getConfig();
    $filesystem = new Filesystem();
    $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
    $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));

    return $vendorPath;
  }

  /**
   * Retrieve the path to the web root.
   *
   * @return string
   *   The file path of the web root.
   *
   * @throws \Exception
   */
  public function getWebRoot() {
    $options = $this->getOptions();
    // @todo Allow packages to set web root location?
    if (!array_key_exists('web-root', $options['locations'])) {
      throw new \Exception("The extra.composer-scaffold.location.web-root is not set in composer.json.");
    }
    $webroot = $options['locations']['web-root'];

    return $webroot;
  }

  /**
   * Retrieve a package from the current composer process.
   *
   * @param string $name
   *   Name of the package to get from the current composer installation.
   *
   * @return \Composer\Package\PackageInterface
   *   The Composer package.
   */
  protected function getPackage($name) {
    $package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');
    if (is_null($package)) {
      $this->io->write("<comment>Composer Scaffold could not find installed package `$name`.</comment>");
    }

    return $package;
  }

  /**
   * Retrieve options from optional "extra" configuration.
   *
   * @return array
   *
   */
  protected function getOptions() {
    $extra = $this->composer->getPackage()->getExtra() + ['composer-scaffold' => []];
    $options = $extra['composer-scaffold'] + [
      "allowed-packages" => [],
      "locations" => [],
      "symlink" => FALSE,
      "file-mapping" => [],
    ];

    return $options;
  }

  /**
   * Merges arrays recursively while preserving.
   *
   * @param array $array1
   *   The first array.
   * @param array $array2
   *   The second array.
   *
   * @return array
   *   The merged array.
   *
   * @see http://php.net/manual/en/function.array-merge-recursive.php#92195
   */
  public static function arrayMergeRecursiveDistinct(
        array &$array1,
        array &$array2
    ) {
    $merged = $array1;
    foreach ($array2 as $key => &$value) {
      if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
        $merged[$key] = self::arrayMergeRecursiveDistinct(
        $merged[$key],
        $value
        );
      }
      else {
        $merged[$key] = $value;
      }
    }
    return $merged;
  }

  /**
   * Replaces '[web-root]' token in file mappings.
   *
   * @param array $file_mappings
   *   An multidimensional array of file mappings, as returned by
   *   self::getFileMappingsFromPackages().
   *
   * @return array
   *   An multidimensional array of file mappings with tokens replaced.
   */
  protected function replaceWebRootToken($file_mappings) {
    $webroot = $this->getWebRoot();
    $fs = new Filesystem();
    $fs->ensureDirectoryExists($webroot);
    $webroot = realpath($webroot);
    foreach ($file_mappings as $package_name => $files) {
      foreach ($files as $source => $destination) {
        if (is_string($destination)) {
          $file_mappings[$package_name][$source] = str_replace('[web-root]', $webroot, $destination);
        }
      }
    }
    return $file_mappings;
  }

  /**
   * Copy all files, as defined by $file_mappings.
   *
   * @param array $file_mappings
   *   An multidimensional array of file mappings, as returned by
   *   self::getFileMappingsFromPackages().
   */
  protected function moveFiles($file_mappings) {
    $options = $this->getOptions();
    $symlink = $options['symlink'];
    $fs = new Filesystem();

    foreach ($file_mappings as $package_name => $files) {
      if (!$this->getAllowedPackage($package_name)) {
        // TODO: We probably don't want to emit an error here. For early development debugging.
        $this->io->writeError("FYI <info>$package_name</info> is not allowed so we are going to skip it.");
        continue;
      }
      $this->io->write("Scaffold <info>$package_name</info>:");
      foreach ($files as $source => $destination) {
        if ($destination) {
          $package_path = $this->getPackagePath($package_name);
          $source_path = $package_path . '/' . $source;
          if (!file_exists($source_path)) {
            $this->io->writeError("Could not find source file $source_path for package $package_name\n");
            continue;
          }
          if (is_dir($source_path)) {
            $this->io->writeError("$source_path in $package_name is a directory; only files may be scaffolded.");
            continue;
          }
          // Get rid of the destination if it exists, and make sure that
          // the directory where it's going to be placed exists.
          @unlink($destination);
          $fs->ensureDirectoryExists(dirname($destination));
          $success = FALSE;
          if ($symlink) {
            try {
              $success = $fs->relativeSymlink($source_path, $destination);
            }
            catch (\Exception $e) {
            }
          }
          else {
            $success = copy($source_path, $destination);
          }
          $verb = $symlink ? 'symlink' : 'copy';
          if (!$success) {
            $this->io->writeError("Could not $verb source file $source_path to $destination");
          }
          else {
            // TODO: Composer status messages look like this:
            //   - Installing fixtures/scaffold-override-fixture (dev-master): Symlinking from ../scaffold-override-fixture
            // We should unify and perhaps use a relative filepath instead of $destination,
            // which is a full path.
            $this->io->write("  - $verb source file <info>$source</info> to $destination");
          }
        }
      }
    }
  }

  /**
   * Gets an allowed package from $this->allowedPackages array.
   *
   * @param string $package_name
   *   The Composer package name. E.g., drupal/core.
   *
   * @return \Composer\Package\Package|null
   *   The allowed Composer package, if it exists.
   */
  public function getAllowedPackage($package_name) {
    if (array_key_exists($package_name, $this->allowedPackages)) {
      return $this->allowedPackages[$package_name];
    }

    return NULL;
  }

  /**
   * Gets a consolidated list of file mappings from all allowed packages.
   *
   * @param \Composer\Package\Package[] $allowed_packages
   *   A multidimensional array of file mappings, as returned by
   *   self::getAllowedPackages().
   *
   * @return array
   *   An multidimensional array of file mappings, which looks like this:
   *   [
   *     'drupal/core' => [
   *       'path/to/source/file' => 'path/to/destination',
   *       'path/to/source/file' => false,
   *     ],
   *     'some/package' => [
   *       'path/to/source/file' => 'path/to/destination',
   *     ],
   *   ]
   */
  protected function getFileMappingsFromPackages($allowed_packages): array {
    $file_mappings = [];
    foreach ($allowed_packages as $name => $package) {
      $package_file_mappings = $this->getPackageFileMappings($package);
      // @todo Write test to ensure overriding occurs as indended.
      $file_mappings = self::arrayMergeRecursiveDistinct(
        $file_mappings,
        $package_file_mappings
      );
    }
    return $file_mappings;
  }

  /**
   * Gets a list of all packages that are allowed to copy scaffold files.
   *
   * Configuration for packages specified later will override configuration
   * specified by packages listed earlier. In other words, the last listed
   * package has the highest priority. The root package will always be returned
   * at the end of the list.
   *
   * @return \Composer\Package\Package[]
   */
  protected function getAllowedPackages(): array {
    $options = $this->getOptions();
    $allowed_packages_list = $options['allowed-packages'];

    $allowed_packages = [];
    foreach ($allowed_packages_list as $name) {
      $package = $this->getPackage($name);
      if (!is_null($package)) {
        $allowed_packages[$name] = $package;
      }
    }

    // Add root package at end.
    $allowed_packages[$this->composer->getPackage()
      ->getName()] = $this->composer->getPackage();

    return $allowed_packages;
  }

  /**
   * Gets the file path of a package.
   *
   * @param string $package_name
   *   The package name.
   *
   * @return string
   *   The file path.
   */
  protected function getPackagePath(
    $package_name
  ) {
    $installationManager = $this->composer->getInstallationManager();
    $root_package = $this->composer->getPackage();
    $composer_root = $installationManager->getInstallPath($root_package);
    if ($package_name == $root_package->getName()) {
      $package_path = $composer_root;
    }
    else {
      $package_path = $installationManager->getInstallPath($this->getPackage($package_name));
    }
    return $package_path;
  }

}
