<?php

namespace Drupal\dpl_pretix\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dpl_pretix\Exception\ValidationException;
use Drupal\dpl_pretix\PretixHelper;
use Drupal\dpl_pretix\Settings;
use Drupal\dpl_pretix\Settings\PretixSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use function Safe\preg_match;

/**
 * Module settings form.
 */
final class SettingsForm extends ConfigFormBase {
  use StringTranslationTrait;
  use DependencySerializationTrait;

  public const CONFIG_NAME = 'dpl_pretix.settings';

  public const SECTION_PRETIX = 'pretix';
  public const PRETIX_SUB_SECTIONS = ['prod', 'test'];

  public const SECTION_PSP_ELEMENTS = 'psp_elements';
  public const SECTION_EVENT_NODES = 'event_nodes';
  public const SECTION_EVENT_FORM = 'event_form';

  private const ELEMENT_TEMPLATE_EVENTS = 'template_events';
  private const ELEMENT_PRETIX_URL = 'url';

  private const ACTION_PING_API = 'action_ping_api';

  public function __construct(
    ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly Settings $settings,
    private readonly PretixHelper $pretixHelper,
  ) {
    parent::__construct($configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    /** @var \Drupal\dpl_pretix\Settings $settings */
    $settings = $container->get(Settings::class);

    /** @var \Drupal\dpl_pretix\PretixHelper $pretixHelper */
    $pretixHelper = $container->get(PretixHelper::class);

    return new static(
      $container->get('config.factory'),
      $container->get('language_manager'),
      $settings,
      $pretixHelper,
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

    $form['#tree'] = TRUE;

    $form['dpl_pretix_header'] = [
      '#theme' => 'status_messages',
      '#message_list' => [
        'warning' => [
          $this->t('Notice: The settings on this page requires knowledge about <a href=":pretix_eventsdocs_url">pretix events</a> and <a href=":pretix_docs_url">pretix in general</a>.', [
            ':pretix_events_docs_url' => 'https://docs.pretix.eu/en/latest/user/events/create.html',
            ':pretix_docs_url' => 'https://docs.pretix.eu/en/latest/index.html',
          ]),
        ],
      ],
    ];

    $this->buildFormPretix($form);

    // We need valid pretix settings before handling the rest of the settings.
    foreach (static::PRETIX_SUB_SECTIONS as $subSection) {
      $defaults = $this->settings->getPretixSettings($subSection);

      if (!$this->pretixHelper->pingApi($defaults)) {
        $form['message'] = [
          '#theme' => 'status_messages',
          '#message_list' => [
            'warning' => [
              $this->t('More settings will become available when pretix settings are saved and are valid'),
            ],
          ],
        ];

        return $form;
      }
    }

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

    $activeSettings = NULL;

    foreach (static::PRETIX_SUB_SECTIONS as $subSection) {
      $defaults = $this->settings->getPretixSettings($subSection);
      $isActive = $this->settings->isActivePretixSettings($defaults);

      if ($isActive) {
        $activeSettings = $defaults;
      }

      $languageOptions = [];
      foreach ($this->languageManager->getLanguages() as $language) {
        $languageOptions[$language->getId()] = $language->getName();
      }

      $canConnect = $this->pretixHelper->pingApi($defaults);

      $form[$section][$subSection] = [
        '#open' => !$canConnect,

        '#type' => 'details',
        '#title' => $this->t('pretix (%section)', ['%section' => $subSection])
        . ($isActive ? ' [' . $this->t('active') . ']' : ''),

        'domain' => [
          '#type' => 'textfield',
          '#title' => $this->t('Domain'),
          '#default_value' => $defaults->domain,
          '#required' => TRUE,
          '#description' => $this->t('The Drupal domain, e.g. <code>dpl-cms.dk</code>, that these pretix settings apply to.'),
          '#element_validate' => [[$this, 'validateDomain']],
        ],

        static::ELEMENT_PRETIX_URL => [
          '#type' => 'url',
          '#title' => $this->t('URL'),
          '#default_value' => $defaults->url,
          '#required' => TRUE,
          '#description' => $this->t('Enter a valid pretix service endpoint without path info, such as https://www.pretix.eu/'),
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

        'event_slug_template' => [
          '#type' => 'textfield',
          '#title' => $this->t('Event slug template'),
          '#default_value' => $defaults->eventSlugTemplate,
          '#required' => TRUE,
          '#description' => $this->t('Template used to generate event short forms in pretix. Placeholders<br/><code>{id}</code>: the event (node) id<br/><code>{randow}</code>: a random string'),
        ],

        'default_language_code' => [
          '#type' => 'select',
          '#options' => $languageOptions,
          '#title' => $this->t('Default language code'),
          '#default_value' => $defaults->defaultLanguageCode,
          '#required' => TRUE,
          '#description' => $this->t('Default language code used for pretix events'),
        ],

        self::ELEMENT_TEMPLATE_EVENTS => [
          '#type' => 'textarea',
          '#title' => $this->t('Template events used to create new events in pretix'),
          '#default_value' => $defaults->templateEvents,
          '#required' => $canConnect,
          '#description' => str_replace(
            '%example%',
            '<pre><code>' .
            <<<'YAML'
dpl-cms-default-template: The default event
dpl-cms-default-template-2: Another event
YAML
            . '</code></pre>',
            $this->t('Define one template per line on the form <code>«template short name»: «display name»</code>. Example: %example%')
          ),
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
          '#markup' => $this->t('The <a href=":pretix_webhook_url">pretix webhook URL</a> used by pretix to send notifications.',
            [
              ':pretix_webhook_url' => 'https://docs.pretix.eu/en/latest/api/webhooks.html',
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
    if (!preg_match('/^(?:[-A-Za-z0-9]+\.)+[A-Za-z]{2,6}$/', $value)) {
      $formState->setError($element, $this->t('@value is not a valid domain name', ['@value' => $value]));
    }
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
        '#description' => $this->t('The name of the organizer metadata property for the PSP element in pretix (case sensitive), example <code>PSP</code>.'),
      ],

      'list_header' => [
        '#theme' => 'form_element_label',
        '#title' => $this->t('Available PSP elements'),
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
        '#type' => 'textfield',
        '#min' => 0,
        '#title' => $this->t('Default event capacity'),
        '#default_value' => $defaults->capacity ?? 0,
        '#size' => 5,
        '#maxlength' => 5,
        '#description' => $this->t('The default capacity for new events.'),
      ],

      'maintain_copy' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Create and update in pretix'),
        '#default_value' => $defaults->maintainCopy ?? FALSE,
        '#return_value' => TRUE,
        '#description' => $this->t('Default value of %field on new event series.', ['%field' => $this->t('Create and update in pretix')]),
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
      '#title' => $this->t('Event form'),
      '#open' => TRUE,

      'weight' => [
        '#type' => 'select',
        '#options' => [
          -9999 => $this->t('Top'),
          9999 => $this->t('Bottom'),
        ],
        '#title' => $this->t('Location of pretix section'),
        '#default_value' => $defaults->weight ?? 9999,
        '#description' => $this->t('The location of the pretix section on event form.'),
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

    foreach ($form_state->getValue(self::SECTION_PRETIX) as $domain => $values) {
      $settings = new PretixSettings($values);
      if (!$this->pretixHelper->pingApi($settings)) {
        $form_state->setError(
          $form[self::SECTION_PRETIX][$domain][self::ELEMENT_PRETIX_URL],
          $this->t('Cannot connect to pretix (@domain)', ['@domain' => $domain])
        );

        continue;
      }

      $yaml = $values[self::ELEMENT_TEMPLATE_EVENTS] ?? NULL;
      try {
        $templateEvents = $this->pretixHelper->parseTemplateEvents($yaml);
        foreach ($templateEvents as $templateEvent => $label) {
          $errors = $this->pretixHelper->validateTemplateEvent($templateEvent,
            $settings);
          if (!empty($errors)) {
            $form_state->setError(
              $form[self::SECTION_PRETIX][$domain][self::ELEMENT_TEMPLATE_EVENTS],
              $this->t('Template event <a href=":pretix_event_url">@event</a> is not valid: @errors',
                [
                  '@event' => $templateEvent,
                  ':pretix_event_url' => $this->pretixHelper->getEventAdminUrl($settings,
                    $templateEvent),
                  '@errors' => implode('; ', array_map(static fn (ValidationException $exception) => $exception->getMessage(), $errors)),
                ])
            );
          }
        }
      }
      catch (\Exception $exception) {
        $form_state->setError(
          $form[self::SECTION_PRETIX][$domain][self::ELEMENT_TEMPLATE_EVENTS],
          $this->t('Invalid template events in @domain (@message).', [
            '@domain' => $domain,
            '@message' => $exception->getMessage(),
          ])
        );
      }
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
        $this->pretixHelper->pingApi();
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
