<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\dpl_pretix\Form\SettingsForm;

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
   * @return array<string, mixed>|string|null
   *   The pretix setting(s).
   */
  public function getPretix(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_PRETIX, $key);
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
   * @return array<string, mixed>|string|null
   *   The settings values.
   */
  private function getValue(string $section, ?string $key = NULL): array|string|null {
    $values = $this->config->get($section);

    return NULL === $key ? $values : ($values[$key] ?? NULL);
  }

}
