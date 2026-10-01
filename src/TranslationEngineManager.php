<?php

namespace Drupal\instant_translate;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\instant_translate\Attribute\TranslateEngine;

/**
 * Plugin manager for translation engines (#[TranslateEngine] plugins).
 */
final class TranslationEngineManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cacheBackend, ModuleHandlerInterface $moduleHandler) {
    parent::__construct(
      'Plugin/TranslationEngine',
      $namespaces,
      $moduleHandler,
      TranslationEngineInterface::class,
      TranslateEngine::class,
    );
    $this->alterInfo('instant_translate_engine_info');
    $this->setCacheBackend($cacheBackend, 'instant_translate_engines');
  }

}
