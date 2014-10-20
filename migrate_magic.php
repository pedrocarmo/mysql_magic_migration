<?php

/**
 * Configs
 */

//db credentials for both the old and new servers
$oldDbCreds = ['dbName' => "", "host" => "", 'user' => "", 'pass' => ""];
$newDbCreds = ['dbName' => "", "host" => "", 'user' => "", 'pass' => ""];

// what tables need the magic done on them
$tablesToMigrate =[ ];

// average new requests on the most active table
$avgReqSec = 3;


/**
 * Actual code
 */

function waitforYes($message)
{
    echo "{$message} Type 'yes' to continue: ";
    $handle = fopen ("php://stdin","r");
    $line = fgets($handle);
    if(trim($line) != 'yes'){
        echo "ABORTING!\n";
        exit;
    }
    echo "\n";
}

echo "=============\n";
echo "Welcome to magic migration\n\n";

echo "Planned Activities:\n";
echo " # Get last insert id's before the dump\n";
echo " # Dump the old database\n";
echo " # Migrate to the new database\n";
echo " # Update the auto-increment in the new DB (using a rough estimate of how many records may have been added)\n";
echo " # Point production to the new DB\n";
echo " # Migrate all missed records\n";
echo " # Enjoy new server\n";
echo "\n";
waitforYes("Lets start?");

echo "Connecting to the old database now...";
$oldDbh = new PDO ("mysql:dbname={$oldDbCreds['dbName']};host={$oldDbCreds['host']}", $newDbCreds['user'], $oldDbCreds['pass'], []);
echo "Done!\n";

echo "\n======Timer starts now!\n\n";
$startTime = time();

echo "Fetching last id from tables";
$tablesLastId = [];
$i = 0;
foreach ($tablesToMigrate as $table)
{
    // simple output every 5 tables
    echo $i%5 == 0 ? "." : "";
    $i++;

    $sth = $oldDbh->query("SELECT id
                    FROM {$table}
                    ORDER BY id DESC
                    LIMIT 1");

    if (!empty($sth))
    {
        $id = $sth->fetch()['id'];
        if (!empty($id) && $id > ($avgReqSec * 10))
        {
            $tablesLastId[$table] = $id;
        }
    }
}

// SAFETY DANCE
file_put_contents("safety.out", print_r($tablesLastId, 1));

echo "Done!\n";

unset($oldDbh);

waitforYes("Start the mysql dump?");

echo "Starting dump (grap a cup o'joe, this'll take a while)...";
$outputFilename = $oldDbCreds['dbName'] . ".sql";
exec("mysqldump -h {$oldDbCreds['host']} -u{$oldDbCreds['user']} -p{$oldDbCreds['pass']} {$oldDbCreds['dbName']} --single-transaction --quick > {$outputFilename}");
echo "Done!\n";

waitforYes("Start the migration?");

echo "Starting mysql input  (grap a cup o'joe, this'll take a while)...";
exec("mysql -h {$newDbCreds['host']} -u{$newDbCreds['user']} -p{$newDbCreds['pass']} {$newDbCreds['dbName']} < {$outputFilename}");
echo "Done!\n";

waitforYes("Start the auto-increment update? After this step you cannot take long to point the servers to the new DB! (timer will stop)");

echo "\n======Timer stops now!\n\n";
$endTime = time();
echo "Updating the auto increment value in the new db";
$i = 0;
$secs = ($endTime - $startTime) * 1.2; // why the multiplication? murphy's law, "shitty rate"
$avgRecords = $avgReqSec * $secs;
foreach ($tablesLastId as $table => $lastId)
{
    // simple output every 5 tables
    echo $i%5 == 0 ? "." : "";
    $i++;

    $newId = $lastId + $avgRecords;
    $newDbh->exec("ALTER TABLE {$table} auto_increment = {$newId}");
}
echo "Done!\n";

waitforYes("Act quicky and point the servers to the new instance.");

echo "Connecting to the databases now...";
$newDbh = new PDO ("mysql:dbname={$newDbCreds['dbName']};host={$newDbCreds['host']}", $newDbCreds['user'], $newDbCreds['pass'], []);
$oldDbh = new PDO ("mysql:dbname={$oldDbCreds['dbName']};host={$oldDbCreds['host']}", $newDbCreds['user'], $oldDbCreds['pass'], []);
echo "Done!\n";

echo "\n===== Relax, the scary part is done.\n\n";
echo "Let's migrate the records we missed during the dump...";
$i = 0;
foreach ($tablesLastId as $table => $lastId)
{
    // simple output every 5 tables
    echo $i%5 == 0 ? "." : "";
    $i++;

    $values = "";
    $insertSql = "";

    $querySql = "SELECT *
			  FROM {$table}
			  WHERE id > {$lastId}";

    // count records
    $records = 0;
    foreach ($oldDbh->query($querySql, PDO::FETCH_ASSOC) as $row)
    {
        if (empty($insertSql))
        {
            $insertSql = "REPLACE INTO {$table} (". implode(",", array_keys($row)) .") VALUES ";
        }

        array_walk($row, function(&$value, $key, $dbh) {
            $value = $dbh->quote($value);
        }, $oldDbh);

        $values .= "(" . implode(", ", $row) . "),";

        // more than 100 records? insert now!
        if ($records % 100 == 0)
        {
            $newDbh->exec($insertSql . trim($values, ",") );
            $values = "";
        }
        $records++;
    }

    // still have values? insert the bitches
    if (!empty($values))
    {
        $newDbh->exec($insertSql . trim($values, ",") );
        $values = "";
    }

}
echo "Done!\n";

echo "\n===== All done! Good luck now!\n";
echo "=============\n\n";
