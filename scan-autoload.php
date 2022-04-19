<?php

/**
 * @file
 * Autoloader for php stan to be able to reach all functions.
 */

require_once '/app/drupal9/vendor//symfony/http-foundation/Request.php';

require_once '/app/drupal9/web/core/lib/Drupal/Core/Installer/InstallerRedirectTrait.php';
require_once '/app/drupal9/vendor/symfony/http-kernel/TerminableInterface.php';
require_once '/app/drupal9/vendor/symfony/http-kernel/HttpKernelInterface.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/DrupalKernelInterface.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/DrupalKernel.php';

require_once '/app/drupal9/web/core/lib/Drupal/Core/Site/Settings.php';

require_once '/app/drupal9/web/core/lib/Drupal/Core/Database/StatementInterface.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Database/Connection.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Database/Database.php';

require_once '/app/drupal9/web/core/lib/Drupal/Core/Cache/CacheableDependencyTrait.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Cache/RefinableCacheableDependencyTrait.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/DependencyInjection/DependencySerializationTrait.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Cache/CacheableDependencyInterface.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Cache/RefinableCacheableDependencyInterface.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Config/ConfigBase.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Config/StorableConfigBase.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Config/Config.php';
require_once '/app/drupal9/web/core/lib/Drupal/Core/Config/ImmutableConfig.php';
require_once '/app/drupal9//web/core/lib/Drupal.php';
