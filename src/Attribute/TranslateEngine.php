<?php

namespace Drupal\instant_translate\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Attribute for translation engine plugins.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TranslateEngine extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
