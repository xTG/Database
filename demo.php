<?php

include_once('database.class.php');

/****************************************************************************/
set_time_limit(0);
$nbDatas = 20000;

echo '/********************************************************************/<br />';
echo '*                       Initialisation                               *<br />';
echo '/********************************************************************/<br />';
$database = new Database();
$database->set('server', '127.0.0.1')
         ->set('username', 'root')
         ->set('database', 'demo_database')
         ->set('cacheName', 'demo')
         ->set('options', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING))
         ->cleanCache();

$database->exec('
CREATE TABLE IF NOT EXISTS `demo_table` (
  `field` int(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
');
$database->exec('DELETE FROM demo_table');
echo ' Done.<br />';

echo '/********************************************************************/<br />';
echo '*                       Insert data                                  *<br />';
echo '/********************************************************************/<br />';
$database->prepare('INSERT into demo_table VALUES(?)');
$time = microtime(true);
for($i=0; $i<$nbDatas; $i++)
	$database->execute(array($i));
echo ' Done. '.$nbDatas.' datas inserted in '.(microtime(true) - $time).'s.<br />';

echo '/********************************************************************/<br />';
echo '*                       Fetch data                                   *<br />';
echo '/********************************************************************/<br />';
echo 'RAM = datas are fetched from the object.<br />';
echo 'Database = datas are fetched from the database.<br />';
echo 'Cache = datas are fetched from Mysqlnd_qc cache or from cutom file cache.<br /><br />';
echo ' [10 fetch with no cache]<br />';
$rows = $database->query('SELECT * FROM demo_table')
	             ->fetchAll(); // First fetch to cache the datas
for($i=0; $i<10; $i++)
{
	$time = microtime(true);
	$rows = $database->query('SELECT * FROM demo_table')
	                 ->fetchAll();
	echo 'Normal query, retrieving from Database : '.count($rows).' datas fetched in '.round((microtime(true) - $time)*100,3).' ms<br />';
	$database->cleanCache();
}
echo '<br />';
echo ' [10 fetch from object RAM]<br />';
for($i=0; $i<10; $i++)
{
	$time = microtime(true);
	$rows = $database->query('SELECT * FROM demo_table')
	                 ->fetchAll();
	echo 'No query, retrieved from RAM : '.count($rows).' datas fetched in '.round((microtime(true) - $time)*100,3).' ms<br />';
}
echo '<br />';
echo ' [10 fetch from cache]<br />';
for($i=0; $i<10; $i++)
{
	unset($database);
	$database = new Database();
	$database->set('server', '127.0.0.1')
	         ->set('username', 'root')
	         ->set('database', 'demo_database')
	         ->set('cacheName', 'demo')
	         ->set('options', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING));
	$time = microtime(true);
	$rows = $database->query('SELECT * FROM demo_table')
	                 ->fetchAll();
	echo 'Retrieved from cache : '.count($rows).' datas fetched in '.round((microtime(true) - $time)*100,3).' ms<br />';
}