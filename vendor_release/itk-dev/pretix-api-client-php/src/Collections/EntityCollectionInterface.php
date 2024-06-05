<?php

namespace ItkDev\Pretix\Api\Collections;

use Doctrine\Common\Collections\Collection;

interface EntityCollectionInterface extends Collection
{
    /**
     * {@inheritdoc}
     *
     * @return array
     *
     * @psalm-return array<TKey,T>
     */
    public function toArray(bool $recursive = true);
}
