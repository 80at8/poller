<?php

namespace Poller\Web\Services;

use InvalidArgumentException;
use PDO;
use Poller\Log;
use RuntimeException;

class Database
{
    const SONAR_URL = 'SONAR_URL';
    const POLLER_API_KEY = 'POLLER_API_KEY';
    const LOG_EXCEPTIONS = 'LOG_EXCEPTIONS';

    private PDO $dbh;
    public function __construct()
    {
        $cnf = [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        $this->dbh = new PDO('sqlite:'.  __DIR__ . './../../../permanent_config/database', null, null, $cnf);
        $this->createTablesIfRequired();
    }

    public function get(string $key):?string
    {
        $query = <<<SQL
SELECT value
FROM settings
WHERE key = ?
SQL;

        $statement = $this->dbh->prepare($query);
        $statement->execute([trim($key)]);
        $result = $statement->fetch();
        if (!$result) {
            return null;
        }
        return $result['value'];
    }

    public function set(string $key, string $value)
    {
        if ($this->get($key) === null) {
            $query = <<<SQL
INSERT INTO settings (value, key) VALUES(?, ?);
SQL;
        } else {
            $query = <<<SQL
UPDATE settings SET value = ? WHERE key = ?
SQL;
        }

        $statement = $this->dbh->prepare($query);
        return $statement->execute([$value, $key]);
    }

    private function createTablesIfRequired()
    {
        $query = <<<SQL
CREATE TABLE IF NOT EXISTS
 settings (
    key STRING PRIMARY KEY,
    value STRING NOT NULL
) WITHOUT ROWID;
SQL;

        $this->dbh->exec($query);
    }
}