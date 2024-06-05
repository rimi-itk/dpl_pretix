<?php

namespace ItkDev\Pretix\Api\Collections;

use ItkDev\Pretix\Api\Entity\Order;
use PHPUnit\Framework\TestCase;

class EntityCollectionTest extends TestCase
{
    public function testToArray()
    {
        $collection = new EntityCollection([
            new Order([
                'code' => 'order-1',
                'positions' => [
                    [
                        'id' => 1,
                        'order' => 'order-1',
                    ],
                    [
                        'id' => 2,
                        'order' => 'order-1',
                    ],
                ],
            ]),
        ]);

        $expected = [
            new Order([
                'code' => 'order-1',
                'positions' => [
                    [
                        'id' => 1,
                        'order' => 'order-1',
                    ],
                    [
                        'id' => 2,
                        'order' => 'order-1',
                    ],
                ],
            ]),
        ];
        $actual = $collection->toArray(false);
        $this->assertEquals($expected, $actual);

        $expected = [
            [
                'code' => 'order-1',
                'positions' => [
                    [
                        'id' => 1,
                        'order' => 'order-1',
                    ],
                    [
                        'id' => 2,
                        'order' => 'order-1',
                    ],
                ],
            ],
        ];

        $actual = $collection->toArray(true);
        $this->assertEquals($expected, $actual);
    }
}
