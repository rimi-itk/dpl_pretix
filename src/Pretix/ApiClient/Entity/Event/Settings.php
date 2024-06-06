<?php

namespace Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event;

use Drupal\dpl_pretix\Pretix\ApiClient\Entity\AbstractEntity;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/events.html#event-settings
 *
 * @method string getName(string $locale = NULL)
 * @method string getSlug()
 * @method bool   getTestmode()
 * @method bool   hasSubevents()
 * @method bool   isPublic()
 */
class Settings extends AbstractEntity
{
    public const CONTACT_MAIL = 'contact_mail';

    public function getContactMail()
    {
        return $this->getValue(static::CONTACT_MAIL, []);
    }
}
