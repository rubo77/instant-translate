<?php

namespace Drupal\instant_translate;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Translates content entities automatically via an engine plugin
 * (config key "engine"; ships with "deepl" - further engines are
 * #[TranslateEngine] plugins under Plugin/TranslationEngine).
 *
 * A save of a source-language entity queues it; Drupal cron
 * (automated_cron, hourly by default) processes the queue via the
 * "instant_translate" QueueWorker (TranslateWorker, max. 60 s per
 * run), which sends the translatable text fields to the engine and
 * writes the results onto the target-language translations.
 * Language rows, the outdated flag and field-level translatability
 * come from core's content_translation; everything else lives here.
 *
 * SCOPE (instant_translate.settings)
 * - auto_mode: off | whitelist | all. Whitelist patterns:
 *   "type:id", "type:bundle", "type:*". Paragraphs of an allowed
 *   parent inherit the allowance (they carry the page text).
 * - source_language is the entity langcode sent to the engine;
 *   targets maps entity langcode => engine target code
 *   ({en: EN-GB, nl: NL}); entity_types controls which types the
 *   hooks auto-enqueue.
 * - Only translatable text props (string/text/email) reach the
 *   engine: title/name/description/info/body + translatable field_*
 *   storages; HTML fields are flagged (DeepL: tag_handling).
 *   References, URLs, boolean/numeric/meta fields are never sent.
 *   field_limit restricts a type/bundle to named fields.
 *
 * UPDATE BEHAVIOUR
 * - Every save of a source entity queues it, but an existing
 *   translation is skipped when it is not outdated and all collected
 *   fields are filled. Source edits do NOT propagate automatically:
 *   content_translation flags translations outdated only via the
 *   editor checkbox "Flag other translations as outdated" (off by
 *   default).
 * - Once outdated/incomplete, ALL collected fields of that entity
 *   are re-sent - no per-field diff. Paragraphs are separate
 *   entities: only a paragraph whose own translation is outdated is
 *   retranslated, not every paragraph of a re-saved parent.
 * - Paragraphs without text fields get an empty translation shell so
 *   references do not render in the source language.
 * - status/promote/sticky of the source row are mirrored onto the
 *   translation, the outdated flag is cleared, and parent references
 *   are repointed to the paragraph's newest revision (entity
 *   reference revisions store a fixed revision id).
 */
final class TranslationService {

  // Default target map: entity langcode => DeepL target code.
  // DeepL requires the variant suffix for English; NL has none.
  // Overridable via the "targets" config key.
  private const DEFAULT_TARGETS = ['en' => 'EN-GB', 'nl' => 'NL'];

  // Typed-data property types whose values get sent to DeepL.
  private const TEXT_PROPS = ['string', 'string_long', 'text', 'text_long', 'email'];

  // Field types whose property values contain HTML markup.
  private const HTML_FIELDS = ['text', 'text_long', 'text_with_summary'];

  // Base-field names that carry human-readable text. Custom field_*
  // storages are included separately; everything else (langcode, path,
  // meta flags, references) is never sent to DeepL.
  private const BASE_TEXT_FIELDS = ['title', 'name', 'description', 'info', 'body'];

  // Default entity types for the auto-translate hooks and the bulk
  // command; overridable via the "entity_types" config key.
  public const ENTITY_TYPES = ['node', 'taxonomy_term', 'paragraph', 'menu_link_content', 'block_content'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly QueueFactory $queueFactory,
    private readonly TranslationEngineManager $engineManager,
  ) {}

  private function config() {
    return $this->configFactory->get('instant_translate.settings');
  }

  private function log() {
    return $this->loggerFactory->get('instant_translate');
  }

  /**
   * Entity langcode of the source text sent to DeepL.
   */
  public function sourceLanguage(): string {
    // Sites updated from a version without the key fall back to 'de'.
    return $this->config()->get('source_language') ?: 'de';
  }

  /**
   * Target map: entity langcode => DeepL target language code.
   */
  public function targets(): array {
    return $this->config()->get('targets') ?: self::DEFAULT_TARGETS;
  }

  /**
   * Entity types the auto-translate hooks enqueue.
   */
  public function entityTypes(): array {
    return $this->config()->get('entity_types') ?: self::ENTITY_TYPES;
  }

  /**
   * Whether the entity is allowed to be auto-translated in the current mode.
   */
  public function mayAutoTranslate(ContentEntityInterface $entity): bool {
    $mode = $this->config()->get('auto_mode') ?: 'off';
    if ($mode === 'all') {
      return TRUE;
    }
    if ($mode !== 'whitelist') {
      return FALSE;
    }
    // Whitelist entries: "type:id", "type:bundle" or "type:*".
    $whitelist = $this->config()->get('whitelist_ids') ?: [];
    foreach (["{$entity->getEntityTypeId()}:{$entity->id()}",
      "{$entity->getEntityTypeId()}:{$entity->bundle()}",
      "{$entity->getEntityTypeId()}:*"] as $key) {
      if (in_array($key, $whitelist, TRUE)) {
        return TRUE;
      }
    }
    // Paragraph items of whitelisted parents inherit the allowance.
    // parent_id/parent_type are stored fields, so this also works for
    // paragraphs loaded outside a field-item context.
    if ($entity->getEntityTypeId() === 'paragraph') {
      $parentType = $entity->get('parent_type')->value ?? NULL;
      $parentId = $entity->get('parent_id')->value ?? NULL;
      if ($parentType && $parentId) {
        return in_array("$parentType:$parentId",
          $this->config()->get('whitelist_ids') ?: [], TRUE);
      }
    }
    return FALSE;
  }

  /**
   * Queue an entity for translation.
   */
  public function enqueue(ContentEntityInterface $entity): void {
    if (!$entity->isDefaultTranslation()
      || $entity->language()->getId() !== $this->sourceLanguage()
      || !in_array($entity->getEntityTypeId(), $this->entityTypes(), TRUE)
      || !$this->mayAutoTranslate($entity)) {
      return;
    }
    $this->queueFactory->get('instant_translate')->createItem([
      'entity_type' => $entity->getEntityTypeId(),
      'id' => $entity->id(),
    ]);
    // Referenced paragraphs carry the actual page text - enqueue them too,
    // unless the entity is field-limited (a title-only node keeps its
    // page paragraphs untranslated).
    if ($this->fieldLimit($entity) === NULL) {
      foreach ($this->referencedParagraphs($entity) as $paragraph) {
        $this->enqueue($paragraph);
      }
    }
  }

  /**
   * Field restriction for an entity, keyed by "type:bundle" or "type".
   *
   * @return string[]|NULL
   */
  public function fieldLimit(ContentEntityInterface $entity): ?array {
    $limits = $this->config()->get('field_limit') ?: [];
    return $limits[$entity->getEntityTypeId() . ':' . $entity->bundle()]
      ?? $limits[$entity->getEntityTypeId()]
      ?? NULL;
  }

  /**
   * Load paragraph entities referenced by ERR fields on this entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function referencedParagraphs(ContentEntityInterface $entity): array {
    $paragraphs = [];
    $map = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('entity_reference_revisions');
    $fieldStorage = $this->entityTypeManager->getStorage('field_storage_config');
    foreach ($map[$entity->getEntityTypeId()] ?? [] as $fieldName => $info) {
      $def = $fieldStorage->load($entity->getEntityTypeId() . '.' . $fieldName);
      if (!$def || $def->getSetting('target_type') !== 'paragraph' || !$entity->hasField($fieldName)) {
        continue;
      }
      foreach ($entity->get($fieldName) as $item) {
        if ($item->entity instanceof ContentEntityInterface) {
          $paragraphs[$item->entity->id()] = $item->entity;
        }
      }
    }
    return $paragraphs;
  }

  /**
   * Translate one entity into the configured target languages.
   *
   * @param string|null $onlyLangcode
   *   Restrict to a single entity langcode when set.
   *
   * @return string[] langcodes that were (re)translated.
   */
  public function translateEntity(ContentEntityInterface $entity, ?string $onlyLangcode = NULL): array {
    if (!$entity->isDefaultTranslation() || $entity->language()->getId() !== $this->sourceLanguage()) {
      return [];
    }
    $engineId = $this->config()->get('engine') ?: 'deepl';
    /** @var \Drupal\instant_translate\TranslationEngineInterface $engine */
    $engine = $this->engineManager->createInstance($engineId);
    $done = [];

    foreach ($this->targets() as $langcode => $deeplCode) {
      if ($onlyLangcode !== NULL && $langcode !== $onlyLangcode) {
        continue;
      }
      // Collect field item properties to translate.
      // $items[$field][$delta][$prop] = ['text' => ..., 'html' => bool]
      // field_limit config: only these fields are sent to DeepL, keyed
      // by "type" or "type:bundle" (e.g. taxonomy_term => [name] for the
      // navigator labels, node:verfahren => [title] - detail pages stay
      // out of pilot scope anyway).
      $fieldLimit = $this->fieldLimit($entity);
      $items = [];
      foreach ($entity->getTranslatableFields() as $fieldName => $field) {
        $fieldDef = $field->getFieldDefinition();
        if (is_array($fieldLimit) && !in_array($fieldName, $fieldLimit, TRUE)) {
          continue;
        }
        if ($fieldDef->isReadOnly()
          || (!str_starts_with($fieldName, 'field_') && !in_array($fieldName, self::BASE_TEXT_FIELDS, TRUE))) {
          continue;
        }
        $isHtml = in_array($fieldDef->getType(), self::HTML_FIELDS, TRUE);
        foreach ($field as $delta => $item) {
          foreach ($item->getProperties() as $prop => $data) {
            $type = $data->getDataDefinition()->getDataType();
            if (!in_array($type, self::TEXT_PROPS, TRUE)) {
              continue;
            }
            $text = (string) $data->getValue();
            if (trim($text) === '') {
              continue;
            }
            $items[$fieldName][$delta][$prop] = ['text' => $text, 'html' => $isHtml];
          }
        }
      }
      if (empty($items)) {
        // Paragraphs with only reference fields still need a translation
        // shell: without it the renderer falls back to the de entity and
        // nested references (e.g. a banner node) render in German.
        if ($entity->getEntityTypeId() === 'paragraph' && !$entity->hasTranslation($langcode)) {
          $shell = $entity->addTranslation($langcode);
          if ($shell->hasField('status')) {
            $shell->set('status', 1);
          }
          $shell->save();
          $this->log()->notice('[ITRANS] @type:@id -> @lang (shell, no text fields)', [
            '@type' => $entity->getEntityTypeId(), '@id' => $entity->id(), '@lang' => $langcode,
          ]);
          $done[] = $langcode;
        }
        continue;
      }

      if ($entity->hasTranslation($langcode)) {
        $translation = $entity->getTranslation($langcode);
        // Keep existing translations unless the source was changed
        // (content_translation marks them outdated then) - but a
        // translation whose collected text fields are all empty
        // (e.g. produced by a former field_limit run) counts as
        // incomplete and is redone.
        $incomplete = FALSE;
        foreach (array_keys($items) as $fieldName) {
          if ($translation->hasField($fieldName) && $translation->get($fieldName)->isEmpty()) {
            $incomplete = TRUE;
            break;
          }
        }
        if (!$incomplete && !\Drupal::service('content_translation.manager')->getTranslationMetadata($translation)->isOutdated()) {
          continue;
        }
      }

      // Split into plain/HTML batches; the engine chunks internally.
      $plain = [];
      $html = [];
      $count = 0;
      foreach ($items as $fieldName => $deltas) {
        foreach ($deltas as $delta => $props) {
          foreach ($props as $prop => $row) {
            $count += mb_strlen($row['text']);
            $key = "$fieldName\x1F$delta\x1F$prop";
            $row['html'] ? $html[$key] = $row['text'] : $plain[$key] = $row['text'];
          }
        }
      }

      try {
        $results = [];
        foreach ([FALSE => $plain, TRUE => $html] as $htmlFlag => $batch) {
          if (!$batch) {
            continue;
          }
          $translated = $engine->translateTexts(array_values($batch), $this->sourceLanguage(), $deeplCode, $htmlFlag);
          $results += array_combine(array_keys($batch), $translated);
        }
      }
      catch (\Throwable $e) {
        $this->log()->error('[ITRANS] Engine "@engine" failed for @type:@id -> @lang: @msg', [
          '@engine' => $engineId,
          '@type' => $entity->getEntityTypeId(), '@id' => $entity->id(),
          '@lang' => $langcode, '@msg' => $e->getMessage(),
        ]);
        continue;
      }

      // Write translated values onto the target-language translation.
      $translation = $entity->hasTranslation($langcode)
        ? $entity->getTranslation($langcode)
        : $entity->addTranslation($langcode);
      foreach ($items as $fieldName => $deltas) {
        if (!$translation->hasField($fieldName)) {
          continue;
        }
        $target = [];
        foreach ($deltas as $delta => $props) {
          $sourceItem = $entity->get($fieldName)->get($delta)->toArray();
          foreach ($props as $prop => $row) {
            $key = "$fieldName\x1F$delta\x1F$prop";
            if (isset($results[$key])) {
              $sourceItem[$prop] = $results[$key];
            }
          }
          $target[] = $sourceItem;
        }
        $translation->set($fieldName, $target);
      }
      // Translations start unpublished under content_translation; the
      // pilot auto-publishes since nobody reviews DeepL output by hand.
      // status/promote/sticky are stored per language row, so the
      // translation must mirror the source row: an unpublished or
      // unpromoted source must not leak into other languages, and
      // sticky drives the themen_matrix 1er-block display.
      foreach (['status', 'promote', 'sticky'] as $baseField) {
        if ($translation->hasField($baseField)) {
          $translation->set($baseField, $entity->get($baseField)->value);
        }
      }
      if ($translation->hasField('content_translation_status')) {
        $translation->set('content_translation_status', $entity->isPublished() ? 1 : 0);
      }
      if ($translation->hasField('content_translation_outdated')) {
        $translation->set('content_translation_outdated', 0);
      }
      $translation->save();
      if ($entity->getEntityTypeId() === 'paragraph') {
        $this->repointParagraphRefs($entity);
      }
      $done[] = $langcode;
      $this->log()->notice('[ITRANS] @type:@id -> @lang (@chars chars)', [
        '@type' => $entity->getEntityTypeId(), '@id' => $entity->id(),
        '@lang' => $langcode, '@chars' => $count,
      ]);
    }
    return $done;
  }

  /**
   * Point field_paragraph references at the paragraph's latest revision.
   *
   * ERR fields store a fixed target_revision_id. Translating a paragraph
   * creates a NEW revision carrying en/nl rows; parents still referencing
   * the old revision would render German text forever.
   */
  public function repointParagraphRefs(ContentEntityInterface $paragraph): void {
    $revId = $paragraph->getRevisionId() ?: $paragraph->getLoadedRevisionId();
    if (!$revId) {
      return;
    }
    $db = \Drupal::database();
    $map = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('entity_reference_revisions');
    $fieldStorage = $this->entityTypeManager->getStorage('field_storage_config');
    foreach ($map as $entityType => $fields) {
      foreach ($fields as $fieldName => $info) {
        $def = $fieldStorage->load("$entityType.$fieldName");
        if (!$def || $def->getSetting('target_type') !== 'paragraph') {
          continue;
        }
        foreach (["{$entityType}__{$fieldName}", "{$entityType}_revision__{$fieldName}"] as $table) {
          if (!$db->schema()->tableExists($table)) {
            continue;
          }
          $n = $db->update($table)
            ->fields(["{$fieldName}_target_revision_id" => $revId])
            ->condition("{$fieldName}_target_id", $paragraph->id())
            ->execute();
          if ($n) {
            $this->log()->notice('[ITRANS] repointed @n refs @table -> paragraph:@pid rev @rev', [
              '@n' => $n, '@table' => $table, '@pid' => $paragraph->id(), '@rev' => $revId,
            ]);
          }
        }
        // Invalidate caches of the referencing entities.
        $entityIds = $db->select("{$entityType}__{$fieldName}", 't')
          ->fields('t', ['entity_id'])
          ->condition("{$fieldName}_target_id", $paragraph->id())
          ->execute()->fetchCol();
        if ($entityIds) {
          $this->entityTypeManager->getStorage($entityType)->resetCache($entityIds);
          \Drupal\Core\Cache\Cache::invalidateTags(array_map(
            fn($id) => "$entityType:$id", $entityIds));
        }
      }
    }
  }

}
