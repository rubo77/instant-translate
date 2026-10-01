<?php

namespace Drupal\instant_translate\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\instant_translate\TranslationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Translates queued entities via DeepL during cron.
 *
 * @QueueWorker(
 *   id = "instant_translate",
 *   title = @Translation("DeepL auto-translation"),
 *   cron = {"time" = 60}
 * )
 */
class TranslateWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TranslationService $translator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('instant_translate.translator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $entity = $this->entityTypeManager->getStorage($data['entity_type'])->load($data['id']);
    if (!$entity) {
      // Entity deleted between queueing and processing - nothing to do.
      return;
    }
    $this->translator->translateEntity($entity);
  }

}
