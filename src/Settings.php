<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\dpl_pretix\Form\SettingsForm;
use Drupal\dpl_pretix\Settings\EventFormSettings;
use Drupal\dpl_pretix\Settings\EventNodeSettings;
use Drupal\dpl_pretix\Settings\LibrariesSettings;
use Drupal\dpl_pretix\Settings\PretixSettings;
use Drupal\dpl_pretix\Settings\PspElementSettings;
use Symfony\Component\HttpFoundation\RequestStack;
use function Safe\preg_replace;

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
    private readonly RequestStack $requestStack,
  ) {
    $this->config = $configFactory->get(SettingsForm::CONFIG_NAME);
  }

  /**
   * Get pretix config.
   *
   * @return \Drupal\dpl_pretix\Settings\PretixSettings
   *   The pretix setting(s).
   */
  public function getPretixSettings(string $domain = NULL): PretixSettings {
    $domain ??= $this->getCurrentDomain();

    $values = $this->getValue(SettingsForm::SECTION_PRETIX);
    $pretixValues = [];
    if (isset($values[$domain])) {
      $pretixValues = $values[$domain];
    }
    else {
      // Find settings by host.
      foreach ($values as $key => $config) {
        if (is_array($config) && $domain === ($config['domain'] ?? NULL)) {
          $pretixValues = $config;
        }
      }
    }

    return new PretixSettings($pretixValues);
  }

  /**
   * Check if a pretix settings is active.
   */
  public function isActivePretixSettings(PretixSettings $settings): bool {
    return $settings->domain === $this->getCurrentDomain();
  }

  /**
   * Get current domain.
   */
  public function getCurrentDomain(): ?string {
    return $this->requestStack->getCurrentRequest()?->getHost();
  }

  /**
   * Get libraries settings.
   */
  public function getLibrarySettings(): LibrariesSettings {
    return new LibrariesSettings($this->getValue(SettingsForm::SECTION_LIBRARIES));
  }

  /**
   * Get PSP elements config.
   *
   * @return \Drupal\dpl_pretix\Settings\PspElementSettings
   *   The PSP elements setting(s).
   */
  public function getPspElements(): PspElementSettings {
    return new PspElementSettings($this->getValue(SettingsForm::SECTION_PSP_ELEMENTS));
  }

  /**
   * Get event nodes config.
   *
   * @return \Drupal\dpl_pretix\Settings\EventNodeSettings
   *   The event nodes setting(s).
   */
  public function getEventNodes(): EventNodeSettings {
    return new EventNodeSettings($this->getValue(SettingsForm::SECTION_EVENT_NODES));
  }

  /**
   * Get event form config.
   *
   * @return \Drupal\dpl_pretix\Settings\EventFormSettings
   *   The event from setting(s).
   */
  public function getEventForm(): EventFormSettings {
    return new EventFormSettings($this->getValue(SettingsForm::SECTION_EVENT_FORM));
  }

  /**
   * Convert kebab_case to camelCase.
   */
  public static function kebab2camel(string $value): string {
    return lcfirst(str_replace('_', '', ucwords($value, '_')));
  }

  /**
   * Convert camelCase to kebab_case.
   *
   * @see https://stackoverflow.com/a/40514305/2502647
   */
  public static function camel2kebab(string $value): string {
    return strtolower(preg_replace('/(?<=\d)(?=[A-Za-z])|(?<=[A-Za-z])(?=\d)|(?<=[a-z])(?=[A-Z])/', '_', $value));
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
