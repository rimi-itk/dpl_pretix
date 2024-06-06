<?php

namespace Drupal\dpl_pretix\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dpl_pretix\EventHelper;
use Drupal\dpl_pretix\PretixHelper;
use Drupal\node\NodeStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    private readonly NodeStorageInterface $nodeStorage,
    private readonly EventHelper $eventHelper,
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
      $container->get(EventHelper::class),
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
    $this->buildFormEventForm($form, $form_state, $config);

    $form['debug'] = [
      '#type' => 'container',
      'debug' => Link::fromTextAndUrl($this->t('Debug pretix'), Url::fromRoute('dpl_pretix.settings_debug'))->toRenderable(),
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
  private function buildFormPretix(array &$form, FormStateInterface $formState, Config $config): void {
    $section = self::SECTION_PRETIX;
    $defaults = $config->get($section);

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('pretix'),
      '#open' => empty($defaults['url'])
      || empty($defaults['organizer'])
      || empty($defaults['api_token'])
      || empty($defaults['template_event']),

      'url' => [
        '#type' => 'url',
        '#title' => t('URL'),
        '#default_value' => $defaults['url'] ?? NULL,
        '#required' => TRUE,
        '#description' => t('Enter a valid pretix service endpoint without path info, such as https://www.pretix.eu/'),
      ],

      'organizer' => [
        '#type' => 'textfield',
        '#title' => $this->t('Organizer'),
        '#default_value' => $defaults['organizer'] ?? NULL,
        '#required' => TRUE,
        '#description' => $this->t('This is the default organizer short form used when connecting to pretix. If you provide short form/API token for a specific library (below), events related to that library will use that key instead of the default key.'),
      ],

      'api_token' => [
        '#type' => 'textfield',
        '#title' => $this->t('The API token of the Organizer Team'),
        '#default_value' => $defaults['api_token'] ?? NULL,
        '#required' => TRUE,
        '#description' => $this->t('This is the default API token used when connecting to pretix. If you provide short form/API token for a specific library (below), events related to that library will use that key instead of the default key.'),
      ],

      'template_event' => [
        '#type' => 'textfield',
        '#title' => $this->t('The short form of the default event template'),
        '#default_value' => $defaults['template_event'] ?? NULL,
        '#required' => TRUE,
        '#description' => $this->t('This is the short form of the default event template. When events are created their setting etc. are copied from this event.'),
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
      '#title' => $this->t('Individual library short form/API tokens'),
      '#description' => $this->t('Optional. If you have several organizers at pretix, each library can have their own short form/API token. In that case, the base short form/API token will be overridden by the provided key when sending data on events related to this library.'),
      '#open' => TRUE,
    ];

    $libraries = $this->loadLibraries();
    foreach ($libraries as $library) {
      $form[$section][$library->id()] = [
        '#type' => 'fieldset',
        '#title' => $library->getTitle(),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,

        'organizer' => [
          '#type' => 'textfield',
          '#title' => $this->t('Organizer'),
          '#default_value' => $defaults[$library->id()]['organizer'] ?? NULL,
          '#description' => $this->t('The short form of the pretix organizer to map to.'),
        ],

        'api_token' => [
          '#type' => 'textfield',
          '#title' => t('API token'),
          '#default_value' => $defaults[$library->id()]['api_token'] ?? NULL,
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
    An AJAX HTTP error occurred.
    HTTP Result Code: 500
    Debugging information follows.
    Path: /admin/config/dpl_pretix?ajax_form=1
    StatusText: Internal Server Error
    ResponseText: The website encountered an unexpected error. Please try again later.Error: Typed property Drupal\\dpl_pretix\\Form\\SettingsForm::$nodeStorage must not be accessed before initialization in Drupal\\dpl_pretix\\Form\\SettingsForm-&gt;loadLibraries() (line 193 of sites/default/files/modules_local/dpl_pretix/src/Form/SettingsForm.php). Drupal\\dpl_pretix\\Form\\SettingsForm-&gt;buildFormLibraries() (Line: 77)
    Drupal\\dpl_pretix\\Form\\SettingsForm-&gt;buildForm()
    call_user_func_array() (Line: 536)
    Drupal\\Core\\Form\\FormBuilder-&gt;retrieveForm() (Line: 375)
    Drupal\\Core\\Form\\FormBuilder-&gt;rebuildForm() (Line: 633)
    Drupal\\Core\\Form\\FormBuilder-&gt;processForm() (Line: 325)
    Drupal\\Core\\Form\\FormBuilder-&gt;buildForm() (Line: 73)
    Drupal\\Core\\Controller\\FormController-&gt;getContentResult()
    call_user_func_array() (Line: 123)
    Drupal\\Core\\EventSubscriber\\EarlyRenderingControllerWrapperSubscriber-&gt;Drupal\\Core\\EventSubscriber\\{closure}() (Line: 592)
    Drupal\\Core\\Render\\Renderer-&gt;executeInRenderContext() (Line: 124)
    Drupal\\Core\\EventSubscriber\\EarlyRenderingControllerWrapperSubscriber-&gt;wrapControllerExecutionInRenderContext() (Line: 97)
    Drupal\\Core\\EventSubscriber\\EarlyRenderingControllerWrapperSubscriber-&gt;Drupal\\Core\\EventSubscriber\\{closure}() (Line: 181)
    Symfony\\Component\\HttpKernel\\HttpKernel-&gt;handleRaw() (Line: 76)
    Symfony\\Component\\HttpKernel\\HttpKernel-&gt;handle() (Line: 58)
    Drupal\\Core\\StackMiddleware\\Session-&gt;handle() (Line: 48)
    Drupal\\Core\\StackMiddleware\\KernelPreHandle-&gt;handle() (Line: 4n8)
    Drupal\\Core\\StackMiddleware\\ReverseProxyMiddleware-&gt;handle() (Line: 51)
    Drupal\\Core\\StackMiddleware\
    egotiationMiddleware-&gt;handle() (Line: 51)
    Drupal\\Core\\StackMiddleware\\StackedHttpKernel-&gt;handle() (Line: 704)
    Drupal\\Core\\DrupalKernel-&gt;handle() (Line: 19)

    @see https://www.drupal.org/project/views_bulk_operations/issues/3351434
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

      'maintain_copy' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Maintain copy in pretix'),
        '#default_value' => $defaults['maintain_copy'] ?? FALSE,
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
        '#default_value' => $defaults['ticket_type'] ?? [],
        '#description' => t('Should new events use PDF or Email tickets by default?'),
      ],
    ];
  }

  /**
   * Build form.
   */
  private function buildFormEventForm(array &$form, FormStateInterface $formState, Config $config): void {
    $section = self::SECTION_EVENT_FORM;
    $defaults = $config->get($section);

    $form[$section] = [
      '#type' => 'details',
      '#title' => $this->t('event form'),
      '#open' => TRUE,

      'weight' => [
        '#type' => 'number',
        '#title' => $this->t('Weight'),
        '#default_value' => $defaults['weight'] ?? 9999,
        '#size' => 5,
        '#maxlength' => 5,
        '#description' => $this->t('The weight if the pretix section on event form.'),
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
