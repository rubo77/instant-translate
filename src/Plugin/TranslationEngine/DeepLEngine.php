<?php

namespace Drupal\instant_translate\Plugin\TranslationEngine;

use DeepL\DeepLClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\instant_translate\Attribute\TranslateEngine;
use Drupal\instant_translate\TranslationEngineInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * DeepL translation engine.
 *
 * The API key is read from settings.php (never from config):
 *   $settings['instant_translate.deepl_key'] = '...';
 */
#[TranslateEngine(
  id: 'deepl',
  label: new TranslatableMarkup('DeepL'),
)]
final class DeepLEngine extends PluginBase implements TranslationEngineInterface, ContainerFactoryPluginInterface {

  private ?DeepLClient $client = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Settings $settings,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('config.factory'),
      $container->get('settings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function translateTexts(array $texts, string $sourceLangcode, string $targetCode, bool $html): array {
    $options = ['preserve_formatting' => TRUE];
    if ($html) {
      $options['tag_handling'] = 'html';
    }
    $results = [];
    foreach (array_chunk($texts, 40) as $chunk) {
      foreach ($this->client()->translateText(array_values($chunk), $sourceLangcode, $targetCode, $options) as $i => $res) {
        $results[array_keys($chunk)[$i]] = $res->text;
      }
    }
    return $results;
  }

  private function client(): DeepLClient {
    if ($this->client === NULL) {
      $key = $this->settings->get('instant_translate.deepl_key');
      if (!$key) {
        throw new \RuntimeException('[ITRANS] settings[instant_translate.deepl_key] missing');
      }
      $server = $this->configFactory->get('instant_translate.settings')->get('deepl_server')
        ?: 'https://api-free.deepl.com';
      $this->client = new DeepLClient($key, ['server_url' => $server]);
    }
    return $this->client;
  }

}
