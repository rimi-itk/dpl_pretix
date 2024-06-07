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
   */
  public function getPretix(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_PRETIX, $key);
  }

  /**
   * Get PSP elements config.
   */
  public function getPspElements(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_PSP_ELEMENTS, $key);
  }

  /**
   * Get event nodes config.
   */
  public function getEventNodes(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_EVENT_NODES, $key);
  }

  /**
   * Get event form config.
   */
  public function getEventForm(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_EVENT_FORM, $key);
  }

  /**
   * Get config value.
   */
  private function getValue(string $section, ?string $key = NULL): array|string|null {
    $values = $this->config->get($section);

    return NULL === $key ? $values : ($values[$key] ?? NULL);
  }

}
