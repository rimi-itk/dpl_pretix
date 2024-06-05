<?php

namespace Drupal\dpl_pretix\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dpl_pretix\PretixHelper;
use Drupal\node\NodeStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings form.
 */
final class SettingsForm extends ConfigFormBase {
  use StringTranslationTrait;

  public const CONFIG_NAME = 'dpl_pretix.settings';

  private const SECTION_PRETIX = 'pretix';
  private const SECTION_LIBRARIES = 'libraries';
  private const SECTION_PSP_ELEMENTS = 'psp_elements';
  private const SECTION_EVENT_NODES = 'event_nodes';

  private const ACTION_PING_API = 'action_ping_api';

  public function __construct(
    ConfigFactoryInterface $configFactory,
    private readonly NodeStorageInterface $nodeStorage,
    private readonly PretixHelper $helper,
  ) {
    parent::__construct($configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get(PretixHelper::class),
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

    $this->buildFormPretix($form, $form_state, $config);
    $this->buildFormLibraries($form, $form_state, $config);
    $this->buildFormPspElements($form, $form_state, $config);
    $this->buildFormEventNodes($form, $form_state, $config);

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
  private function buildFormPretix(array &$form, FormStateInterface $formState, Config $config): void {
    $section = self::SECTION_PRETIX;
    $defaults = $config->get($section);

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('pretix'),
      '#open' => empty($defaults['url'])
        || empty($defaults['organizer_slug'])
        || empty($defaults['api_key'])
        || empty($defaults['template_event_slug']),

      'url' => [
        '#type' => 'url',
        '#title' => t('URL'),
        '#default_value' => $defaults['url'] ?? NULL,
        '#required' => TRUE,
        '#description' => t('Enter a valid pretix service endpoint without path info, such as https://www.pretix.eu/'),
      ],

      'organizer_slug' => [
        '#type' => 'textfield',
        '#title' => $this->t('Organizer slug'),
        '#default_value' => $defaults['organizer_slug'] ?? NULL,
        '#required' => TRUE,
        '#description' => $this->t('This is the default organizer slug used when connecting to pretix. If you provide slug/API key for a specific library (below), events related to that library will use that key instead of the default key.'),
      ],

      'api_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('The API key of the Organizer Team'),
        '#default_value' => $defaults['api_key'] ?? NULL,
        '#required' => TRUE,
        '#description' => $this->t('This is the default API key used when connecting to pretix. If you provide slug/API key for a specific library (below), events related to that library will use that key instead of the default key.'),
      ],

      'template_event_slug' => [
        '#type' => 'textfield',
        '#title' => $this->t('The slug of the default event template'),
        '#default_value' => $defaults['template_event_slug'] ?? NULL,
        '#required' => TRUE,
        '#description' => $this->t('This is the slug of the default event template. When events are created their setting etc. are copied from this event.'),
      ],
    ];
  }

  /**
   * Build form.
   */
  private function buildFormLibraries(array &$form, FormStateInterface $formState, Config $config): void {
    $section = self::SECTION_LIBRARIES;
    $defaults = $config->get($section);

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('Individual library slug/API keys'),
      '#description' => $this->t('Optional. If you have several organizers at pretix, each library can have their own slug/API key. In that case, the base slug/API key will be overridden by the provided key when sending data on events related to this library.'),
      '#open' => TRUE,
    ];

    $libraries = $this->loadLibraries();
    foreach ($libraries as $library) {
      $form[$section][$library->id()] = [
        '#type' => 'fieldset',
        '#title' => $library->getTitle(),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,

        'organizer_slug' => [
          '#type' => 'textfield',
          '#title' => $this->t('Organizer slug'),
          '#default_value' => $defaults[$library->id()]['organizer_slug'] ?? NULL,
          '#description' => $this->t('The slug of the pretix organizer to map to.'),
        ],

        'api_key' => [
          '#type' => 'textfield',
          '#title' => t('API key'),
          '#default_value' => $defaults[$library->id()]['api_key'] ?? NULL,
          '#description' => t('The API key of the Organizer Team'),
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
  private function buildFormPspElements(array &$form, FormStateInterface $formState, Config $config): void {
    $section = self::SECTION_PSP_ELEMENTS;
    $defaults = $config->get($section);

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('PSP elements'),
      '#open' => TRUE,

      'pretix_psp_meta_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('pretix PSP property name'),
        '#default_value' => $defaults['pretix_psp_meta_key'] ?? NULL,
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
      $pspElements = $defaults['list'] ?? NULL;
    }

    if (is_array($pspElements)) {
      foreach ($pspElements as $key => $value) {
        $form[$section]['list'][$key] = [
          '#type' => 'fieldset',
          '#title' => $key ? $this->t('PSP element') : $this->t('PSP element (default)'),

          'name' => [
            '#type' => 'textfield',
            '#title' => $this->t('Name'),
            '#default_value' => $value['name'] ?? NULL,
          ],

          'value' => [
            '#type' => 'textfield',
            '#title' => $this->t('Value'),
            '#size' => 50,
            '#maxlength' => 50,
            '#default_value' => $value['value'] ?? NULL,
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
  public function formPspAjaxCallback(array $form, FormStateInterface $formState) {
    return $form[self::SECTION_PSP_ELEMENTS]['list'];
  }

  /**
   * Submit handler for the "add-one-more" button.
   */
  public function formPspAddElement(array $form, FormStateInterface $formState) {
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
  public function formPspRemoveElement(array $form, FormStateInterface $formState) {
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
  private function buildFormEventNodes(array &$form, FormStateInterface $formState, Config $config): void {
    $section = self::SECTION_EVENT_NODES;
    $defaults = $config->get($section);

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('pretix event node defaults'),
      '#open' => TRUE,

      'capacity' => [
        '#type' => 'number',
        '#min' => 0,
        '#title' => $this->t('Capacity'),
        '#default_value' => $defaults['capacity'] ?? 0,
        '#size' => 5,
        '#maxlength' => 5,
        '#description' => $this->t('The default capacity for new events. Set to 0 for unlimited capacity.'),
      ],

      'maintain_copy_in_pretix' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Maintain copy in pretix'),
        '#default_value' => $defaults['maintain_copy_in_pretix'] ?? FALSE,
        '#return_value' => TRUE,
        '#description' => $this->t('Should new events be saved and updated to pretix by default?'),
      ],

      'default_ticket_form' => [
        '#type' => 'radios',
        '#title' => $this->t('Use PDF or Email tickets'),
        '#options' => [
          'pdf_ticket' => $this->t('PDF Tickets'),
          'email_ticket' => $this->t('Email Tickets'),
        ],
        '#required' => TRUE,
        '#default_value' => $defaults['default_ticket_form'] ?? [],
        '#description' => t('Should new events use PDF or Email tickets by default?'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (self::ACTION_PING_API === ($form_state->getTriggeringElement()['#name'] ?? NULL)) {
      return;
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (self::ACTION_PING_API === ($form_state->getTriggeringElement()['#name'] ?? NULL)) {
      try {
        $this->helper->pingApi();
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
    ] as $section) {
      $values = $form_state->getValue($section);
      if (is_array($values)) {
        foreach ($values as $key => $value) {
          $config->set($section . '.' . $key, $value);
        }
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