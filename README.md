# Instant Translate

Translates content entities from the source language to the configured
target languages via a pluggable translation engine — automatically on
save, in the background via cron, with no manual translation jobs or
review UI. Ships with a DeepL engine; other providers can be added as
`#[TranslateEngine]` plugins under `Plugin/TranslationEngine`
(implement `TranslationEngineInterface`).

This is a lightweight custom integration, not a translation management
system: Drupal's `content_translation` module provides the translation
rows, the outdated flag and field-level translatability; everything else
(queueing, field collection, DeepL batching, writing translations) is
implemented here.

## How it works

1. `hook_entity_insert()` / `hook_entity_update()` enqueue every saved
   source-language entity whose type is configured in `entity_types`
   and that passes the `auto_mode` check.
2. The `instant_translate` queue is processed during cron
   (`TranslateWorker`, max. 60 s per cron run): with core's
   `automated_cron` that means queued items are translated in the
   background **once per hour** — no editor action needed once an
   entity is flagged outdated. Interval is configurable under
   `/admin/config/system/cron`; manually via `drush queue:run
   instant_translate` / `drush cron`.
3. The worker collects the entity's translatable text properties
   (plain text and email types; text fields use DeepL's HTML tag
   handling so markup survives), sends them in batches of 40 and writes
   the results onto the target-language entity translations.

## What gets sent to DeepL

- Base text fields (`title`, `name`, `description`, `info`, `body`)
  plus every `field_*` storage marked translatable — property types
  `string`, `string_long`, `text`, `text_long`, `email` only.
- Never sent: references (entity_reference, file, image), URLs, domain
  fields, boolean/numeric/meta properties, untranslatable fields.
- `field_limit` can restrict a type or bundle to named fields
  (e.g. `node:article => [title]` for label-only translation).

## Scope: `auto_mode`

| Mode        | Effect |
|-------------|--------|
| `off`       | never translate automatically (default) |
| `whitelist` | only entities matching `whitelist_ids` |
| `all`       | every source-language entity |

Whitelist patterns: `type:id` (single entity), `type:bundle` (all of a
bundle), `type:*` (all of a type). Paragraphs referenced by an allowed
parent inherit the allowance — they carry the actual page text.

## Update behaviour (important)

- Every save of a source entity queues it, but an existing translation
  is **skipped** when it is not flagged outdated and all collected text
  fields are filled. Editing the source does **not** update translations
  by itself: `content_translation` only marks translations outdated when
  the editor checks *"Flag other translations as outdated"* on save
  (unchecked by default).
- Once a translation is outdated or incomplete, **all** collected text
  fields of that entity are re-sent — there is no per-field diffing.
- Paragraphs are separate entities: only a paragraph whose own
  translation was flagged gets retranslated, not every paragraph of a
  re-saved parent node.
- Paragraphs without text fields get an empty translation shell so the
  renderer does not fall back to the source-language entity for nested
  references.
- `status` / `promote` / `sticky` of the source row are mirrored onto
  the translation row, the outdated flag is cleared, and parent
  entity-reference-revisions fields are repointed to the paragraph's
  newest revision (ERR stores a fixed revision id).

## Configuration (`instant_translate.settings`)

```yaml
auto_mode: off               # off | whitelist | all
whitelist_ids: []            # e.g. ['node:1', 'node:article', 'taxonomy_term:*']
field_limit: {  }            # e.g. {'node:verfahren': ['title']}
engine: 'deepl'              # translation engine plugin id
deepl_server: 'https://api-free.deepl.com'   # or api.deepl.com (pro)
source_language: 'de'        # entity langcode sent to the engine
targets:                     # entity langcode => engine target code
  en: 'EN-GB'
  nl: 'NL'
entity_types:                # types enqueued by the save hooks
  - node
  - taxonomy_term
  - paragraph
  - menu_link_content
  - block_content
```

The DeepL API key is read from `settings.php` (never from config):

```php
$settings['instant_translate.deepl_key'] = 'xxxxxxxx-xxxx-…:fx';
```

## Drush

```bash
# Translate everything allowed by auto_mode:
drush instant_translate:translate-all --type=node

# Ignore the whitelist (full rollout), dry run first:
drush instant_translate:translate-all --type=each --all --dry
drush instant_translate:translate-all --type=each --all

# Restrict to entities / one language:
drush instant_translate:translate-all --type=node --only=1,89 --lang=en
```

`--dry` counts the characters that would be sent — useful to estimate
DeepL usage before a rollout.

## Requirements

- Drupal 11, `language` + `content_translation` enabled
- `paragraphs` module when paragraph support is wanted
- For the bundled DeepL engine: `deeplcom/deepl-php`
  (`composer require deeplcom/deepl-php`) and a DeepL API key
  (free tier works; set `deepl_server` accordingly)

## Limitations

- No per-field change detection: an outdated translation resends every
  collected text field of that entity.
- No review workflow: DeepL output is written directly (published state
  mirrors the source). Add TMGMT if you need translator review.
- Config entities (menus, views, blocks config) are not translated —
  use core Configuration Translation for those.
