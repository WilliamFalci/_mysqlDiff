<?
require_once 'sqlparser.php';
use iamcal\SQLParser;

$options = '';

class _mysqlDiff {
  var $sql;
  var $options;
  var $creation_tables = [];
  
  function __costruct(){
    $this->options ='';
    
    return $this;
  }
  
  function analyzeDiff($arrOptions){
    global $options;
        
    $options = $arrOptions;
    $this->options = $arrOptions;
    
    date_default_timezone_set('Europe/Zurich');

    $db1 = &$options->db1;
    $db2 = &$options->db2;

    $db1->database = &$options->db1->database;
    $db2->database = &$options->db2->database;
    $db1->user =  &$options->db1->user;
    $db2->user =  &$options->db1->user;
    $db1->pwd =  &$options->db1->pwd;
    $db2->pwd =  &$options->db1->pwd;

    if (!$db1->database && !$db1->schema)
        $this->error("source database or schema file must be specified with --schema-file1, --database1 or --database");

    if ($db1->schema) {
        if (!file_exists($db1->schema))
            $this->error("schema file 1 does not exist");
        $db1->database = "tmp_schema_" . uniqid();
    }

    if (!$db2->database && !$db2->schema)
        $this->error("destination database or schema file must be specified with --schema-file2, --database2 or --database");

    if ($db2->schema) {
        if (!file_exists($db1->schema))
            $this->error("schema file 2 does not exist");
        $db2->database = "tmp_schema_" . uniqid();
    }

    if ($db1->host == $db2->host && $db1->database == $db2->database && !$db1->schema && !$db2->schema)
        $this->error("databases names must be different if they reside on the same host");


    $options->ofh = @fopen($options->output_dir . $options->output_file, 'w') or $this->error("error creating output file $options->output_file");

    $db1->link = mysqli_connect($db1->host, $db1->user, $db1->pwd) or $this->error('Connection 1 failed');
    $this->create_schema_db($db1);   
    mysqli_select_db($db1->link, $db1->database) or $this->error(mysqli_error($db1->link));

    $db2->link = mysqli_connect($db2->host, $db2->user, $db2->pwd) or $this->error('Connection 2 failed');
    $this->create_schema_db($db2);
    mysqli_select_db($db2->link, $db2->database) or $this->error(mysqli_error($db2->link));

    $this->load_schema_db($db1);
    $this->load_schema_db($db2);

    $this->populate_schemata_info($db1);
    $this->populate_schemata_info($db2);

    $this->process_database($db1, $db2);
    $this->process_tables($db1, $db2);

    $this->drop_schema_db($db1);
    $this->drop_schema_db($db2);

    $this->sql= file_get_contents( $options->output_dir . $options->output_file );
    return $this;
  }
  
  function drop_schema_db($db)
  {
      if (!$db->schema)
          return;
      mysqli_query($db->link, "drop database {$db->database}");
  }

  function create_schema_db($db)
  {
      if (!$db->schema)
          return;
      if (!mysqli_query($db->link, "create database {$db->database}"))
          $this->error('Error of create database ' . mysqli_error($db->link));
  }

  function load_schema_db(&$db)
  {
      if (!$db->schema)
          return;
      $sql = explode(";", file_get_contents($db->schema));
      foreach ($sql as $q) {
          if (!trim($q))
              continue;
          if (preg_match('/^\s*\/\*.*\*\/\s*$/', $q))
              continue;
          if (preg_match('/^\s*drop /i', $q))
              continue;
          if (!mysqli_query($db->link, $q))
              $this->error("Error in load schema db '$q'" . mysqli_error($db->link));
      }
  }

  function populate_schemata_info(&$db)
  {
      if (!($result = mysqli_query($db->link, "select * from information_schema.schemata where schema_name='$db->database'")))
          return FALSE;
      if ($info = mysqli_fetch_object($result)) {
          $db->charset = $info->DEFAULT_CHARACTER_SET_NAME;
          $db->collation = $info->DEFAULT_COLLATION_NAME;
      }
  }

  function list_tables($db)
  {
      if (!($result = mysqli_query($db->link, "select TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT, CHECKSUM, TABLE_COMMENT from information_schema.tables where table_schema='$db->database'")))
          return FALSE;
      $tables = array();
      while ($row = mysqli_fetch_object($result)) {
          $tables[$row->TABLE_NAME] = $row;
      }
      return $tables;
  }

  function list_columns($table, $db)
  {
      // Note the columns are returned in ORDINAL_POSITION ascending order.
      if (!($result = mysqli_query($db->link, "select * from information_schema.columns where table_schema='$db->database' and table_name='$table' order by ordinal_position")))
          return FALSE;

      $columns = array();
      while ($row = mysqli_fetch_object($result)) {
          $columns[$row->COLUMN_NAME] = $row;
      }
      return $columns;
  }

  function list_indexes($table, $db)
  {
      if (!($result = mysqli_query($db->link, "show indexes from `$table`")))
          return FALSE;

      $indexes = array();
      $prev_key_name = NULL;
      while ($row = mysqli_fetch_object($result)) {
          // Get the information about the index column.
          $index_column = (object) array(
                      'sub_part' => $row->Sub_part,
                      'seq' => $row->Seq_in_index,
                      'type' => $row->Index_type,
                      'collation' => $row->Collation,
                      'comment' => $row->Comment,
          );
          if ($row->Key_name != $prev_key_name) {
              // Add a new index to the list.
              $indexes[$row->Key_name] = (object) array(
                          'key_name' => $row->Key_name,
                          'table' => $row->Table,
                          'non_unique' => $row->Non_unique,
                          'columns' => array($row->Column_name => $index_column)
              );
              $prev_key_name = $row->Key_name;
          } else {
              // Add a new column to an existing index.
              $indexes[$row->Key_name]->columns[$row->Column_name] = $index_column;
          }
      }

      return $indexes;
  }

  function get_create_table_sql($name, $db)
  {
      if (!($result = mysqli_query($db->link, "show create table `$name`")))
          return FALSE;

      $row = mysqli_fetch_row($result);
      return $row[1];
  }

  function create_tables($db1, $tables1, $tables2)
  {
      global $options;

      $sql = '';
      $table_names = array_diff(array_keys($tables1), array_keys($tables2));
      foreach ($table_names as $t) {
          $sql .= $this->get_create_table_sql($t, $db1) . ";\n\n";
      }
      
      array_push($this->creation_tables,$sql);
      //fputs($options->ofh, $sql);
  }

  function format_default_value($value, $db)
  {
      if (strcasecmp($value, 'CURRENT_TIMESTAMP') == 0)
          return $value;
      elseif (is_string($value))
          return "'" . mysqli_real_escape_string($db->link, $value) . "'";
      else
          return $value;
  }

  function drop_tables($tables1, $tables2)
  {
      global $options;

      $sql = '';
      $table_names = array_diff(array_keys($tables2), array_keys($tables1));
      foreach ($table_names as $t) {
          $sql .= "DROP TABLE `$t`;\n";
      }

      if ($sql)
          $sql .= "\n";

      fputs($options->ofh, $sql);
  }

  function build_column_definition_sql($column, $db)
  {
      $result = $column->COLUMN_TYPE;

      if ($column->COLLATION_NAME)
          $result .= " COLLATE '$column->COLLATION_NAME'";

      $result .= strcasecmp($column->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';

      if (isset($column->COLUMN_DEFAULT))
          $result .= ' DEFAULT ' . format_default_value($column->COLUMN_DEFAULT, $db);

      if ($column->EXTRA)
          $result .= " $column->EXTRA";

      if ($column->COLUMN_COMMENT)
          $result .= " COMMENT '" . mysqli_real_escape_string($db->link, $column->COLUMN_COMMENT) . "'";

      return $result;
  }

  function alter_table_add_column($column, $after_column, $table, $db)
  {
      global $options;

      $sql = "ALTER TABLE `$table` ADD COLUMN `$column->COLUMN_NAME` " .
              $this->build_column_definition_sql($column, $db) .
              ($after_column ? " AFTER `$after_column`" : ' FIRST') .
              ";\n";

      fputs($options->ofh, $sql);
  }

  function alter_table_modify_column($column1, $column2, $after_column, $table, $db)
  {
      global $options;

      $modify = array();

      if ($column1->COLUMN_TYPE != $column2->COLUMN_TYPE)
          $modify['type'] = " $column1->COLUMN_TYPE";

      if ($column1->COLLATION_NAME != $column2->COLLATION_NAME)
          $modify['collation'] = " COLLATE $column1->COLLATION_NAME";

      if ($column1->IS_NULLABLE != $column2->IS_NULLABLE)
          $modify['null'] = strcasecmp($column1->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';

      if ($column1->COLUMN_DEFAULT != $column2->COLUMN_DEFAULT) {
          // FALSE is an special value that indicates we should DROP this column's default value,
          // causing MySQL to assign it the "default default".
          $modify['default'] = isset($column1->COLUMN_DEFAULT) ? ' DEFAULT ' . format_default_value($column1->COLUMN_DEFAULT, $db) : FALSE;
      }

      if ($column1->EXTRA != $column2->EXTRA)
          $modify['extra'] = " $column1->EXTRA";

      if ($column1->COLUMN_COMMENT != $column2->COLUMN_COMMENT)
          $modify['comment'] = " COMMENT '$column1->COLUMN_COMMENT'";

      if ($column1->ORDINAL_POSITION != $column2->ORDINAL_POSITION)
          $modify['position'] = $after_column ? " AFTER `$after_column`" : ' FIRST';

      if ($modify) {
          $sql = "ALTER TABLE `$table` MODIFY `$column1->COLUMN_NAME`";

          $sql .= isset($modify['type']) ? $modify['type'] : " $column2->COLUMN_TYPE";

          if (isset($modify['collation']))
              $sql .= $modify['collation'];

          if (isset($modify['null']))
              $sql .= $modify['null'];
          else
              $sql .= strcasecmp($column2->IS_NULLABLE, 'NO') == 0 ? ' NOT NULL' : ' NULL';

          if (isset($modify['default']) && $modify['default'] !== FALSE) {
              $sql .= $modify['default'];
          } elseif (isset($column2->COLUMN_DEFAULT))
              $sql .= ' DEFAULT ' . format_default_value($column2->COLUMN_DEFAULT, $db);

          if (isset($modify['extra']))
              $sql .= $modify['extra'];
          elseif ($column2->EXTRA != '')
              $sql .= " $column2->EXTRA";

          if (isset($modify['comment']))
              $sql .= $modify['comment'];
          elseif ($column2->COLUMN_COMMENT != '')
              $sql .= " COMMENT '$column2->COLUMN_COMMENT'";

          if (isset($modify['position']))
              $sql .= $modify['position'];

          $sql .= ";\n";

          fputs($options->ofh, $sql);
      }
  }

  function alter_table_drop_columns($columns1, $columns2, $table)
  {
      global $options;

      $sql = '';
      $columns = array_diff_key($columns2, $columns1);
      foreach ($columns as $c) {
          $sql .= "ALTER TABLE `$table` DROP COLUMN `$c->COLUMN_NAME`;\n";
      }

      fputs($options->ofh, $sql);
  }

  function alter_tables_columns($db1, $db2)
  {
      global $options;

      $tables1 = $this->list_tables($db1);
      $tables2 = $this->list_tables($db2);

      $tables = array_intersect(array_keys($tables1), array_keys($tables2));
      foreach ($tables as $t) {
          $columns1 = $this->list_columns($t, $db1);
          $columns2 = $this->list_columns($t, $db2);
          $columns_index = array_keys($columns1);

          foreach ($columns1 as $c1) {
              $after_column = $c1->ORDINAL_POSITION == 1 ? NULL : $columns_index[$c1->ORDINAL_POSITION - 2];

              if (!isset($columns2[$c1->COLUMN_NAME]))
                  $this->alter_table_add_column($c1, $after_column, $t, $db2);
              else
                  $this->alter_table_modify_column($c1, $columns2[$c1->COLUMN_NAME], $after_column, $t, $db2);
          }

          if ($options->drop_columns)
              $this->alter_table_drop_columns($columns1, $columns2, $t);
      }
  }

  function alter_tables($tables1, $tables2)
  {
      global $options;

      $sql = '';
      $table_names = array_intersect(array_keys($tables2), array_keys($tables1));
      foreach ($table_names as $t) {
          $t1 = $tables1[$t];
          $t2 = $tables2[$t];

          if ($t1->ENGINE != $t2->ENGINE)
              $sql .= "ALTER TABLE `$t` ENGINE=$t1->ENGINE;\n";

          if ($t1->TABLE_COLLATION != $t2->TABLE_COLLATION)
              $sql .= "ALTER TABLE `$t` COLLATE=$t1->TABLE_COLLATION;\n";

          if ($t1->ROW_FORMAT != $t2->ROW_FORMAT)
              $sql .= "ALTER TABLE `$t` ROW_FORMAT=$t1->ROW_FORMAT;\n";

          if ($t1->CHECKSUM != $t2->CHECKSUM)
              $sql .= "ALTER TABLE `$t` CHECKSUM=$t1->CHECKSUM;\n";

           if ($t1->TABLE_COMMENT != $t2->TABLE_COMMENT)
            $sql .= "ALTER TABLE `$t` COMMENT='$t1->TABLE_COMMENT';\n";

          if ($sql)
              $sql .= "\n";
      }

      fputs($options->ofh, $sql);
  }

  function are_indexes_eq($index1, $index2)
  {
      if ($index1->non_unique != $index2->non_unique)
          return FALSE;
      if (count($index1->columns) != count($index2->columns))
          return FALSE;

      if (empty($index1->columns)) {
          return false;
      }
      foreach ((array) $index1->columns as $name => $column1) {
          if (!isset($index2->columns[$name]))
              return FALSE;
          if ($column1->seq != $index2->columns[$name]->seq)
              return FALSE;
          if ($column1->sub_part != $index2->columns[$name]->sub_part)
              return FALSE;
          if ($column1->type != $index2->columns[$name]->type)
              return FALSE;
          /* if ($column1->collation != $index2->columns[$name]->collation)
            return FALSE; */
      }

      return TRUE;
  }

  function build_drop_index_sql($index)
  {
      return $index->key_name == 'PRIMARY' ?
              "ALTER TABLE `$index->table` DROP PRIMARY KEY;" :
              "ALTER TABLE `$index->table` DROP INDEX $index->key_name;";
  }

  function build_create_index_sql($index)
  {
      $column_list = array();
      foreach ($index->columns as $name => $column) {
          $column_list[] = $name . ($column->sub_part ? "($column->sub_part)" : '');
      }
      $column_list = '(' . implode(',', $column_list) . ')';

      if ($index->key_name == 'PRIMARY')
          $result = "ALTER TABLE `$index->table` ADD PRIMARY KEY $column_list;";
      else {
          if (isset($index->type) && $index->type == 'FULLTEXT')
              $index_type = ' FULLTEXT';
          elseif (!$index->non_unique)
              $index_type = ' UNIQUE';
          else
              $index_type = '';

          $result = "CREATE$index_type INDEX $index->key_name ON `$index->table` $column_list;";
      }

      return $result;
  }

  function alter_table_add_indexes($idx1, $idx2)
  {
      global $options;

      $indexes = array_diff_key($idx1, $idx2);
      $sql = '';
      foreach ($indexes as $index_name => $index)
          $sql .= $this->build_create_index_sql($index) . "\n";

      fputs($options->ofh, $sql);
  }

  function alter_table_drop_indexes($idx1, $idx2)
  {
      global $options;

      $indexes = array_diff_key($idx2, $idx1);
      $sql = '';
      foreach ($indexes as $index_name => $index)
          $sql .= $this->build_drop_index_sql($index) . "\n";

      fputs($options->ofh, $sql);
  }

  function alter_table_alter_indexes($idx1, $idx2)
  {
      global $options;

      $sql = '';
      $indexes = (array) array_intersect_key((array) $idx1, (array) $idx2);
      foreach ($indexes as $index_name => $index)
          if (!$this->are_indexes_eq($index, $idx2[$index_name])) {
              $sql .= $this->build_drop_index_sql($idx2[$index_name]) . "\n";
              $sql .= $this->build_create_index_sql($index) . "\n";
          }

      fputs($options->ofh, $sql);
  }

  function process_database($db1, $db2)
  {
      global $options;

      $sql = "SET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = '';\n";

      if (!$db2->schema)
          $sql .= "USE `$db2->database`;\n";

      if ($db1->charset != $db2->charset)
          $sql .= "ALTER DATABASE `$db2->database` CHARACTER SET=$db1->charset;\n";

      if ($db1->collation != $db2->collation)
          $sql .= "ALTER DATABASE `$db2->database` COLLATE=$db1->collation;\n";

      $sql .= "\n";

      fputs($options->ofh, $sql);
  }

  function process_indexes($tables1, $tables2, $db1, $db2)
  {
      $tables = array_intersect_key((array) $tables1, (array) $tables2);
      foreach (array_keys((array) $tables) as $t) {
          $idx1 = $this->list_indexes($t, $db1);
          $idx2 = $this->list_indexes($t, $db2);

          $this->alter_table_drop_indexes($idx1, $idx2);
          $this->alter_table_add_indexes($idx1, $idx2);
          $this->alter_table_alter_indexes($idx1, $idx2);
      }
  }

  function process_tables($db1, $db2)
  {
      global $options;

      $tables1 = $this->list_tables($db1);
      $tables2 = $this->list_tables($db2);

      $this->create_tables($db1, $tables1, $tables2);
    
      $sql= '';
      
    
      if(is_array($this->creation_tables) && count($this->creation_tables) > 0){
        $parser = new SQLParser();
        $parser->parse($this->creation_tables[0]);

        $orderedSQL = $parser->tables;

        foreach($parser->tables as $table_name=>$statements){
          if(isset($parser->tables[$table_name]['indexes'])){

            foreach($parser->tables[$table_name]['indexes'] as $index){
              if($index['type'] == 'FOREIGN'){
                $orderedSQL = array_swap($table_name,$index['ref_table'], $orderedSQL);
              }
            }
          }
        }

        foreach($orderedSQL as $create){
          $sql.= $create['sql'] . "\n";
        }

        fputs($options->ofh, $sql);
      }

      if ($options->drop_tables)
          $this->drop_tables($tables1, $tables2);

      $this->alter_tables($tables1, $tables2);
      $this->alter_tables_columns($db1, $db2);

      $this->process_indexes($tables1, $tables2, $db1, $db2);

      $sql= "SET FOREIGN_KEY_CHECKS = 1;";
      fputs($options->ofh, $sql);
  }

  function error($msg)
  {
      echo "mysqldiff: $msg\n";
  }

  function prompt($msg)
  {
      echo $msg;
  }
}
?>
