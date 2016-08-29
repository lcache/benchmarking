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

function create_schema($dbh) {
    echo 'Initializing database: ' . DBNAME . PHP_EOL;
    $dbh->exec('DROP DATABASE IF EXISTS '. DBNAME);
    $dbh->exec('CREATE DATABASE '. DBNAME);
    echo 'Initializing schema.' . PHP_EOL;
    $dbh->exec('CREATE TABLE '. DBNAME .'.lcache_events (
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

function update_insert_delete($dbh, $address) {
    $now = microtime(true);

    $sth = $dbh->prepare('INSERT INTO '. DBNAME .'.lcache_events ("pool", "address", "value", "created", "expiration") VALUES (:pool, :address, :value, :now, :expiration)');
    $sth->bindValue(':pool', POOL, PDO::PARAM_STR);
    $sth->bindValue(':address', $address, PDO::PARAM_STR);
    $sth->bindValue(':value', str_repeat('a', rand(1, 1024 * 1024)), PDO::PARAM_LOB);
    $sth->bindValue(':expiration', $now + rand(0, 86400), PDO::PARAM_INT);
    $sth->bindValue(':now', $now, PDO::PARAM_INT);
    $sth->execute();

    $event_id = $dbh->lastInsertId();

    $sth = $dbh->prepare('DELETE FROM '. DBNAME .'.lcache_events WHERE "address" LIKE :address AND "event_id" < :new_event_id');
    $sth->bindValue(':address', $address, PDO::PARAM_STR);
    $sth->bindValue(':new_event_id', $event_id, PDO::PARAM_INT);
    $sth->execute();

    $duration = microtime(true) - $now;
    return $duration;
}

function repeat_writes($dbh, $function, $multiple) {
    $duration = 0.0;
    for ($i = 0; $i < $multiple; $i++) {
        $duration += $function($dbh, 'address:' . rand(0, 128));
    }
    return $duration;
}

$command = $argv[1];
$host = $argv[2];
$dbh = get_pdo_handle($host);

if ($command === 'init') {
    create_schema($dbh);
    exit();
}
assert($command === 'run');

$duration = repeat_writes($dbh, 'update_insert_delete', 40);
echo 'Duration: ' . $duration . PHP_EOL;

