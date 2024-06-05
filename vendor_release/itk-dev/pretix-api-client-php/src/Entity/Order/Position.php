<?php

namespace ItkDev\Pretix\Api\Entity\Order;

use ItkDev\Pretix\Api\Collections\EntityCollection;
use ItkDev\Pretix\Api\Collections\EntityCollectionInterface;
use ItkDev\Pretix\Api\Entity\AbstractEntity;
use ItkDev\Pretix\Api\Entity\SubEvent;
use ItkDev\Pretix\Api\Exception\InvalidArgumentException;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/orders.html#order-position-resource
 *
 * @method int          getItem()
 * @method int|SubEvent getSubevent()
 * @method string|null  getAttendeeName()
 * @method string|null  getAttendeeEmail()
 * @method float        getPrice()
 */
class Position extends AbstractEntity
{
    protected static $fields = [
        // Internal ID of the order position
        'id' => 'integer',
        // Order code of the order the position belongs to
        'order' => 'string',
        // Number of the position within the order
        'positionid' => 'integer',
        // Whether or not this position has been canceled. Note that by default, only non-canceled positions are shown.
        'canceled' => 'boolean',
        // ID of the purchased item
        'item' => 'integer',
        // ID of the purchased variation (or null)
        'variation' => 'integer',
        // Price of this position
        'price' => 'money (string)',
        // Specified attendee name for this position (or null)
        'attendee_name' => 'string',
        // Decomposition of attendee name (i.e. given name, family name)
        'attendee_name_parts' => 'object of strings',
        // Specified attendee email address for this position (or null)
        'attendee_email' => 'string',
        // Internal ID of the voucher used for this position (or null)
        'voucher' => 'integer',
        // VAT rate applied for this position
        'tax_rate' => 'decimal (string)',
        // VAT included in this position
        'tax_value' => 'money (string)',
        // The ID of the used tax rule (or null)
        'tax_rule' => 'integer',
        // Secret code printed on the tickets for validation
        'secret' => 'string',
        // Internal ID of the position this position is an add-on for (or null)
        'addon_to' => 'integer',
        // ID of the date inside an event series this position belongs to (or null).
        'subevent' => 'integer',
        // A random ID, e.g. for use in lead scanning apps
        'pseudonymization_id' => 'string',
        // List of check-ins with this ticket
        'checkins' => [
            'type' => 'list of objects',
            'object' => [
                // Internal ID of the check-in list
                'list' => 'integer',
                // Time of check-in
                'datetime' => 'datetime',
                // Indicates if this check-in been performed automatically by the system
                'auto_checked_in' => 'boolean',
            ],
        ],
        // List of ticket download options
        'downloads' => [
            'type' => 'list of objects',
            'object' => [
                // Ticket output provider (e.g. pdf, passbook)
                'output' => 'string',
                // Download URL
                'url' => 'string',
            ],
        ],
        // Answers to user-defined questions
        'answers' => [
            'type' => 'list of objects',
            'object' => [
                // Internal ID of the answered question
                'question' => 'integer',
                // Text representation of the answer
                'answer' => 'string',
                // The question’s identifier field
                'question_identifier' => 'string',
                // Internal IDs of selected option(s)s (only for choice types)
                'options' => 'list of integers',
                // The identifier fields of the selected option(s)s
                'option_identifiers' => 'list of strings',
            ],
        ],
        // The assigned seat. Can be null.
        'seat' => [
            'type' => 'objects',
            'object' => [
                // Internal ID of the seat instance
                'id' => 'integer',
                // Human-readable seat name
                'name' => 'string',
                // Identifier of the seat within the seating plan
                'seat_guid' => 'string',
            ],
        ],
        // Data object required for ticket PDF generation. By default, this field is missing. It will be added only if you add the pdf_data=true query parameter to your request.
        'pdf_data' => 'object',
    ];

    public function setQuotas($quotas)
    {
        if (is_array($quotas)) {
            $quotas = new EntityCollection($quotas);
        }
        if (!$quotas instanceof EntityCollectionInterface) {
            throw new InvalidArgumentException();
        }
        $this->data['quotas'] = $quotas;

        return $this;
    }

    public function setSubevent($subevent)
    {
        if (!(is_integer($subevent) || $subevent instanceof SubEvent)) {
            throw new InvalidArgumentException(sprintf('Sub-event must be an integer or an instance of %s', SubEvent::class));
        }
        $this->data['subevent'] = $subevent;

        return $this;
    }
}
