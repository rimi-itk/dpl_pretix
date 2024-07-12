<?php

namespace Drupal\dpl_pretix\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dpl_pretix\EntityHelper;
use Drupal\dpl_pretix\Settings;
use Drupal\node\NodeStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Module settings form.
 */
final class SettingsForm extends ConfigFormBase {
  use StringTranslationTrait;

  public const CONFIG_NAME = 'dpl_pretix.settings';

  public const SECTION_PRETIX = 'pretix';
  public const SECTION_LIBRARIES = 'libraries';
  public const SECTION_PSP_ELEMENTS = 'psp_elements';
  public const SECTION_EVENT_NODES = 'event_nodes';
  public const SECTION_EVENT_FORM = 'event_form';

  private const ACTION_PING_API = 'action_ping_api';

  public function __construct(
    ConfigFactoryInterface $configFactory,
    private NodeStorageInterface $nodeStorage,
    private readonly EntityHelper $eventHelper,
    private readonly Settings $settings,
  ) {
    parent::__construct($configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    /** @var \Drupal\dpl_pretix\EntityHelper $eventHelper */
    $eventHelper = $container->get(EntityHelper::class);

    /** @var \Drupal\dpl_pretix\Settings $settings */
    $settings = $container->get(Settings::class);

    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')->getStorage('node'),
      $eventHelper,
      $settings
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dpl_pretix_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      self::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Form constructor.
    $form = parent::buildForm($form, $form_state);

    $config = $this->getConfig();

    $form['#tree'] = TRUE;

    $this->buildFormPretix($form);
    $this->buildFormLibraries($form);
    $this->buildFormPspElements($form, $form_state);
    $this->buildFormEventNodes($form);
    $this->buildFormEventForm($form);

    $form['admin'] = [
      '#type' => 'container',

      'admin' => [
        '#type' => 'link',
        '#title' => $this->t('Admin pretix'),
        '#url' => Url::fromRoute('dpl_pretix.settings_debug'),
      ],
    ];

    $form['actions']['ping_api'] = [
      '#type' => 'container',

      self::ACTION_PING_API => [
        '#type' => 'submit',
        '#name' => self::ACTION_PING_API,
        '#value' => $this->t('Ping API'),
      ],

      'message' => [
        '#markup' => $this->t('Note: Pinging the API will use saved config.'),
      ],
    ];

    return $form;
  }

  /**
   * Build form.
   */
  private function buildFormPretix(array &$form): void {
    $section = self::SECTION_PRETIX;

    $subSections = ['prod', 'test'];

    $activeSettings = NULL;

    $groupName = $section . '_tabs';
    $form[$groupName] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('pretix event settings'),
      '#description' => $this->t('The active pretix settings will be selected based on the active domain (%domain).', [
        '%domain' => $this->settings->getCurrentDomain(),
      ]),
      '#description_display' => 'before',
    ];

    foreach ($subSections as $subSection) {
      $defaults = $this->settings->getPretixSettings($subSection);
      $isActive = $this->settings->isActivePretixSettings($defaults);

      if ($isActive) {
        $activeSettings = $defaults;
        $form[$groupName]['#default_tab'] = 'edit-pretix-' . $subSection;
      }

      $form[$section][$subSection] = [
        '#group' => $groupName,

        '#type' => 'details',
        '#title' => $this->t('pretix (%section)', ['%section' => $subSection])
        . ($isActive ? ' [' . $this->t('active') . ']' : ''),

        'domain' => [
          '#type' => 'textfield',
          '#title' => t('Domain'),
          '#default_value' => $defaults->domain,
          '#required' => TRUE,
          '#description' => t('The Drupal domain that these pretix settings apply to.'),
          '#element_validate' => [[$this, 'validateDomain']],
        ],

        'url' => [
          '#type' => 'url',
          '#title' => t('URL'),
          '#default_value' => $defaults->url,
          '#required' => TRUE,
          '#description' => t('Enter a valid pretix service endpoint without path info, such as https://www.pretix.eu/'),
        ],

        'organizer' => [
          '#type' => 'textfield',
          '#title' => $this->t('Organizer'),
          '#default_value' => $defaults->organizer,
          '#required' => TRUE,
          '#description' => $this->t('This is the default organizer short form used when connecting to pretix. If you provide short form/API token for a specific library (below), events related to that library will use that key instead of the default key.'),
        ],

        'api_token' => [
          '#type' => 'textfield',
          '#title' => $this->t('The API token of the Organizer Team'),
          '#default_value' => $defaults->apiToken,
          '#required' => TRUE,
          '#description' => $this->t('This is the default API token used when connecting to pretix. If you provide short form/API token for a specific library (below), events related to that library will use that key instead of the default key.'),
        ],

        'template_event' => [
          '#type' => 'textfield',
          '#title' => $this->t('The short form of the default event template'),
          '#default_value' => $defaults->templateEvent,
          '#required' => TRUE,
          '#description' => $this->t('This is the short form of the default event template. When events are created their setting etc. are copied from this event.'),
        ],

        'event_slug_template' => [
          '#type' => 'textfield',
          '#title' => $this->t('Event slug template'),
          '#default_value' => $defaults->eventSlugTemplate,
          '#required' => TRUE,
          '#description' => $this->t('Template used to generate event short forms in pretix. Placeholders<br/> <code>{id}</code>: the event (node) id'),
        ],
      ];

      $webhookUrl = Url::fromRoute('dpl_pretix.pretix_webhook')->setAbsolute();
      $form[$section][$subSection]['pretix_webhook_info'] = [
        '#type' => 'container',

        'label' => [
          '#type' => 'label',
          '#title' => $this->t('pretix webhook URL'),
        ],

        'link' => [
          '#type' => 'link',
          '#title' => $webhookUrl->toString(TRUE)->getGeneratedUrl(),
          '#url' => $webhookUrl,
        ],

        'description' => [
          '#markup' => $this->t('The <a href="@pretix_webhook_url">pretix webhook URL</a> used by pretix to send notifications.',
            [
              '@pretix_webhook_url' => 'https://docs.pretix.eu/en/latest/api/webhooks.html',
            ]),
          '#prefix' => '<div class="form-item__description">',
          '#suffix' => '</div>',
        ],
      ];
    }

    if (Request::METHOD_GET === $this->getRequest()->getMethod() && empty($activeSettings)) {
      $this->messenger()->addWarning(
        $this->t('No pretix settings are currently active (based on the domain %domain).', [
          '%domain' => $this->settings->getCurrentDomain(),
        ])
      );
    }
  }

  /**
   * Validate domain.
   */
  public function validateDomain(array $element, FormStateInterface $formState): void {
    $value = $formState->getValue($element['#parents']);

    // @todo FILTER_VALIDATE_DOMAIN does not work as expected; it does not report '1 2 3', say, as invalid.
    if (!filter_var($value, FILTER_VALIDATE_DOMAIN)) {
      $formState->setError($element, $this->t('@value is not a valid host name'));
    }
  }

  /**
   * Build form.
   */
  private function buildFormLibraries(array &$form): void {
    $section = self::SECTION_LIBRARIES;
    $defaults = $this->settings->getLibrarySettings();

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('Individual library short form/API tokens'),
      '#description' => $this->t('Optional. If you have several organizers at pretix, each library can have their own short form/API token. In that case, the base short form/API token will be overridden by the provided key when sending data on events related to this library.'),
      '#open' => TRUE,
    ];

    $libraries = $this->loadLibraries();
    foreach ($libraries as $library) {
      $item = $defaults->list[$library->id()] ?? NULL;
      $form[$section]['list'][$library->id()] = [
        '#type' => 'fieldset',
        '#title' => $library->getTitle(),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,

        'organizer' => [
          '#type' => 'textfield',
          '#title' => $this->t('Organizer'),
          '#default_value' => $item->organizer ?? NULL,
          '#description' => $this->t('The short form of the pretix organizer to map to.'),
        ],

        'api_token' => [
          '#type' => 'textfield',
          '#title' => t('API token'),
          '#default_value' => $item->apiToken ?? NULL,
          '#description' => t('The API token of the organizer team'),
        ],
      ];
    }
  }

  /**
   * Load libraries.
   *
   * @return \Drupal\node\Entity\Node[]|array
   *   The libraries.
   */
  private function loadLibraries(): array {
    // @todo Uncaught
    /*
     * Typed property Drupal\\dpl_pretix\\Form\\SettingsForm::$nodeStorage must
     * not be accessed before initialization in
     * Drupal\\dpl_pretix\\Form\\SettingsForm-&gt;loadLibraries() (line 193 of
     * sites/default/files/modules_local/dpl_pretix/src/Form/SettingsForm.php).
     * Drupal\\dpl_pretix\\Form\\SettingsForm-&gt;buildFormLibraries() (Line:
     * 77)
     *
     * @see https://www.drupal.org/project/views_bulk_operations/issues/3351434
     */
    if (!isset($this->nodeStorage)) {
      $this->nodeStorage = \Drupal::service('entity_type.manager')->getStorage('node');
    }

    $ids = $this->nodeStorage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'branch')
      ->execute();

    return $this->nodeStorage->loadMultiple($ids);
  }

  /**
   * Build form.
   */
  private function buildFormPspElements(array &$form, FormStateInterface $formState): void {
    $section = self::SECTION_PSP_ELEMENTS;
    $defaults = $this->settings->getPspElements();

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('PSP elements'),
      '#open' => TRUE,

      'pretix_psp_meta_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('pretix PSP property name'),
        '#default_value' => $defaults->pretixPspMetaKey ?? NULL,
        '#size' => 50,
        '#maxlength' => 50,
        '#description' => $this->t('The name of the organizer metadata property for the PSP element in pretix (case sensitive).'),
      ],

      'list' => [
        '#prefix' => '<div id="dpl-pretix-psp-elements-list">',
        '#suffix' => '</div>',
      ],

      'add_element' => [
        '#type' => 'submit',
        '#value' => $this->t('Add PSP element'),
        '#submit' => ['::formPspAddElement'],
        '#ajax' => [
          'callback' => [$this, 'formPspAjaxCallback'],
          'wrapper' => 'dpl-pretix-psp-elements-list',
        ],
      ],

      'remove_element' => [
        '#type' => 'submit',
        '#value' => $this->t('Remove PSP element'),
        '#submit' => ['::formPspRemoveElement'],
        '#ajax' => [
          'callback' => [$this, 'formPspAjaxCallback'],
          'wrapper' => 'dpl-pretix-psp-elements-list',
        ],
      ],
    ];

    // Get PSPs previously saved to a variable (first load), else use formstate
    // data (ajax calls).
    $pspElements = $formState->getValue([$section, 'list']);
    if (empty($pspElements)) {
      $pspElements = $defaults->list ?? NULL;
    }

    if (is_array($pspElements)) {
      foreach ($pspElements as $key => $value) {
        $form[$section]['list'][$key] = [
          '#type' => 'fieldset',
          '#title' => $key ? $this->t('PSP element') : $this->t('PSP element (default)'),

          'name' => [
            '#type' => 'textfield',
            '#title' => $this->t('Name'),
            '#default_value' => $value->name ?? NULL,
          ],

          'value' => [
            '#type' => 'textfield',
            '#title' => $this->t('Value'),
            '#size' => 50,
            '#maxlength' => 50,
            '#default_value' => $value->value ?? NULL,
          ],
        ];
      }
    }
  }

  /**
   * Callback for PSP element AJAX buttons.
   *
   * Selects and returns the fieldset with the PSP elements in it.
   */
  public function formPspAjaxCallback(array $form, FormStateInterface $formState): array {
    return $form[self::SECTION_PSP_ELEMENTS]['list'];
  }

  /**
   * Submit handler for the "add-one-more" button.
   */
  public function formPspAddElement(array $form, FormStateInterface $formState): void {
    $key = [self::SECTION_PSP_ELEMENTS, 'list'];
    $list = $formState->getValue($key);
    if (!is_array($list)) {
      $list = [];
    }
    $list[] = [
      'name' => '',
      'value' => '',
    ];
    $formState->setValue($key, $list);
    $formState->setRebuild();
  }

  /**
   * Submit handler for the "Remove PSP elements" button.
   */
  public function formPspRemoveElement(array $form, FormStateInterface $formState): void {
    $key = [self::SECTION_PSP_ELEMENTS, 'list'];
    $list = $formState->getValue($key);
    if (!is_array($list)) {
      $list = [];
    }
    array_pop($list);
    $formState->setValue($key, $list);
    $formState->setRebuild();
  }

  /**
   * Build form.
   */
  private function buildFormEventNodes(array &$form): void {
    $section = self::SECTION_EVENT_NODES;
    $defaults = $this->settings->getEventNodes();

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('pretix event node defaults'),
      '#open' => TRUE,

      'capacity' => [
        '#type' => 'number',
        '#min' => 0,
        '#title' => $this->t('Capacity'),
        '#default_value' => $defaults->capacity ?? 0,
        '#size' => 5,
        '#maxlength' => 5,
        '#description' => $this->t('The default capacity for new events. Set to 0 for unlimited capacity.'),
      ],

      'maintain_copy' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Maintain copy in pretix'),
        '#default_value' => $defaults->maintainCopy ?? FALSE,
        '#return_value' => TRUE,
        '#description' => $this->t('Should new events be saved and updated to pretix by default?'),
      ],

      'ticket_type' => [
        '#type' => 'radios',
        '#title' => $this->t('Use PDF or Email tickets'),
        '#options' => [
          'pdf_ticket' => $this->t('PDF Tickets'),
          'email_ticket' => $this->t('Email Tickets'),
        ],
        '#required' => TRUE,
        '#default_value' => $defaults->ticketType ?? '',
        '#description' => t('Should new events use PDF or Email tickets by default?'),
      ],
    ];
  }

  /**
   * Build form.
   */
  private function buildFormEventForm(array &$form): void {
    $section = self::SECTION_EVENT_FORM;
    $defaults = $this->settings->getEventForm();

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('event form'),
      '#open' => TRUE,

      'weight' => [
        '#type' => 'number',
        '#title' => $this->t('Weight'),
        '#default_value' => $defaults->weight ?? 9999,
        '#size' => 5,
        '#maxlength' => 5,
        '#description' => $this->t('The weight if the pretix section on event form.'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (self::ACTION_PING_API === ($form_state->getTriggeringElement()['#name'] ?? NULL)) {
      return;
    }

    // @todo check that pretix template event exists.
    // @todo check that pretix template event has a single subevent.
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (self::ACTION_PING_API === ($form_state->getTriggeringElement()['#name'] ?? NULL)) {
      try {
        $this->eventHelper->pingApi();
        $this->messenger()->addStatus($this->t('Pinged API successfully.'));
      }
      catch (\Throwable $t) {
        $this->messenger()->addError($this->t('Pinging API failed: @message', ['@message' => $t->getMessage()]));
      }
      return;
    }

    $config = $this->getConfig();

    foreach ([
      self::SECTION_PRETIX,
      self::SECTION_LIBRARIES,
      self::SECTION_PSP_ELEMENTS,
      self::SECTION_EVENT_NODES,
      self::SECTION_EVENT_FORM,
    ] as $section) {
      $values = $form_state->getValue($section);
      if (is_array($values)) {
        // Remove some values that are only used for form stuff.
        unset($values['add_element'], $values['remove_element']);
        $config->set($section, $values);
      }
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get module config.
   */
  private function getConfig(): Config|ImmutableConfig {
    return $this->config(self::CONFIG_NAME);
  }

}
