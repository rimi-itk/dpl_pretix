<?php

namespace Drupal\dpl_pretix\Settings\Item;

use Drupal\dpl_pretix\Settings;

/**
 * Abstract item.
 */
abstract class AbstractItem {

  /**
   * List properties map from name to type.
   *
   * @var array<string, string>
   */
  protected static array $listProperties = [];

  /**
   * The values.
   */
  protected array $values;

  public function __construct(array $values) {
    $this->values = [];

    foreach (static::$listProperties as $property => $class) {
      $values[$property] = array_map(static fn (array $vals) => new $class($vals), $values[$property]);
    }

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
   * Values as array.
   */
  public function toArray(): array {
    return $this->values;
  }

}
