<?php

namespace Drupal\dpl_pretix\Drush\Commands;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dpl_pretix\EntityHelper;
use Drupal\dpl_pretix\EventDataHelper;
use Drupal\dpl_pretix\Exception\InvalidEventSeriesException;
use Drupal\dpl_pretix\FormHelper;
use Drupal\dpl_pretix\PretixHelper;
use Drupal\recurring_events\Entity\EventSeries;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\sprintf;

/**
 * A Drush command file.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 */
final class DplPretixCommands extends DrushCommands {

  /**
   * Constructs a DplPretixCommands object.
   */
  public function __construct(
    private readonly EntityHelper $entityHelper,
    private readonly EventDataHelper $eventDataHelper,
    private readonly PretixHelper $pretixHelper,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var \Drupal\dpl_pretix\EntityHelper $entityHelper */
    $entityHelper = $container->get(EntityHelper::class);
    /** @var \Drupal\dpl_pretix\EventDataHelper $eventDataHelper */
    $eventDataHelper = $container->get(EventDataHelper::class);
    /** @var \Drupal\dpl_pretix\PretixHelper $pretixHelper */
    $pretixHelper = $container->get(PretixHelper::class);

    return new static(
      $entityHelper,
      $eventDataHelper,
      $pretixHelper,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Synchronize event in pretix.
   */
  #[CLI\Command(name: 'dpl_pretix:event:synchronize')]
  #[CLI\Argument(name: 'eventId', description: 'Event id.')]
  #[CLI\Usage(name: 'dpl_pretix:event:synchronize 87', description: 'Synchronize event 87')]
  public function synchronizeEvent(
    string $eventId,
    array $options = [
      'templateEvent' => NULL,
      'formValues' => '{}',
    ],
  ): void {
    $event = $this->loadEventSeries($eventId);

    $templateEvent = $options['templateEvent'];
    if (empty($templateEvent)) {
      throw new InvalidArgumentException('Missing --templateEvent option.');
    }

    EntityHelper::setFormValues($event, [
      FormHelper::ELEMENT_MAINTAIN_COPY => TRUE,
      FormHelper::ELEMENT_TEMPLATE_EVENT => $templateEvent,
      FormHelper::CUSTOM_FORM_VALUES => json_decode($options['formValues'], TRUE),
    ]);
    $this->entityHelper->synchronizeEvent($event);
  }

  /**
   * Validate pretix template event.
   */
  #[CLI\Command(name: 'dpl_pretix:pretix:validate-template-event')]
  #[CLI\Argument(name: 'templateEvent', description: 'Template event slug.')]
  public function validatePretixTemplateEvent(string $templateEvent): void {
    $errors = $this->pretixHelper->validateTemplateEvent($templateEvent);
    foreach ($errors as $error) {
      $this->io()->error($error->getMessage());
    }
  }

  /**
   * Delete pretix event.
   */
  #[CLI\Command(name: 'dpl_pretix:pretix-event:delete')]
  #[CLI\Argument(name: 'eventId', description: 'Event id.')]
  #[CLI\Usage(name: 'dpl_pretix:pretix-event:delete 87', description: 'Delete pretix event for Drupal event 87')]
  public function deletePretixEvent(string $eventId): void {
    $event = $this->loadEventSeries($eventId);

    $question = sprintf('Really delete pretix event for Drupal event %s?', $event->label());
    if ($this->io()->confirm($question)) {
      if (!$this->entityHelper->deleteEvent($event)) {
        $this->io()->error(sprintf('Error deleting event %s (%s)', $event->label(), $event->id()));
      }
      else {
        $this->io()->success(t('Event has been deleted in pretix.'));
        if ($data = $this->eventDataHelper->loadEventData($event)) {
          $this->eventDataHelper->deleteEventData($data);
          foreach ($this->entityHelper->getEventInstances($event) as $instance) {
            if ($data = $this->eventDataHelper->getEventData($instance)) {
              $this->eventDataHelper->deleteEventData($data);
            }
          }
          $this->io()->success('Event data has been deleted.');
        }
      }
    }
  }

  /**
   * Show event information.
   */
  #[CLI\Command(name: 'dpl_pretix:event:info')]
  #[CLI\Argument(name: 'eventId', description: 'Event id.')]
  public function info(string $eventId, array $options = []): void {
    $event = $this->loadEventSeries($eventId);

    $this->io()->section('Event');

    $table = $this->io()->createTable();
    $table->setHeaders(['Property', 'Value']);
    $table->setRows([
      ['id', $event->id()],
      ['label', $event->label()],
    ]);
    $table->render();

    $data = $this->eventDataHelper->getEventData($event);

    if (!empty($data)) {
      $this->io()->section('Event data');

      $table = $this->io()->createTable();
      $table->setHeaders(['Property', 'Value']);
      foreach ($data->toArray() as $key => $value) {
        $table->addRow([$key, $value]);
      }
      $table->render();
    }

    $instances = $this->entityHelper->getEventInstances($event);
    $this->io()->section('Instances');

    foreach ($instances as $instance) {
      /** @var \Drupal\datetime_range\Plugin\Field\FieldType\DateRangeItem $date */
      $date = $instance->get('date')->first();
      $this->io()->writeln([
        sprintf('%s: %s', $instance->id(), $instance->label()),
        $date->get('value')->getValue(),
        $date->get('end_value')->getValue(),
      ]);
    }
  }

  /**
   * Create event.
   */
  #[CLI\Command(name: 'dpl_pretix:event:test-create-event')]
  public function testCreateEventName(): void {

    $event = EventSeries::create([
      'type' => 'default',
      'title' => __METHOD__,
      'field_description' => __METHOD__,
      'recur_type' => 'custom',
      'custom_date' => [
        [
          'start_date' => new DrupalDateTime('2025-01-01T12:00:00:'),
          'end_date' => new DrupalDateTime('2025-01-01T14:00:00:'),
        ],
      ],
    ]);

    $event->save();

    $event = $this->loadEventSeries((string) $event->id());
    $this->io()->success(sprintf('%s:%s created.', $event->getEntityTypeId(),
      $event->id()));

    drupal_register_shutdown_function(function () use ($event) {
      $this->info((string) $event->id());
    });
  }

  /**
   * Perform pretix API request.
   */
  #[CLI\Command(name: 'dpl_pretix:api:request')]
  #[CLI\Argument(name: 'path', description: 'Path')]
  public function pretixApiRequest(
    string $path,
    array $options = [
      'method' => Request::METHOD_GET,
    ],
  ): void {
    $method = $options['method'];
    if (Request::METHOD_GET !== $method) {
      throw new InvalidArgumentException(sprintf('Method %s not supported', $method));
    }

    $pretix = $this->pretixHelper->client();
    $request = new \ReflectionMethod($pretix, 'request');
    $requestOptions = [];
    /** @var \GuzzleHttp\Psr7\Response $response */
    $response = $request->invoke($pretix, $method, $path, $requestOptions);

    $data = json_decode($response->getBody(), TRUE);
    $this->io()->write(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Load event series.
   */
  private function loadEventSeries(string $id): EventSeries {
    /** @var ?\Drupal\recurring_events\Entity\EventSeries $event */
    $event = $this->entityTypeManager->getStorage('eventseries')->load($id);

    if (NULL === $event) {
      throw new InvalidEventSeriesException(sprintf('Invalid event series ID: %s', $id));
    }

    return $event;
  }

}
