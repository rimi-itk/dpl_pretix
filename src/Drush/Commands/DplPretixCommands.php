<?php

namespace Drupal\dpl_pretix\Drush\Commands;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\dpl_pretix\EntityHelper;
use Drupal\dpl_pretix\EventDataHelper;
use Drupal\dpl_pretix\PretixHelper;
use Drupal\recurring_events\Entity\EventSeries;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
    /** @var \Drupal\dpl_pretix\EventDataHelper $eventDataHelper */
    $pretixHelper = $container->get(PretixHelper::class);

    return new static(
      $entityHelper,
      $eventDataHelper,
      $pretixHelper
    );
  }

  /**
   * Synchronize event in pretix.
   */
  #[CLI\Command(name: 'dpl_pretix:event:synchronize')]
  #[CLI\Argument(name: 'eventId', description: 'Event id.')]
  #[CLI\Usage(name: 'dpl_pretix:event:synchronize 87', description: 'Synchronize event 87')]
  public function synchronizeEvent(string $eventId, array $options = []): void {
    $event = $this->entityHelper->getEventSeries($eventId);

    $this->entityHelper->synchronizeEvent($event, EntityHelper::UPDATE);
  }

  /**
   * Validate pretix template event.
   */
  #[CLI\Command(name: 'dpl_pretix:pretix:validate-template-event')]
  public function validatePretixTemplateEvent(): void {
    $errors = $this->pretixHelper->validateTemplateEvent();
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
    $event = $this->entityHelper->getEventSeries($eventId);

    $question = sprintf('Really delete pretix event for Drupal event %s?', $event->label());
    if ($this->io()->confirm($question)) {
      if ($this->entityHelper->deleteEvent($event)) {
        $this->io()->success(t('Event has been deleted in pretix.'));
        if ($data = $this->eventDataHelper->loadEventData($event)) {
          $this->eventDataHelper->deleteEventData($data);
          foreach ($this->entityHelper->getEventInstances($event) as $instance) {
            if ($data = $this->eventDataHelper->getEventData($instance)) {
              $this->eventDataHelper->deleteEventData($data);
            }
          }
          $this->io()->success(t('Event data has been deleted.'));
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
    $event = $this->entityHelper->getEventSeries($eventId);

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

    $event = $this->entityHelper->getEventSeries((string) $event->id());
    $this->io()->success(sprintf('%s:%s created.', $event->getEntityTypeId(),
      $event->id()));

    drupal_register_shutdown_function(function () use ($event) {
      $this->info((string) $event->id());
    });
  }

}
