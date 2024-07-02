<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\dpl_pretix\Form\SettingsForm;
use Drupal\dpl_pretix\Settings\PretixSettings;

/**
 * Settings for dpl_pretix.
 */
class Settings {
  /**
   * The config.
   */
  private ImmutableConfig $config;

  public function __construct(
    ConfigFactoryInterface $configFactory,
  ) {
    $this->config = $configFactory->get(SettingsForm::CONFIG_NAME);
  }

  /**
   * Get pretix config.
   *
   * @return \Drupal\dpl_pretix\Settings\PretixSettings
   *   The pretix setting(s).
   */
  public function getPretixSettings(): PretixSettings {
    return new PretixSettings($this->getValue(SettingsForm::SECTION_PRETIX));
  }

  /**
   * Get PSP elements config.
   *
   * @return array<string, mixed>|string|null
   *   The PSP elements setting(s).
   */
  public function getPspElements(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_PSP_ELEMENTS, $key);
  }

  /**
   * Get event nodes config.
   *
   * @return array<string, mixed>|string|null
   *   The event nodes setting(s).
   */
  public function getEventNodes(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_EVENT_NODES, $key);
  }

  /**
   * Get event form config.
   *
   * @return array<string, mixed>|string|null
   *   The event from setting(s).
   */
  public function getEventForm(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_EVENT_FORM, $key);
  }

  /**
   * Get settings value.
   *
   * @return array<string, mixed>
   *   The settings values.
   */
  private function getValue(string $section): array {
    $values = $this->config->get($section);

    return is_array($values) ? $values : [];
  }

}
