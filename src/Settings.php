<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\dpl_pretix\Form\SettingsForm;

/**
 *
 */
class Settings {
  private ImmutableConfig $config;

  public function __construct(
    ConfigFactoryInterface $configFactory,
  ) {
    $this->config = $configFactory->get(SettingsForm::CONFIG_NAME);
  }

  /**
   *
   */
  public function getPretix(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_PRETIX, $key);
  }

  /**
   *
   */
  public function getPspElements(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_PSP_ELEMENTS, $key);
  }

  /**
   *
   */
  public function getEventNodes(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_EVENT_NODES, $key);
  }

  /**
   *
   */
  public function getEventForm(?string $key = NULL): array|string|null {
    return $this->getValue(SettingsForm::SECTION_EVENT_FORM, $key);
  }

  /**
   *
   */
  private function getValue(string $section, ?string $key = NULL) {
    $values = $this->config->get($section);

    return NULL === $key ? $values : ($values[$key] ?? NULL);
  }

}
