# _mysqlDiff

This class is a revision of https://github.com/caviola/mysqldiff

mysqldiff is a PHP script that compares the schema of two MySQL databases 
and produces a sequence of MySQL statements to "alter" the second schema 
to match the first one.

## USAGE:

* Copy the class in your code
* Declare new mysqlDiff()
* Invoke the function analyzeDiff($options);

### Example:
```
$test = new _mysqlDiff();
$test->analyzeDiff($options);
```

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


## OPTIONS STRUCTURE:
```
 $options = (object) array(
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
```
