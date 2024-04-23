<?php

namespace Drupal\dpl_pretix\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Module settings form.
 */
final class SettingsForm extends ConfigFormBase {
  use StringTranslationTrait;

  private const CONFIG_NAME = 'dpl_pretix.settings';

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
    // Default settings.
    $config = $this->getConfig();

    $form['#tree'] = TRUE;

    $this->buildFormPretix($form, $form_state, $config);
    $this->buildFormLibraries($form, $form_state, $config);
    $this->buildFormPspElements($form, $form_state, $config);
    $this->buildFormEventNodes($form, $form_state, $config);

    return $form;
  }

  /**
   * Build form.
   */
  private function buildFormPretix(array &$form, FormStateInterface $formState, Config $config): void {
    // Default settings.
    $form['pretix'] = [
      '#type' => 'details',
      '#title' => $this->t('Pretix'),
      '#open' => empty($config->get('pretix.url'))
      || empty($config->get('pretix.organizer_slug'))
      || empty($config->get('pretix.api_key'))
      || empty($config->get('pretix.template_event_slug')),

      'url' => [
        '#type' => 'url',
        '#title' => t('URL'),
        '#default_value' => $config->get('pretix.url'),
        '#required' => TRUE,
        '#description' => t('Enter a valid Pretix service endpoint without path info, such as https://www.pretix.eu/'),
      ],

      'organizer_slug' => [
        '#type' => 'textfield',
        '#title' => $this->t('Organizer slug'),
        '#default_value' => $config->get('pretix.organizer_slug'),
        '#required' => TRUE,
        '#description' => $this->t('This is the default organizer slug used when connecting to Pretix. If you provide slug/API key for a specific library (below), events related to that library will use that key instead of the default key.'),
      ],

      'api_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('The API key of the Organizer Team'),
        '#default_value' => $config->get('pretix.api_key'),
        '#required' => TRUE,
        '#description' => $this->t('This is the default API key used when connecting to Pretix. If you provide slug/API key for a specific library (below), events related to that library will use that key instead of the default key.'),
      ],

      'template_event_slug' => [
        '#type' => 'textfield',
        '#title' => $this->t('The slug of the default event template'),
        '#default_value' => $config->get('pretix.template_event_slug'),
        '#required' => TRUE,
        '#description' => $this->t('This is the slug of the default event template. When events are created their setting etc. are copied from this event.'),
      ],
    ];
  }

  /**
   * Build form.
   */
  private function buildFormLibraries(array &$form, FormStateInterface $formState, Config $config): void {
    $form['libraries'] = [
      '#type' => 'details',
      '#title' => $this->t('Individual library slug/API keys'),
      '#description' => $this->t('Optional. If you have several organizers at Pretix, each library can have their own slug/API key. In that case, the base slug/API key will be overridden by the provided key when sending data on events related to this library.'),
      '#open' => TRUE,
    ];
  }

  /**
   * Build form.
   */
  private function buildFormPspElements(array &$form, FormStateInterface $formState, Config $config): void {
    $form['psp_elements'] = [
      '#type' => 'details',
      '#title' => $this->t('PSP elements'),
      '#open' => TRUE,

      'pretix_psp_meta_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('Pretix PSP property name'),
        '#default_value' => $config->get('psp_elements.pretix_psp_meta_key'),
        '#size' => 50,
        '#maxlength' => 50,
        '#description' => $this->t('The name of the organizer metadata property for the PSP element in Pretix (case sensitive).'),
      ],
    ];
  }

  /**
   * Build form.
   */
  private function buildFormEventNodes(array &$form, FormStateInterface $formState, Config $config): void {
    $form['event_nodes'] = [
      '#type' => 'details',
      '#title' => $this->t('Pretix event node defaults'),
      '#open' => TRUE,

      'capacity' => [
        '#type' => 'number',
        '#min' => 0,
        '#title' => $this->t('Capacity'),
        '#default_value' => $config->get('event_nodes.capacity') ?? 0,
        '#size' => 5,
        '#maxlength' => 5,
        '#description' => $this->t('The default capacity for new events. Set to 0 for unlimited capacity.'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $formState): void {
    $config = $this->getConfig();

    foreach (['pretix', 'libraries', 'psp_elements', 'event_nodes'] as $section) {
      $values = $formState->getValue($section);
      if (is_array($values)) {
        foreach ($values as $key => $value) {
          $config->set($section . '.' . $key, $value);
        }
      }
    }

    $config->save();

    parent::submitForm($form, $formState);
  }

  /**
   * Get module config.
   */
  private function getConfig(): Config|ImmutableConfig {
    return $this->config(self::CONFIG_NAME);
  }

}
