<?php

namespace ManticoreEloquent\Database;

use Illuminate\Database\Connectors\MySqlConnector;
use PDO;

class ManticoreConnector extends MySqlConnector
{
    /**
     * Connect without the MySQL-only session setup (USE, SET NAMES, isolation level,
     * timezone, sql_mode) — Manticore rejects every one of those right after connecting.
     *
     * @param  array  $config
     * @return \PDO
     */
    public function connect(array $config): PDO
    {
        $dsn     = $this->getDsn($config);
        $options = $this->getOptions($config);

        return $this->createConnection($dsn, $config, $options);
    }

    /**
     * Manticore's server-side prepared statements are limited, so we emulate prepares and
     * let PDO interpolate the bindings client-side. That's what keeps MATCH(?) working.
     *
     * @param  array  $config
     * @return array
     */
    public function getOptions(array $config): array
    {
        return array_replace(parent::getOptions($config), [
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    }

    /**
     * There's no database to select in Manticore, so the DSN never carries a dbname.
     *
     * @param  array  $config
     * @return string
     */
    protected function getDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';

        if (isset($config['port'])) {
            return "mysql:host={$host};port={$config['port']}";
        }

        return "mysql:host={$host}";
    }
}
