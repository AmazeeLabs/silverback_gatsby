<?php

namespace Drupal\silverback_gatsby\GraphQL;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\graphql\Plugin\GraphQL\Schema\ComposableSchema as OriginalComposableSchema;
use Drupal\graphql\Plugin\SchemaExtensionPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for composable/extensible schemas.
 *
 * TODO: Move this back into the upstream GraphQL module.
 *
 * Allows extensions to access the host schema AST and define directives
 * that can be used by the host schema.
 *
 * Grants public access to extensions so other services can interact with them.
 *
 * @package Drupal\silverback_gatsby\GraphQL
 */
class ComposableSchema extends OriginalComposableSchema {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected RouteMatchInterface $routeMatch;

  public function __construct(
    array $configuration,
    $pluginId,
    array $pluginDefinition,
    CacheBackendInterface $astCache,
    ModuleHandlerInterface $moduleHandler,
    SchemaExtensionPluginManager $extensionManager,
    array $config,
    EntityTypeManagerInterface $entityTypeManager,
    RouteMatchInterface $routeMatch
  ) {
    parent::__construct(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $astCache,
      $moduleHandler,
      $extensionManager,
      $config,
      $routeMatch
    );
    $this->entityTypeManager = $entityTypeManager;
    $this->routeMatch = $routeMatch;
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('cache.graphql.ast'),
      $container->get('module_handler'),
      $container->get('plugin.manager.graphql.schema_extension'),
      $container->getParameter('graphql.config'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getExtensions() {
    $extensions = &drupal_static(__METHOD__);
    if (!$extensions) {
      $extensions = parent::getExtensions();

      $schema = $this->getSchemaDefinition();
      // Iterate through all extensions and pass them the current schema, so they
      // can act on it.
      foreach ($extensions as $extension) {
        if ($extension instanceof ParentAwareSchemaExtensionInterface) {
          $extension->setParentSchemaDefinition($schema);
        }
      }
    }

    return $extensions;
  }

  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state
  ) {
    $form = parent::buildConfigurationForm(
      $form,
      $form_state
    );

    // Build configuration has to be defined on this form,
    // access is set to false if current route is not the build form one.
    $isBuildForm = $this->routeMatch->getRouteName() === 'entity.graphql_server.build_form';
    $form['extensions']['#disabled'] = $isBuildForm;
    $form['build_trigger_on_save'] = [
      '#type' => 'checkbox',
      '#access' => $isBuildForm,
      '#required' => FALSE,
      '#title' => $this->t('Trigger a build on entity save.'),
      '#description' => $this->t('If not checked, make sure to have an alternate build method (cron, manual).'),
      '#default_value' => $this->configuration['build_trigger_on_save'] ?? '',
    ];
    $form['build_webhook'] = [
      '#type' => 'textfield',
      '#access' => $isBuildForm,
      '#required' => FALSE,
      '#title' => $this->t('Build webhook'),
      '#description' => $this->t('A webhook that will be notified when content changes relevant to this server happen.'),
      '#default_value' => $this->configuration['build_webhook'] ?? '',
    ];
    $form['build_url'] = [
      '#type' => 'url',
      '#access' => $isBuildForm,
      '#required' => FALSE,
      '#title' => $this->t('Build url'),
      '#description' => $this->t('The frontend url that is the result of the build. With the scheme and without a trailing slash (https://www.example.com).'),
      '#default_value' => $this->configuration['build_url'] ?? '',
    ];

    /** @var \Drupal\graphql\Form\ServerForm $formObject */
    $formObject = $form_state->getFormObject();
    /** @var \Drupal\graphql\Entity\Server $server */
    $server = $formObject->getEntity();

    $form['user'] = [
      '#type' => 'select',
      '#access' => $isBuildForm,
      '#options' => ['' => $this->t('- None -')],
      '#title' => $this->t('Notification user'),
      '#description' => $this->t('Only changes visible to this user will trigger build updates.'),
      '#default_value' => $this->configuration['user'] ?? '',
      '#states'=> [
        'required' => [
          ':input[name="schema_configuration[' . $server->schema . '][extensions][silverback_gatsby]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $users = [];
    /** @var \Drupal\user\UserInterface $user */
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple() as $user) {
      $users[$user->uuid()] = $user->getAccountName() === '' ? 'Anonymous' : $user->getAccountName();
    }
    natcasesort($users);
    $form['user']['#options'] += $users;

    $form['role'] = [
      '#type' => 'select',
      '#access' => $isBuildForm,
      '#required' => FALSE,
      '#options' => ['' => $this->t('- None -')],
      '#title' => $this->t('Notification role'),
      '#description' => $this->t('<strong>DEPRECATED</strong>: use the "@userField" field instead.', [
        '@userField' => $this->t('Notification user'),
      ]),
      '#default_value' => $this->configuration['role'] ?? '',
    ];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $id => $role) {
      if (!in_array($id, ['anonymous', 'authenticated'])) {
        $form['role']['#options'][$id] = $role->label();
      }
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function getSchemaDefinition() {
    $extensions = parent::getExtensions();

    // Get all extensions and prepend any defined directives to the schema.
    $schema = [];
    foreach ($extensions as $extension) {
      if ($extension instanceof DirectiveProviderExtensionInterface) {
        $schema[] = $extension->getDirectiveDefinitions();
      }
    }

    // Attempt to load a schema file and return it instead of the hardcoded
    // empty schema in \Drupal\graphql\Plugin\GraphQL\Schema\ComposableSchema.
    $id = $this->getPluginId();
    $definition = $this->getPluginDefinition();
    $module = $this->moduleHandler->getModule($definition['provider']);
    $path = 'graphql/' . $id . '.graphqls';
    $file = $module->getPath() . '/' . $path;

    if (!file_exists($file)) {
      return parent::getSchemaDefinition();
    }

    $schema[] = file_get_contents($file);

    return implode("\n", $schema);
  }
}
