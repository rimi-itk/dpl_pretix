<?php

namespace ItkDev\Pretix\Api\Entity;

use ItkDev\Pretix\Api\Collections\EntityCollectionInterface;
use ItkDev\Pretix\Api\Entity\Order\Position;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/orders.html
 *
 * @method string                                                     getCode()
 * @method \ItkDev\Pretix\Api\Collections\EntityCollection|Position[] getPositions()
 * @method string|null                                                getEmail()
 */
class Order extends AbstractEntity
{
    protected static $fields = [
        // Order code
        'code' => 'string',
        // Order status, one of:
        'status' => 'string',
        // If true, this order was created when the event was in test mode. Only orders in test mode can be deleted.
        'testmode' => 'boolean',
        // The secret contained in the link sent to the customer
        'secret' => 'string',
        // The customer email address
        'email' => 'string',
        // The locale used for communication with this customer
        'locale' => 'string',
        // Channel this sale was created through, such as "web".
        'sales_channel' => 'string',
        // Time of order creation
        'datetime' => 'datetime',
        // The order will expire, if it is still pending by this time
        'expires' => 'datetime',
        // DEPRECATED AND INACCURATE Date of payment receipt
        'payment_date' => 'date',
        // DEPRECATED AND INACCURATE Payment provider used for this order
        'payment_provider' => 'string',
        // Total value of this order
        'total' => 'money (string)',
        // Internal comment on this order
        'comment' => 'string',
        // If true, the check-in app should show a warning that this ticket requires special attention if a ticket of this order is scanned.
        'checkin_attention' => 'boolean',
        // Invoice address information (can be null)
        'invoice_address' => [
            'type' => 'object',
            'object' => [
                // Last modification date of the address
                'last_modified' => 'datetime',
                // Customer company name
                'company' => 'string',
                // Business or individual customers (always false for orders created before pretix 1.7, do not rely on it).
                'is_business' => 'boolean',
                // Customer name
                'name' => 'string',
                // Customer name decomposition
                'name_parts' => 'object of strings',
                // Customer street
                'street' => 'string',
                // Customer ZIP code
                'zipcode' => 'string',
                // Customer city
                'city' => 'string',
                // Customer country code
                'country' => 'string',
                // Customer state (ISO 3166-2 code). Only supported in AU, BR, CA, CN, MY, MX, and US.
                'state' => 'string',
                // Customer’s internal reference to be printed on the invoice
                'internal_reference' => 'string',
                // Customer VAT ID
                'vat_id' => 'string',
                // true, if the VAT ID has been validated against the EU VAT service and validation was successful. This only happens in rare cases.
                'vat_id_validated' => 'string',
            ],
        ],
        // List of order positions (see below). By default, only non-canceled positions are included.
        'positions' => 'list of objects',
        // List of fees included in the order total. By default, only non-canceled fees are included.
        'fees' => [
            'type' => 'list of objects',
            'object' => [
                // Type of fee (currently payment, passbook, other)
                'fee_type' => 'string',
                // Fee amount
                'value' => 'money (string)',
                // Human-readable string with more details (can be empty)
                'description' => 'string',
                // Internal string (i.e. ID of the payment provider), can be empty
                'internal_type' => 'string',
                // VAT rate applied for this fee
                'tax_rate' => 'decimal (string)',
                // VAT included in this fee
                'tax_value' => 'money (string)',
                // The ID of the used tax rule (or null)
                'tax_rule' => 'integer',
                // Whether or not this fee has been canceled.
                'canceled' => 'boolean',
            ],
        ],
        // List of ticket download options for order-wise ticket downloading. This might be a multi-page PDF or a ZIP file of tickets for outputs that do not support multiple tickets natively. See also order position download options.
        'downloads' => [
            'type' => 'list of objects',
            'object' => [
                // Ticket output provider (e.g. pdf, passbook)
                'output' => 'string',
                // Download URL
                'url' => 'string',
            ],
        ],
        // If true and the order is pending, this order needs approval by an organizer before it can continue. If true and the order is canceled, this order has been denied by the event organizer.
        'require_approval' => 'boolean',
        // The full URL to the order confirmation page
        'url' => 'string',
        // List of payment processes (see below)
        'payments' => 'list of objects',
        // List of refund processes (see below)
        'refunds' => 'list of objects',
        // Last modification of this object
        'last_modified' => 'datetime',
    ];

    public function __construct(array $data)
    {
        if (isset($data['positions'])) {
            $data['positions'] = $this->buildCollection(Position::class, $data['positions']);
        }
        parent::__construct($data);
    }

    public function setEvent($event)
    {
        $this->set('event', $event);
    }

    public function setPositions(EntityCollectionInterface $positions)
    {
        $this->set('positions', $positions);
        // @TODO Update totals on order.
    }

    public function getUrl()
    {
        $event = $this->getEvent();
        $eventSlug = $event instanceof Event ? $event->getSlug() : $event;

        return sprintf('%s/control/event/%s/%s/orders/%s', $this->getPretixUrl(), $this->getOrganizerSlug(), $eventSlug, $this->getCode());
    }
}
