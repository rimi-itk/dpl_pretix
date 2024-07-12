<?php

namespace Drupal\dpl_pretix\Settings\Item;

use Drupal\dpl_pretix\Settings;
use function Safe\sprintf;

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
   *
   * @var array<string, mixed>
   */
  protected array $values;

  /**
   * Constructor.
   *
   * @param array<string, mixed> $values
   *   The values.
   */
  public function __construct(array $values) {
    $this->values = [];

    foreach (static::$listProperties as $property => $class) {
      if (isset($values[$property]) && is_array($values[$property])) {
        $values[$property] = array_map(static fn(array $vals) => new $class($vals), $values[$property]);
      }
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
   *
   * @return array<string, mixed>
   *   The values.
   */
  public function toArray(): array {
    return $this->values;
  }

}
