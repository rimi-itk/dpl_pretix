<?php

namespace Drupal\dpl_pretix\Settings;

use Drupal\dpl_pretix\Settings;

/**
 * Abstract settings.
 */
abstract class AbstractSettings {

  /**
   * The values.
   */
  protected array $values;

  public function __construct(array $values) {
    $this->values = [];
    foreach ($values as $key => $value) {
      $name = Settings::kebab2camel($key);
      if (!property_exists($this, $name)) {
        throw new \RuntimeException(
          $name !== $key
            ? sprintf('Property "%s" ("%s") does not exist in class %s.', $name, $key, static::class)
            : sprintf('Property "%s" does not exist in class %s.', $name, static::class)
        );
      }
      $this->$name = $value;
      $this->values[Settings::camel2kebab($name)] = $value;
    }
  }

  /**
   * Settings as array.
   */
  public function toArray(): array {
    return $this->values;
  }

}
