<?php

declare(strict_types=1);

namespace Drupal\Tests\instant_translate\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for the TranslationService.
 */
#[Group('instant_translate')]
#[RunTestsInSeparateProcesses]
final class TranslationServiceTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'filter',
    'text',
    'language',
    'content_translation',
    'instant_translate',
    'instant_translate_test',
  ];

  /**
   * The service under test.
   */
  protected \Drupal\instant_translate\TranslationService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'instant_translate']);

    $this->createContentType(['type' => 'test', 'name' => 'Test']);
    $this->createContentType(['type' => 'other', 'name' => 'Other']);

    $translation_manager = $this->container->get('content_translation.manager');
    $translation_manager->setEnabled('node', 'test', TRUE);
    $translation_manager->setEnabled('node', 'other', TRUE);

    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('nl')->save();

    $this->service = \Drupal::service('instant_translate.translator');
  }

  /**
   * A de node must not enqueue when auto_mode is off.
   */
  public function testEnqueueRespectsOffMode(): void {
    $node = $this->createNode();
    $this->service->enqueue($node, 'insert');
    $this->assertSame(0, \Drupal::queue('instant_translate')->numberOfItems());
  }

  /**
   * Whitelist patterns type:id, type:bundle and type:* are honoured.
   */
  public function testWhitelistModes(): void {
    $config = \Drupal::configFactory()->getEditable('instant_translate.settings');
    $config->set('auto_mode', 'whitelist')
      ->set('whitelist_ids', ['node:test'])
      ->save();

    $allowed = $this->createNode('test');
    $denied = $this->createNode('other');

    $this->assertTrue($this->service->mayAutoTranslate($allowed));
    $this->assertFalse($this->service->mayAutoTranslate($denied));

    // A type:id entry matches exactly that entity.
    $config->set('whitelist_ids', ['node:' . $denied->id()])->save();
    $this->assertFalse($this->service->mayAutoTranslate($allowed));
    $this->assertTrue($this->service->mayAutoTranslate($denied));

    // A type:* entry matches every entity of the type.
    $config->set('whitelist_ids', ['node:*'])->save();
    $this->assertTrue($this->service->mayAutoTranslate($denied));
  }

  /**
   * With engine=fake a translate call writes prefixed en/nl translations.
   */
  public function testTranslateEntityWithFakeEngine(): void {
    \Drupal::configFactory()->getEditable('instant_translate.settings')
      ->set('engine', 'fake')
      ->set('targets', ['en' => 'EN-GB', 'nl' => 'NL'])
      ->save();

    $node = $this->createNode('test', ['title' => 'Hallo']);
    $this->assertTrue($node->isTranslatable());
    $this->assertTrue($node->isDefaultTranslation());
    $this->assertSame('de', $node->language()->getId());

    $written = $this->service->translateEntity($node);
    $this->assertSame(['en', 'nl'], $written);

    $en = $node->getTranslation('en');
    $this->assertSame('[EN-GB] Hallo', $en->label());
    $nl = $node->getTranslation('nl');
    $this->assertSame('[NL] Hallo', $nl->label());
  }

  /**
   * A complete, non-outdated translation is skipped on the next run.
   */
  public function testSkipsUpToDateTranslations(): void {
    \Drupal::configFactory()->getEditable('instant_translate.settings')
      ->set('engine', 'fake')
      ->save();

    $node = $this->createNode('test', ['title' => 'Hallo']);
    $this->service->translateEntity($node);

    $this->assertSame([], $this->service->translateEntity($node));
  }

  /**
   * Config keys have the documented defaults.
   */
  public function testConfigDefaults(): void {
    $this->assertSame('de', $this->service->sourceLanguage());
    $this->assertSame(['en' => 'EN-GB', 'nl' => 'NL'], $this->service->targets());
    $this->assertContains('node', $this->service->entityTypes());
  }

  /**
   * Creates a node with a German source language.
   *
   * @param string $type
   *   Bundle name.
   * @param array $values
   *   Extra node values.
   *
   * @return \Drupal\node\NodeInterface
   *   The created node (saved).
   */
  protected function createNode(string $type = 'test', array $values = []): \Drupal\node\NodeInterface {
    $node = Node::create($values + [
      'type' => $type,
      'title' => 'Probe',
      'langcode' => 'de',
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

}
