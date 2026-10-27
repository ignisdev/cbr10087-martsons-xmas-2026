<?php

/**
 * libphonenumber-for-php-lite data file
 * This file has been @generated from libphonenumber data
 * Do not modify!
 * @internal
 */

return [
    'generalDesc' => [
        'NationalNumberPattern' => '[1-46-8]\\d{2,5}',
        'PossibleLength' => [
            3,
            4,
            5,
            6,
        ],
        'PossibleLengthLocalOnly' => [],
    ],
    'tollFree' => [
        'NationalNumberPattern' => '11(?:[02]|6\\d{3})',
        'ExampleNumber' => '110',
        'PossibleLength' => [
            3,
            6,
        ],
        'PossibleLengthLocalOnly' => [],
    ],
    'premiumRate' => [
        'PossibleLength' => [
            -1,
        ],
        'PossibleLengthLocalOnly' => [],
    ],
    'emergency' => [
        'NationalNumberPattern' => '11[02]',
        'ExampleNumber' => '110',
        'PossibleLength' => [
            3,
        ],
        'PossibleLengthLocalOnly' => [],
    ],
    'shortCode' => [
        'NationalNumberPattern' => '11(?:[025]|6(?:00[06]|1(?:1[167]|23))|800\\d)|22(?:044|5(?:43|80)|7700|922)|33(?:11|3[34])|44844|600\\d\\d|7(?:0\\d{3}|464)|80808|118\\d\\d',
        'ExampleNumber' => '110',
        'PossibleLength' => [],
        'PossibleLengthLocalOnly' => [],
    ],
    'standardRate' => [
        'PossibleLength' => [
            -1,
        ],
        'PossibleLengthLocalOnly' => [],
    ],
    'carrierSpecific' => [
        'NationalNumberPattern' => '(?:33[13]|746)\\d|(?:22(?:[059]|7\\d)|(?:44|80)8|600|70\\d)\\d\\d',
        'ExampleNumber' => '3310',
        'PossibleLength' => [
            4,
            5,
            6,
        ],
        'PossibleLengthLocalOnly' => [],
    ],
    'smsServices' => [
        'NationalNumberPattern' => '(?:333|746)\\d|(?:22(?:[059]|7\\d)|(?:44|80)8|600|70\\d)\\d\\d',
        'ExampleNumber' => '3330',
        'PossibleLength' => [
            4,
            5,
            6,
        ],
        'PossibleLengthLocalOnly' => [],
    ],
    'id' => 'DE',
    'countryCode' => 0,
    'internationalPrefix' => '',
    'sameMobileAndFixedLinePattern' => false,
    'numberFormat' => [],
    'intlNumberFormat' => [],
    'mainCountryForCode' => false,
    'mobileNumberPortableRegion' => false,
];
