<?php

namespace ItkDev\Pretix\Api\Entity;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/subevents.html
 *
 * @method string|Event getEvent()
 */
class SubEvent extends AbstractEntity
{
    protected static $fields = [
        // Internal ID of the sub-event
        'id' => 'integer',
        // The sub-event’s full name
        'name' => 'multi-lingual string',
        // The slug of the parent event
        'event' => 'string',
        // If true, the sub-event ticket shop is publicly available.
        'active' => 'boolean',
        // If true, the sub-event ticket shop is publicly shown in lists.
        'is_public' => 'boolean',
        // The sub-event’s start date
        'date_from' => 'datetime',
        // The sub-event’s end date (or null)
        'date_to' => 'datetime',
        // The sub-event’s admission date (or null)
        'date_admission' => 'datetime',
        // The sub-date at which the ticket shop opens (or null)
        'presale_start' => 'datetime',
        // The sub-date at which the ticket shop closes (or null)
        'presale_end' => 'datetime',
        // The sub-event location (or null)
        'location' => 'multi-lingual string',
        // Latitude of the location (or null)
        'geo_lat' => 'float',
        // Longitude of the location (or null)
        'geo_lon' => 'float',
        // List of items for which this sub-event overrides the default price
        'item_price_overrides' => [
            'type' => 'list of objects',
            'object' => [
                // The internal item ID
                'item' => 'integer',
                // The price or null for the default price
                'price' => 'money (string)',
            ],
        ],
        // List of variations for which this sub-event overrides the default price
        'variation_price_overrides' => [
            'type' => 'list of objects',
            'object' => [
                // The internal variation ID
                'variation' => 'integer',
                // The price or null for the default price
                'price' => 'money (string)',
            ],
        ],
        // Values set for organizer-specific meta data parameters.
        'meta_data' => 'object',
        // If reserved seating is in use, the ID of a seating plan. Otherwise null.
        'seating_plan' => 'integer',
        // An object mapping categories of the seating plan (strings) to items in the event (integers or null).
        'seat_category_mapping' => 'object',
    ];

    /**
     * @return string
     */
    public function getEventSlug()
    {
        $event = $this->getEvent();

        return $event instanceof Event ? $event->getSlug() : $event;
    }

    public function getUrl()
    {
        return sprintf('%s/control/event/%s/%s/subevents/%d/', $this->getPretixUrl(), $this->getOrganizerSlug(), $this->getEvent(), $this->getId());
    }

    public function getShopUrl()
    {
        return sprintf('%s/%s/%s/%d/', $this->getPretixUrl(), $this->getOrganizerSlug(), $this->getEvent(), $this->getId());
    }
}
