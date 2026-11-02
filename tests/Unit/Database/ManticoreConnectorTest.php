<?php

use ManticoreEloquent\Database\ManticoreConnector;

/**
 * The DSN builder is the offline-testable part of the connector (connect()/getOptions()
 * need a live PDO, so they're exercised by the integration suite).
 */
function manticoreConnector(): ManticoreConnector
{
    return new class extends ManticoreConnector
    {
        public function dsn(array $config): string
        {
            return $this->getDsn($config);
        }
    };
}

it('builds a DSN with host and port', function () {
    expect(manticoreConnector()->dsn(['host' => '10.0.0.1', 'port' => 9306]))
        ->toBe('mysql:host=10.0.0.1;port=9306');
});

it('builds a DSN without a port', function () {
    expect(manticoreConnector()->dsn(['host' => '10.0.0.1']))
        ->toBe('mysql:host=10.0.0.1');
});

it('defaults the host to 127.0.0.1', function () {
    expect(manticoreConnector()->dsn([]))->toBe('mysql:host=127.0.0.1');
});

it('emulates prepares, on top of the options of Laravel', function () {
    $options = manticoreConnector()->getOptions(['options' => [PDO::ATTR_TIMEOUT => 3]]);

    expect($options[PDO::ATTR_EMULATE_PREPARES])->toBeTrue()
        ->and($options[PDO::ATTR_TIMEOUT])->toBe(3);
});
