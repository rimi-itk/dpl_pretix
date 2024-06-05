<?php

namespace ItkDev\Pretix\Api\Entity;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/webhooks.html
 *
 * @method bool     getEnabled()
 * @method string   getTargetUrl()
 * @method string   getAllEvents()
 * @method string[] getLimitEvents()
 * @method string[] getActionTypes()
 */
class Webhook extends AbstractEntity
{
    protected static $fields = [
        // Internal ID of the webhook
        'id' => 'integer',
        // If false, this webhook will not receive any notifications
        'enabled' => 'boolean',
        // The URL to call
        'target_url' => 'string',
        // If true, this webhook will receive notifications on all events of this organizer
        'all_events' => 'boolean',
        // If all_events is false, this is a list of event slugs this webhook is active for
        'limit_events' => 'list of strings',
        // A list of action type filters that limit the notifications sent to this webhook. See below for valid values
        'action_types' => 'list of strings',
    ];
}
