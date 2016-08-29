<?php

define('DBNAME', 'DatabaseL2');
define('POOL', 'mypool');
define('SCRIPTDIR', dirname(__FILE__) . DIRECTORY_SEPARATOR);

function get_pdo_handle($host) {
    $dsn = 'mysql:host='. $host. ';port=3306';
    $here = dirname(__FILE__) . PATH_SEPARATOR;
    $options = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET sql_mode="ANSI_QUOTES"',
                     //PDO::MYSQL_ATTR_SSL_CA => SCRIPTDIR . 'server-ca.pem',
                     //PDO::MYSQL_ATTR_SSL_CERT => SCRIPTDIR . 'client-cert.pem',
                     //PDO::MYSQL_ATTR_SSL_KEY => SCRIPTDIR . 'client-key.pem',
                     );
    $dbh = new PDO($dsn, 'root', '', $options);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $dbh;
}

abstract class StorageMethod {
    protected $dbh;

    public function __construct($dbh) {
        $this->dbh = $dbh;
    }

    abstract public function write($address);
    abstract public function initialize();

    public function cleanup() {
        return 0.0;
    }
}

abstract class InsertStorageMethod extends StorageMethod {
    public function initialize() {
        echo 'Initializing database: ' . DBNAME . PHP_EOL;
        $this->dbh->exec('DROP DATABASE IF EXISTS '. DBNAME);
        $this->dbh->exec('CREATE DATABASE '. DBNAME);
        echo 'Initializing schema.' . PHP_EOL;
        $this->dbh->exec('CREATE TABLE '. DBNAME .'.lcache_events (
                     "event_id" int(11) NOT NULL AUTO_INCREMENT,
                     "pool" varchar(255) NOT NULL,
                     "address" varchar(255),
                     "value" longblob,
                     "expiration" int(11),
                     "created" int(11) NOT NULL,
                     PRIMARY KEY ("event_id"),
                     KEY "expiration" ("expiration"),
                     KEY "lookup_miss" ("address","event_id")
                    )');
    }
}

class InsertDelete extends InsertStorageMethod {
    public function write($address) {
        $now = microtime(true);

        $sth = $this->dbh->prepare('INSERT INTO '. DBNAME .'.lcache_events ("pool", "address", "value", "created", "expiration") VALUES (:pool, :address, :value, :now, :expiration)');
        $sth->bindValue(':pool', POOL, PDO::PARAM_STR);
        $sth->bindValue(':address', $address, PDO::PARAM_STR);
        $sth->bindValue(':value', str_repeat('a', rand(1, 1024 * 1024)), PDO::PARAM_LOB);
        $sth->bindValue(':expiration', $now + rand(0, 86400), PDO::PARAM_INT);
        $sth->bindValue(':now', $now, PDO::PARAM_INT);
        $sth->execute();

        $event_id = $this->dbh->lastInsertId();

        $sth = $this->dbh->prepare('DELETE FROM '. DBNAME .'.lcache_events WHERE "address" LIKE :address AND "event_id" < :new_event_id');
        $sth->bindValue(':address', $address, PDO::PARAM_STR);
        $sth->bindValue(':new_event_id', $event_id, PDO::PARAM_INT);
        $sth->execute();

        $duration = microtime(true) - $now;
        return $duration;
    }
}

class InsertBatchDelete extends InsertStorageMethod {
    protected $deletions = [];
    protected $event_id_low_water = 0;

    public function write($address) {
        $now = microtime(true);

        $sth = $this->dbh->prepare('INSERT INTO '. DBNAME .'.lcache_events ("pool", "address", "value", "created", "expiration") VALUES (:pool, :address, :value, :now, :expiration)');
        $sth->bindValue(':pool', POOL, PDO::PARAM_STR);
        $sth->bindValue(':address', $address, PDO::PARAM_STR);
        $sth->bindValue(':value', str_repeat('a', rand(1, 1024 * 1024)), PDO::PARAM_LOB);
        $sth->bindValue(':expiration', $now + rand(0, 86400), PDO::PARAM_INT);
        $sth->bindValue(':now', $now, PDO::PARAM_INT);
        $sth->execute();

        if ($this->event_id_low_water === 0) {
            $this->event_id_low_water = $this->dbh->lastInsertId();
        }
        $this->deletions[] = $address;

        $duration = microtime(true) - $now;
        return $duration;
    }

    public function cleanup() {
        $now = microtime(true);

        $filler = implode(',', array_fill(0, count($this->deletions), '?'));
        $sth = $this->dbh->prepare('DELETE FROM '. DBNAME .'.lcache_events WHERE "event_id" < ? AND "address" IN ('. $filler .')');
        $sth->bindValue(1, $this->event_id_low_water, PDO::PARAM_INT);
        foreach ($this->deletions as $i => $address) {
            $sth->bindValue($i + 2, $address, PDO::PARAM_STR);
        }
        $sth->execute();

        $duration = microtime(true) - $now;
        return $duration;
    }
}

function repeat_writes(StorageMethod $storage, $repetitions) {
    $durations = [0.0, 0.0];
    for ($i = 0; $i < $repetitions; $i++) {
        $durations[0] += $storage->write('address:' . rand(0, 64));
    }
    $durations[1] = $storage->cleanup();
    return $durations;
}

$command = $argv[1];
$host = $argv[2];
$dbh = get_pdo_handle($host);
$storage = new InsertBatchDelete($dbh);

if ($command === 'init') {
    $storage->initialize();
    exit();
}
assert($command === 'run');

//$storage = new InsertDelete($dbh);
$durations = repeat_writes($storage, 40);
echo 'Real-time: ' . $durations[0] . PHP_EOL;
echo 'Cleanup:   ' . $durations[1] . PHP_EOL;

