<?php return array(
    'root' => array(
        'name' => '__root__',
        'pretty_version' => '1.1.13',
        'version' => '1.1.13.0',
        'reference' => 'e24573b250e758838a6c559fb24518857e3c3020',
        'type' => 'library',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        '__root__' => array(
            'pretty_version' => '1.1.13',
            'version' => '1.1.13.0',
            'reference' => 'e24573b250e758838a6c559fb24518857e3c3020',
            'type' => 'library',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'giggsey/libphonenumber-for-php' => array(
            'pretty_version' => 'dev-gp-advanced-phone-field',
            'version' => 'dev-gp-advanced-phone-field',
            'reference' => '7d275b5b2ecb38f4515d512c87a73a50a1b000cc',
            'type' => 'library',
            'install_path' => __DIR__ . '/../giggsey/libphonenumber-for-php',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'giggsey/libphonenumber-for-php-lite' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '9999999-dev',
                1 => 'dev-gp-advanced-phone-field',
            ),
        ),
        'giggsey/locale' => array(
            'pretty_version' => '2.7.0',
            'version' => '2.7.0.0',
            'reference' => 'a5c65ea3c2630f27ccb78977990eefbee6dd8f97',
            'type' => 'library',
            'install_path' => __DIR__ . '/../giggsey/locale',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'symfony/polyfill-mbstring' => array(
            'pretty_version' => 'v1.32.0',
            'version' => '1.32.0.0',
            'reference' => '6d857f4d76bd4b343eac26d6b539585d2bc56493',
            'type' => 'library',
            'install_path' => __DIR__ . '/../symfony/polyfill-mbstring',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
