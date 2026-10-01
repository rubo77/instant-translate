<?php

namespace Drupal\instant_translate_test\Plugin\TranslationEngine;

use Drupal\instant_translate\Attribute\TranslateEngine;
use Drupal\instant_translate\TranslationEngineInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Test engine that echoes back texts with the target code prefixed.
 */
#[TranslateEngine(
  id: 'fake',
  label: new TranslatableMarkup('Fake test engine'),
)]
class FakeEngine implements TranslationEngineInterface {

  /**
   * {@inheritdoc}
   */
  public function translateTexts(array $texts, string $sourceLangcode, string $targetCode, bool $html): array {
    return array_map(
      static fn(string $text): string => '[' . $targetCode . '] ' . $text,
      $texts,
    );
  }

}
