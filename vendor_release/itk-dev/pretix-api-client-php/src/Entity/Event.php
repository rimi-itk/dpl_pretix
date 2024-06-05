<?php

namespace ItkDev\Pretix\Api\Entity;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/events.html
 *
 * @method string getName(string $locale = NULL)
 * @method string getSlug()
 * @method bool   getTestmode()
 * @method bool   hasSubevents()
 * @method bool   isPublic()
 */
class Event extends AbstractEntity
{
    protected static $fields = [
        // The event’s full name
        'name' => 'multi-lingual string',
        // A short form of the name, used e.g. in URLs.
        'slug' => 'string',
        // If <code class="docutils literal notranslate"><span class="pre">true</span></code>, the event ticket shop is publicly available.
        'live' => 'boolean',
        // If <code class="docutils literal notranslate"><span class="pre">true</span></code>, the ticket shop is in test mode.
        'testmode' => 'boolean',
        // The currency this event is handled in.
        'currency' => 'string',
        // The event’s start date
        'date_from' => 'datetime',
        // The event’s end date (or <code class="docutils literal notranslate"><span class="pre">null</span></code>)
        'date_to' => 'datetime',
        // The event’s admission date (or <code class="docutils literal notranslate"><span class="pre">null</span></code>)
        'date_admission' => 'datetime',
        // If <code class="docutils literal notranslate"><span class="pre">true</span></code>, the event shows up in places like the organizer’s public list of events
        'is_public' => 'boolean',
        // The date at which the ticket shop opens (or <code class="docutils literal notranslate"><span class="pre">null</span></code>)
        'presale_start' => 'datetime',
        // The date at which the ticket shop closes (or <code class="docutils literal notranslate"><span class="pre">null</span></code>)
        'presale_end' => 'datetime',
        // The event location (or <code class="docutils literal notranslate"><span class="pre">null</span></code>)
        'location' => 'multi-lingual string',
        // Latitude of the location (or <code class="docutils literal notranslate"><span class="pre">null</span></code>)
        'geo_lat' => 'float',
        // Longitude of the location (or <code class="docutils literal notranslate"><span class="pre">null</span></code>)
        'geo_lon' => 'float',
        // <code class="docutils literal notranslate"><span class="pre">true</span></code> if the event series feature is active for this event. Cannot change after event is created.
        'has_subevents' => 'boolean',
        // Values set for organizer-specific meta data parameters.
        'meta_data' => 'object',
        // A list of package names of the enabled plugins for this event.
        'plugins' => 'list',
        // If reserved seating is in use, the ID of a seating plan. Otherwise <code class="docutils literal notranslate"><span class="pre">null</span></code>.
        'seating_plan' => 'integer',
        // An object mapping categories of the seating plan (strings) to items in the event (integers or <code class="docutils literal notranslate"><span class="pre">null</span></code>).
        'seat_category_mapping' => 'object',
        // Event timezone name
        'timezone' => 'string',
    ];

    public function getUrl()
    {
        return sprintf('%s/control/event/%s/%s/', $this->getPretixUrl(), $this->getOrganizerSlug(), $this->getSlug());
    }

    public function getShopUrl()
    {
        return sprintf('%s/%s/%s/', $this->getPretixUrl(), $this->getOrganizerSlug(), $this->getSlug());
    }
}
