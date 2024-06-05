<?php
namespace ItkDev\Pretix\Api\Entity;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/exporters.html
 *
 * @method string getIdentifier()
 * @method array  getInputParameters()
 */
class Exporter extends AbstractEntity
{
    public function getName()
    {
        return $this->getValue('verbose_name', []);
    }
}
