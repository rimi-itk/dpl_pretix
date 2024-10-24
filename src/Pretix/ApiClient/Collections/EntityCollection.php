<?php

namespace Drupal\dpl_pretix\Pretix\ApiClient\Collections;

use Doctrine\Common\Collections\ArrayCollection;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\AbstractEntity;

class EntityCollection extends ArrayCollection implements EntityCollectionInterface
{
    public function toArray(bool $recursive = true)
    {
        $elements = parent::toArray();

        if ($recursive) {
            foreach ($elements as &$element) {
                if ($element instanceof AbstractEntity || $element instanceof EntityCollectionInterface) {
                    $element = $element->toArray($recursive);
                }
            }
        }

        return $elements;
    }
}
