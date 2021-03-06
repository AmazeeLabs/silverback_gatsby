<?php

namespace Drupal\Tests\silverback_gatsby\Kernel;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\graphql\Entity\Server;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\silverback_gatsby\Traits\BuildNotificationCheckTrait;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Prophecy\Argument;

class GatsbyUpdateTriggerTest extends KernelTestBase {
  use BuildNotificationCheckTrait;

  protected $strictConfigSchema = FALSE;

  /**
   * @var string[]
   */
  public static $modules = [
    'language',
    'user',
    'node',
    'graphql',
    'content_translation',
    'silverback_gatsby',
    'silverback_gatsby_example',
    'menu_link_content',
  ];

  /**
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $messengerProphecy;

  /**
   * @var \Drupal\silverback_gatsby\GatsbyUpdateTrigger
   */
  protected $trigger;

  protected function setUp() : void {
    parent::setUp();
    $this->setupClientProphecy();
    $this->messengerProphecy = $this->prophesize(MessengerInterface::class);
    $this->container->set('messenger', $this->messengerProphecy->reveal());

    $this->installConfig('graphql');
    $this->installSchema('silverback_gatsby', ['gatsby_update_log']);

    $this->trigger = $this->container->get('silverback_gatsby.update_trigger');

    Server::create([
      'schema' => 'silverback_gatsby_example',
      'name' => 'foo',
      'endpoint' => '/foo',
      'schema_configuration' => [
        'silverback_gatsby_example' => [
          'extensions' => [
            'silverback_gatsby' => 'silverback_gatsby'
          ],
          'build_webhook' => 'http://localhost:8000/__refresh'
        ]
      ]
    ])->save();

    Server::create([
      'schema' => 'silverback_gatsby_example',
      'name' => 'bar',
      'endpoint' => '/bar',
      'schema_configuration' => [
        'silverback_gatsby_example' => [
          'extensions' => [
            'silverback_gatsby' => 'silverback_gatsby'
          ],
          'build_webhook' => 'http://localhost:9000/__refresh'
        ]
      ]
    ])->save();

  }

  public function testBeforeShutdown() {
    $this->trigger->trigger('foo', 1);
    // If _drupal_shutdown_function() is not called, no notifications go out.
    $this->checkTotalNotifications(0);
  }

  public function testRequestException() {
    $this->clientProphecy->post(Argument::any(), Argument::any())
      ->willThrow(new RequestException('Invalid!', new Request('post', 'http://localhost:8000/__refresh')));
    $this->trigger->trigger('foo', 1);
    _drupal_shutdown_function();
    $this->messengerProphecy->addError(Argument::any())->shouldHaveBeenCalledTimes(2);
    $this->messengerProphecy->addError('Invalid!')->shouldHaveBeenCalledTimes(1);
    $this->messengerProphecy->addError('Could not send build notification to server "http://localhost:8000/__refresh".')->shouldHaveBeenCalledTimes(1);
  }

  public function testSingleTrigger() {
    $this->trigger->trigger('foo', 1);
    _drupal_shutdown_function();
    $this->checkTotalNotifications(1);
    $this->checkNotification('http://localhost:8000/__refresh', 1);
  }

  public function testMultipleTriggers() {
    $this->trigger->trigger('foo', 1);
    $this->trigger->trigger('foo', 2);
    _drupal_shutdown_function();
    $this->checkTotalNotifications(1);
    $this->checkNotification('http://localhost:8000/__refresh', 2);
  }

  public function testMultipleServers() {
    $this->trigger->trigger('foo', 1);
    $this->trigger->trigger('bar', 2);
    _drupal_shutdown_function();
    $this->checkTotalNotifications(2);
    $this->checkNotification('http://localhost:8000/__refresh', 1);
    $this->checkNotification('http://localhost:9000/__refresh', 2);
  }
}
