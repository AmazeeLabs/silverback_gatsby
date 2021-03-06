<?php

namespace Drupal\silverback_gatsby\Plugin\GraphQL\DataProducer;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\Execution\FieldContext;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Drupal\locale\StringInterface;
use Drupal\locale\StringStorageInterface;
use Drupal\locale\TranslationString;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns the translations of a string.
 *
 * @DataProducer(
 *   id = "string_translations",
 *   name = @Translation("String translations"),
 *   description = @Translation("Returns the translations of a string."),
 *   produces = @ContextDefinition("any",
 *     label = @Translation("String translation"),
 *     multiple = TRUE
 *   ),
 *   consumes = {
 *     "sourceString" = @ContextDefinition("any",
 *       label = @Translation("Source string")
 *     )
 *   }
 * )
 */
class StringTranslations extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The locale storage service.
   *
   * @var \Drupal\locale\StringStorageInterface
   */
  protected $localeStorage;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LanguageManagerInterface $language_manager,
    StringStorageInterface $locale_storage
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->languageManager = $language_manager;
    $this->localeStorage = $locale_storage;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('language_manager'),
      $container->get('locale.storage'),
    );
  }

  /**
   * Resolver.
   *
   * @param Drupal\locale\StringInterface $sourceString
   * @return TranslationString[]
   */
  public function resolve(StringInterface $sourceString, FieldContext $context) {
    $context->addCacheTags(['locale']);
    $languages  = $this->languageManager->getLanguages();
    $translations = [];
    foreach ($languages as $language) {
      $translatedStrings = $this->localeStorage->getTranslations([
        'lid' => $sourceString->getId(),
        'language' => $language->getId(),
      ]);
      if (!empty($translatedStrings)) {
        $translatedString = reset($translatedStrings);
        if ($translatedString->isTranslation()) {
          $translations[$language->getId()] = $translatedString;
        }
      }
    }
    return $translations;
  }
}
