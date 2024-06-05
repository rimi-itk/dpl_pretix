<?php

namespace ItkDev\Pretix\Api\Entity;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/items.html
 */
class Item extends AbstractEntity
{
    protected static $fields = [
        // Internal ID of the item
        'id' => 'integer',
        // The item’s visible name
        'name' => 'multi-lingual string',
        // An optional name that is only used in the backend
        'internal_name' => 'string',
        // The item price that is applied if the price is not overwritten by variations or other options.
        'default_price' => 'money (string)',
        // The ID of the category this item belongs to (or null).
        'category' => 'integer',
        // If false, the item is hidden from all public lists and will not be sold.
        'active' => 'boolean',
        // A public description of the item. May contain Markdown syntax or can be null.
        'description' => 'multi-lingual string',
        // If true, customers can change the price at which they buy the product (however, the price can’t be set lower than the price defined by default_price or otherwise).
        'free_price' => 'boolean',
        // The VAT rate to be applied for this item (read-only, set through tax_rule).
        'tax_rate' => 'decimal (string)',
        // The internal ID of the applied tax rule (or null).
        'tax_rule' => 'integer',
        // true for items that grant admission to the event (such as primary tickets) and false for others (such as add-ons or merchandise).
        'admission' => 'boolean',
        // An integer, used for sorting
        'position' => 'integer',
        // A product picture to be displayed in the shop (read-only, can be null).
        'picture' => 'string',
        // Sales channels this product is available on, such as "web" or "resellers". Defaults to ["web"].
        'sales_channels' => 'list of strings',
        // The first date time at which this item can be bought (or null).
        'available_from' => 'datetime',
        // The last date time at which this item can be bought (or null).
        'available_until' => 'datetime',
        // The internal ID of a quota object, or null. If set, this item won’t be shown publicly as long as this quota is available.
        'hidden_if_available' => 'integer',
        // If true, this item can only be bought using a voucher that is specifically assigned to this item.
        'require_voucher' => 'boolean',
        // If true, this item is only shown during the voucher redemption process, but not in the normal shop frontend.
        'hide_without_voucher' => 'boolean',
        // If false, customers cannot cancel orders containing this item.
        'allow_cancel' => 'boolean',
        // This product can only be bought if it is included at least this many times in the order (or null for no limitation).
        'min_per_order' => 'integer',
        // This product can only be bought if it is included at most this many times in the order (or null for no limitation).
        'max_per_order' => 'integer',
        // If true, the check-in app should show a warning that this ticket requires special attention if such a product is being scanned.
        'checkin_attention' => 'boolean',
        // An original price, shown for comparison, not used for price calculations (or null).
        'original_price' => 'money (string)',
        // If true, orders with this product will need to be approved by the event organizer before they can be paid.
        'require_approval' => 'boolean',
        // If true, this item is only available as part of bundles.
        'require_bundling' => 'boolean',
        // If false, tickets are never generated for this product, regardless of other settings. If true, tickets are generated even if this is a non-admission or add-on product, regardless of event settings. If this is null, regular ticketing rules apply.
        'generate_tickets' => 'boolean',
        // If false, no waiting list will be shown for this product when it is sold out.
        'allow_waitinglist' => 'boolean',
        // If true, buying this product will yield a gift card.
        'issue_giftcard' => 'boolean',
        // Publicly show how many tickets are still available. If this is null, the event default is used.
        'show_quota_left' => 'boolean',
        // Shows whether or not this item has variations.
        'has_variations' => 'boolean',
        // A list with one object for each variation of this item. Can be empty. Only writable during creation, use separate endpoint to modify this later.
        'variations' => [
            'type' => 'list of objects',
            'object' => [
                // Internal ID of the variation
                'id' => 'integer',
                // The “name” of the variation
                'value' => 'multi-lingual string',
                // The price set directly for this variation or null
                'default_price' => 'money (string)',
                // The price used for this variation. This is either the same as default_price if that value is set or equal to the item’s default_price.
                'price' => 'money (string)',
                // An original price, shown for comparison, not used for price calculations (or null).
                'original_price' => 'money (string)',
                // If false, this variation will not be sold or shown.
                'active' => 'boolean',
                // A public description of the variation. May contain Markdown syntax or can be null.
                'description' => 'multi-lingual string',
                // An integer, used for sorting
                'position' => 'integer',
            ],
        ],
        // Definition of add-ons that can be chosen for this item. Only writable during creation, use separate endpoint to modify this later.
        'addons' => [
            'type' => 'list of objects',
            'object' => [
                // Internal ID of the item category the add-on can be chosen from.
                'addon_category' => 'integer',
                // The minimal number of add-ons that need to be chosen.
                'min_count' => 'integer',
                // The maximal number of add-ons that can be chosen.
                'max_count' => 'integer',
                // An integer, used for sorting
                'position' => 'integer',
                // Adding this add-on to the item is free
                'price_included' => 'boolean',
            ],
        ],
        // Definition of bundles that are included in this item. Only writable during creation, use separate endpoint to modify this later.
        'bundles' => [
            'type' => 'list of objects',
            'object' => [
                // Internal ID of the item that is included.
                'bundled_item' => 'integer',
                // Internal ID of the variation of the item (or null).
                'bundled_variation' => 'integer',
                // Number of items included
                'count' => 'integer',
                // Designated price of the bundled product. This will be used to split the price of the base item e.g. for mixed taxation. This is not added to the price.
                'designated_price' => 'money (string)',
            ],
        ],
    ];
}
