<?php

namespace ItkDev\Pretix\Api\Entity;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/quotas.html
 *
 * @method int[] getItems()
 * @method int   getSubevent()
 */
class Quota extends AbstractEntity
{
    protected static $fields = [
        // Internal ID of the quota
        'id' => 'integer',
        // The internal name of the quota
        'name' => 'string',
        // The size of the quota or null for unlimited
        'size' => 'integer',
        // List of item IDs this quota acts on.
        'items' => 'list of integers',
        // List of item variation IDs this quota acts on.
        'variations' => 'list of integers',
        // ID of the date inside an event series this quota belongs to (or null).
        'subevent' => 'integer',
        // If true, the quota will “close” as soon as it is sold out once. Even if tickets become available again, they will not be sold unless the quota is set to open again.
        'close_when_sold_out' => 'boolean',
        // Whether the quota is currently closed (see above field).
        'closed' => 'boolean',
    ];

    public function __construct(array $data)
    {
        parent::__construct($data + ['availability' => null]);
    }

    public function setAvailability(QuotaAvailability $availability)
    {
        $this->data['availability'] = $availability;

        return $this;
    }
}
