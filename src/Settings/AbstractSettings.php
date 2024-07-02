<?php

namespace Drupal\dpl_pretix\Settings;

/**
 * Abstract settings.
 */
abstract class AbstractSettings {

  public function __construct(array $values) {
    foreach ($values as $key => $value) {
      $name = $this->toCamelCase($key);
      if (!property_exists($this, $name)) {
        throw new \RuntimeException(
          $name !== $key
            ? sprintf('Property "%s" ("%s") does not exist in class %s.', $name, $key, static::class)
            : sprintf('Property "%s" does not exist in class %s.', $name, static::class)
        );
      }
      $this->$name = $value;
    }
  }

  /**
   * Convert a snake_case name to camelCase.
   */
  protected function toCamelCase(string $value) {
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $value))));
  }

}
