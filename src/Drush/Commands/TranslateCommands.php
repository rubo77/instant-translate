<?php

namespace Drupal\instant_translate\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Bulk translation commands.
 */
final class TranslateCommands extends DrushCommands {

  /**
   * Translate entities via DeepL.
   *
   * Respects instant_translate.settings:auto_mode unless --all is given.
   */
  #[CLI\Command(name: 'instant_translate:translate-all', aliases: ['itr'])]
  #[CLI\Option(name: 'type', description: 'Entity type id (or "each" for all configured entity types)')]
  #[CLI\Option(name: 'only', description: 'Comma-separated entity IDs to restrict to')]
  #[CLI\Option(name: 'lang', description: 'Only this target langcode, default all configured targets')]
  #[CLI\Option(name: 'dry', description: 'Count characters only, do not translate')]
  #[CLI\Option(name: 'all', description: 'Ignore auto_mode whitelist (use after approval)')]
  public function translateAll(array $options = ['type' => 'node', 'only' => NULL, 'lang' => NULL, 'dry' => FALSE, 'all' => FALSE]): void {
    $translator = \Drupal::service('instant_translate.translator');
    $type = $options['type'] ?: 'node';
    $types = $type === 'each' ? $translator->entityTypes() : [$type];
    $only = $options['only'] ? array_map('trim', explode(',', $options['only'])) : NULL;

    $totalChars = 0;
    $done = 0;
    foreach ($types as $entityType) {
      $storage = \Drupal::entityTypeManager()->getStorage($entityType);
      $query = $storage->getQuery()->accessCheck(FALSE);
      $key = $storage->getEntityType()->getKey('id');
      if ($only) {
        $query->condition($key, $only, 'IN');
      }
      $ids = $query->execute();
      foreach ($ids as $id) {
        $entity = $storage->load($id);
        if (!$entity || !$entity->isDefaultTranslation() || $entity->language()->getId() !== $translator->sourceLanguage()) {
          continue;
        }
        if (empty($options['all']) && !$translator->mayAutoTranslate($entity)) {
          continue;
        }
        if (!empty($options['dry'])) {
          $totalChars += $this->countChars($entity);
          $done++;
          continue;
        }
        $langs = $translator->translateEntity($entity, $options['lang'] ?: NULL);
        if ($langs) {
          $this->output()->writeln("[ITRANS] $entityType:$id -> " . implode(',', $langs));
        }
        // Referenced paragraphs carry the page text - translate them too,
        // unless the entity itself is field-limited (e.g. verfahren nodes
        // translate titles only; their page content stays out of scope).
        foreach ($translator->fieldLimit($entity) === NULL ? $translator->referencedParagraphs($entity) : [] as $paragraph) {
          $plangs = $translator->translateEntity($paragraph, $options['lang'] ?: NULL);
          if ($plangs) {
            $this->output()->writeln("[ITRANS] paragraph:" . $paragraph->id() . " -> " . implode(',', $plangs));
          }
        }
        $done++;
      }
    }
    $this->output()->writeln($options['dry']
      ? "[ITRANS] DRY: $done entities, ~$totalChars chars"
      : "[ITRANS] processed $done entities");
  }

  /**
   * Rough character estimate of the translatable text of an entity.
   */
  private function countChars($entity): int {
    $chars = 0;
    foreach ($entity->getTranslatableFields() as $field) {
      foreach ($field as $item) {
        foreach ($item->getProperties() as $data) {
          if (in_array($data->getDataDefinition()->getDataType(), ['string', 'string_long', 'text', 'text_long', 'email'], TRUE)) {
            $chars += mb_strlen((string) $data->getValue());
          }
        }
      }
    }
    return $chars;
  }

}
