# _mysqlDiff

This class is a revision of https://github.com/caviola/mysqldiff
With the integration of:
* SQLparser => https://github.com/iamcal/SQLParser
* sql-import => https://github.com/daveismyname/sql-import

mysqldiff is a PHP script that compares the schema of two MySQL databases 
and produces a sequence of MySQL statements to "alter" the second schema 
to match the first one.

_**In case of create tables statements with fk, the order of creation table is auto-reorder to match correctly the table's creation order.**_

## BASIC USAGE:

* Create a directory with the 3 files: mysqldiff.php, sqlparser.php, sqlimport.php
* require mysqldiff.php (the sqlparser class is already required inside the mysqldiff.php)
* Declare new mysqlDiff()
* Invoke the function analyzeDiff($options);

### Example:
```
require_once 'yourpath/mysqlDiff/mysqldiff.php';
$test = new _mysqlDiff();
$test->analyzeDiff($options);
```

## OPTIONS STRUCTURE:
```
 $options = (object) array(
              'reset_auto_increment' => TRUE,
              'drop_columns' => TRUE,
              'drop_tables' => TRUE,
              'db1' => (object) array(
                  'host' => 'localhost',
                  'pwd' => 'userPassword',
                  'user' => 'userDatabase',
                  'database' => 'databaseName',
                  'schema' => NULL,
              ),
              'db2' => (object) array(
                  'host' => 'localhost',
                  'pwd' => 'userPassword',
                  'user' => 'userDatabase',
                  'database' => 'databaseName',
                  'schema' => NULL
              ),
              'output_dir' => 'serverDirOutputPath',
              'output_file' => 'outputFileName',
              'overwrite' => TRUE,
              'ofh' => fopen('serverDirOutputPath + outputFileName', 'w'), // output file handle
  );
  
## OPTION'S EXPLANATION:
[*] reset_auto_increment =>     If reset_auto_increment options is TRUE the class 
                                will reset the auto_increment to 1 of all new tables 
                                created (without change the auto_increment of all 
                                other tables into the database)
                                
[*]drop-tables =>               Whether to generate DROP TABLE statements
                                for tables present in destination but not
                                on source database.
                                Note this can happen when you simply rename
                                a table. Default is NOT TO DROP.
                                
[*]drop-columns =>              Whether to generate ALTER TABLE...DROP COLUMN
                                statements for columns present in destination
                                but not on source database.
                                Note this can happen when you simply rename
                                a column. Default is NOT TO DROP.


### if you want print the result (the result will show the options parsed and the sql builded):
```
echo '<pre>';
print_r($test);
echo '</pre>';
```

### if you want store the sql result in a variable to use:
```
$sql = $test->sql;
```

## EXAMPLE SQL OUTPUT:

```
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';
USE `TEST_2`;

ALTER TABLE `company` ADD COLUMN `test` int(11) NOT NULL auto_increment AFTER `company_id`;
ALTER TABLE `prova1` DROP COLUMN `prova_name`;
CREATE UNIQUE INDEX test_key ON `company` (company_id,test);
CREATE INDEX test ON `company` (test);
CREATE INDEX test_2 ON `company` (test);
SET FOREIGN_KEY_CHECKS = 1;
```
## APPLY SQL FILE:
```
require_once 'yourpath/mysqlDiff/sqlimport.php';

use Daveismyname\SqlImport\Import;

$filename = 'FULLFILEPATH';
$username = 'db_user';
$password = 'db_passowrd';
$database = 'db_name';
$host = 'db_host';
$dropTables = false;
$forceDropTables = false;
new Import($filename, $username, $password, $database, $host, $dropTables,$forceDropTables);
```
## APPLY SQL FILE OPTION'S EXPLANATION:

* dropTables => when set to true will delete all the tables in the database before import the sql file.
* forceDropTables => force the deletion of all the tables
