<?php

namespace ItkDev\Pretix\Api;

/**
 * @internal
 * @coversNothing
 */
class OrganizerTest extends AbstractClientTest
{
    public function testGetOrganizers()
    {
        $organizers = $this->client()->getOrganizers();

        $this->assertSame(1, $organizers->count());
        $this->assertCount(1, $organizers);
    }

    public function testGetOrganizer()
    {
        $organizer = $this->client()->getOrganizer('pretix-api-client');

        $this->assertSame('pretix-api-client', $organizer->getName());
    }
}
