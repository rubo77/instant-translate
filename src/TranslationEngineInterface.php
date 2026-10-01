<?php

namespace Drupal\instant_translate;

/**
 * A translation provider engine (DeepL, Google, ...).
 *
 * Engines are plugins of type "TranslationEngine" discovered via the
 * #[TranslateEngine] attribute; the active engine is selected via the
 * "engine" key of instant_translate.settings.
 */
interface TranslationEngineInterface {

  /**
   * Translate a list of texts.
   *
   * @param string[] $texts
   *   The source texts.
   * @param string $sourceLangcode
   *   Entity langcode of the source text (e.g. 'de').
   * @param string $targetCode
   *   Provider-specific target code from the "targets" config map
   *   (e.g. 'EN-GB'), NOT the entity langcode.
   * @param bool $html
   *   TRUE when the texts contain HTML markup the provider should keep.
   *
   * @return string[]
   *   Translated texts in the same order as $texts.
   */
  public function translateTexts(array $texts, string $sourceLangcode, string $targetCode, bool $html): array;

}
