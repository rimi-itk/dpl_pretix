<?php

namespace ItkDev\Pretix\Api\Entity;

/**
 * @see https://docs.pretix.eu/en/latest/api/resources/questions.html
 *
 * @method int getId()
 */
class Question extends AbstractEntity
{
    protected static $fields = [
        // Internal ID of the question
        'id' => 'integer',
        // The field label shown to the customer
        'question' => 'multi-lingual string',
        // The help text shown to the customer
        'help_text' => 'multi-lingual string',
        // The expected type of answer.
        'type' => 'string',
        // If true, the question needs to be filled out.
        'required' => 'boolean',
        // An integer, used for sorting
        'position' => 'integer',
        // List of item IDs this question is assigned to.
        'items' => 'list of integers',
        // An arbitrary string that can be used for matching with other sources.
        'identifier' => 'string',
        // If true, this question will not be asked while buying the ticket, but will show up when redeeming the ticket instead.
        'ask_during_checkin' => 'boolean',
        // If true, the question will only be shown in the backend.
        'hidden' => 'boolean',
        // If true, the question will only be shown on invoices.
        'print_on_invoice' => 'boolean',
        'options' => [
            // In case of question type C or M, this lists the available objects. Only writable during creation, use separate endpoint to modify this later.
            'type' => 'list of objects',
            'object' => [
                // Internal ID of the option
                'id' => 'integer',
                // An integer, used for sorting
                'position' => 'integer',
                // An arbitrary string that can be used for matching with other sources.
                'identifier' => 'string',
                // The displayed value of this option
                'answer' => 'multi-lingual string',
            ],
        ],
        // Internal ID of a different question. The current question will only be shown if the question given in this attribute is set to the value given in dependency_value. This cannot be combined with ask_during_checkin.
        'dependency_question' => 'integer',
        // If dependency_question is set to a boolean question, this should be ["True"] or ["False"]. Otherwise, it should be a list of identifier values of question options.
        'dependency_values' => 'list of strings',
        // An old version of dependency_values that only allows for one value. Deprecated.
        'dependency_value' => 'string',
    ];

    public function getQuestion()
    {
        return $this->getValue('question', []);
    }
}
