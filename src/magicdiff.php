<?php
namespace vielhuber\magicdiff;
class magicdiff
{
    // column information
    public static $column_information = [];

    // this helper variable simulates all column/value changes done by alter statements
    public static $magic_modifier = [];

    public static function setup() {
    	if( !is_dir(magicdiff::path()) ) { mkdir(magicdiff::path()); echo 'created folder /.magicdiff/...'.PHP_EOL; }
    	else { echo 'folder /.magicdiff/ already present...'.PHP_EOL; }
    	if( !file_exists(magicdiff::path().'/config.json') ) { file_put_contents( magicdiff::path().'/config.json', magicdiff::get_boilerplate_config() ); echo 'file /.magicdiff/config.json created. now edit config.json...'.PHP_EOL; }
		else { echo 'file /.magicdiff/config.json already exists...'.PHP_EOL; }
    }

    public static function path() {
        return getcwd().'/.magicdiff/';
	}

	public static function get_boilerplate_config() {
		return '{
	"engine": "mysql",
	"database": {
		"host": "localhost",
		"port": "3306",
		"database": "_test1",
		"username": "root",
		"password": "root",
		"export": "C:\\MAMP\\bin\\mysql\\bin\\mysqldump.exe"
	},
	"ignore": [
		"s_table1",
		"s_table2",
		"s_table3"
	]'.PHP_EOL.'}';
	}

    public static function init() {
        magicdiff::check_setup();
        magicdiff::export('reference', true);	
    }

    public static function check_setup() {
        if( !is_dir(magicdiff::path()) || !file_exists(magicdiff::path().'/config.json') ) { echo 'do setup first...'.PHP_EOL; die(); }
    }

    public static function export($filename, $with_ignored = true) {
        $tables = magicdiff::get_tables();
        if(!empty($tables)) {
			foreach($tables as $tables__value) {
				magicdiff::command(magicdiff::conf('database.export').' --no-data --skip-add-drop-table --skip-add-locks --skip-comments --extended-insert=false --disable-keys --quick -h '.magicdiff::conf('database.host').' --port '.magicdiff::conf('database.port').' -u '.magicdiff::conf('database.username').' -p"'.magicdiff::conf('database.password').'" '.magicdiff::conf('database.database').' '.$tables__value.' > '.magicdiff::path().'/_'.$filename.'_'.$tables__value.'_schema.sql');
                magicdiff::command(magicdiff::conf('database.export').' --no-create-info --skip-add-locks --skip-comments --extended-insert=false --disable-keys --quick -h '.magicdiff::conf('database.host').' --port '.magicdiff::conf('database.port').' -u '.magicdiff::conf('database.username').' -p"'.magicdiff::conf('database.password').'" '.magicdiff::conf('database.database').' '.$tables__value.' > '.magicdiff::path().'/_'.$filename.'_'.$tables__value.'_data.sql');
			}
		}
    }

    public static function get_tables($with_ignored = true) {
        $tables = [];
        $ignored = magicdiff::conf('ignored');
        $db = magicdiff::sql();
        $statement = $db->prepare('SHOW TABLES');
        $statement->execute();
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);
        if(!empty($data)) {
            foreach($data as $data__value) {
                $table = $data__value[ array_keys($data__value)[0] ];
                if( $with_ignored === false && in_array($table,$ignored) ) { continue; }
                $tables[] = $table;
            }
        }
        return $tables;
    }

	public static function conf($path) {
		$config = file_get_contents(magicdiff::path().'/config.json');
		// if kiwi config is available, chose this configuration instead
		if( file_exists(magicdiff::path().'/../.kiwi/config.json') ) {
			$config = file_get_contents(magicdiff::path().'/../.kiwi/config.json');
		}
		$config = json_decode($config);
		if( json_last_error() != JSON_ERROR_NONE ) { die('corrupt config file.'); }
		$config = json_decode(json_encode($config),true);
        $keys = explode('.', $path);
        foreach($keys as $key) {
	        if(isset($config[$key])) {
	            $config = $config[$key];
	        }
	        else {
	            return null;
	        }
        }
        return $config;       
	}

    public static function sql() {
        return new PDO('mysql:host=' . magicdiff::conf('database.host') . ';port=' . magicdiff::conf('database.port') . ';dbname=' . magicdiff::conf('database.database'), magicdiff::conf('database.username'), magicdiff::conf('database.password'), array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING
        ));
    }

	public static function command($command, $verbose = false) {
		if( $verbose === false ) {
			if( strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ) {
				$command .= ' 2> nul';
			}
			else {
				$command .= ' 2>/dev/null';
			}
		}
		$return = shell_exec($command);
		return $return;
	}

    public static function diff() {
        magicdiff::check_setup();
        $diff = [];
        magicdiff::export('current', false);
        $tables = magicdiff::get_tables();
        foreach($tables as $tables__value) {
            $diff_this = magicdiff::diff_table($tables__value);
            if($diff_this['diff']['all'] !== null && $diff_this['diff']['all'] != '') {
                $diff[] = $diff_this;
            }  
        }
        return $diff;
    }

    // main function to generate diff
    public static function diff_table($table) {

        // this is the return value; it consists of the following data
        $diff = [
            'table' => $table,
            'patch' => [
                'all' => null,
                'schema' => null,
                'data' => null
            ],
            'diff' => [
                'all' => null,
                'schema' => null,
                'data' => null
            ]
        ];

        // if files do not exist, create empty ones
        foreach(['_reference_'.$table.'_schema','_reference_'.$table.'_data','_current_'.$table.'_schema','_current_'.$table.'_data'] as $file) {
            if( !file_exists(magicdiff::path().'/'.$file.'.sql') ) {
                touch(magicdiff::path().'/'.$file.'.sql');
            }
        }

        // format: one command per line in schema files (in data files this fortunately is not needed)
        $delimiter = '#################';
        foreach([magicdiff::path().'/_reference_'.$table.'_schema.sql',magicdiff::path().'/_current_'.$table.'_schema.sql'] as $file_line) {
            file_put_contents($file_line,str_replace("\r\n",$delimiter,file_get_contents($file_line)));
            file_put_contents($file_line,str_replace("\r",$delimiter,file_get_contents($file_line)));       
            file_put_contents($file_line,str_replace("\n",$delimiter,file_get_contents($file_line)));
            file_put_contents($file_line,str_replace(";".$delimiter,";\n",file_get_contents($file_line)));
            file_put_contents($file_line,str_replace($delimiter,"",file_get_contents($file_line)));
        }

        // fetch all insert statements of second schema to get column names (for later statements)
        foreach(explode("\n",file_get_contents(magicdiff::path().'/_current_'.$table.'_schema.sql')) as $create_statement) {
            $parse_line = self::parse_line($create_statement);
            if( $parse_line === null || $parse_line["type"] != "create" ) { continue; }
            self::$column_information[$parse_line['table']] = $parse_line;
        }

        // compare files with diff
        passthru('diff --speed-large-files --suppress-common-lines '.magicdiff::path().'/_reference_'.$table.'_schema.sql '.magicdiff::path().'/_current_'.$table.'_schema.sql > '.magicdiff::path().'/diff_schema.result');
        passthru('diff --speed-large-files --suppress-common-lines '.magicdiff::path().'/_reference_'.$table.'_data.sql '.magicdiff::path().'/_current_'.$table.'_data.sql > '.magicdiff::path().'/diff_data.result');

        // save patch files
        $diff['patch']['schema'] = file_get_contents(magicdiff::path().'/diff_schema.result');
        $diff['patch']['data'] = file_get_contents(magicdiff::path().'/diff_data.result');

        // delete tmp files
        unlink(magicdiff::path().'/diff_schema.result');
        unlink(magicdiff::path().'/diff_data.result');

        // immediately return if empty
        if($diff['patch']['schema'] == '' && $diff['patch']['data'] == '') { return $diff; }

        // get results
        $diff['patch']['all'] = $diff['patch']['schema']."\n".$diff['patch']['data'];

        // fix line endings
        $diff['patch']['all'] = str_replace("\r\n","\n",$diff['patch']['all']);

        // split diff in new lines (don't use PHP_EOL, because CRLF fails)
        $diff['patch']['all'] = explode("\n",$diff['patch']['all']);

        // split by added/deleted lines
        $diff_added = [];
        $diff_deleted = [];
        foreach($diff['patch']['all'] as $diff__value) {

            // don't include empty lines
            if($diff__value == "" || trim($diff__value) == "") { continue; }

            // don't include meta lines
            if( strpos($diff__value, '> ') !== 0 && strpos($diff__value, '< ') !== 0 ) { continue; }

            // prepare parsed lines (with hash so we can later remove duplicates)
            $parse_line_raw = substr($diff__value,2);
            $parse_line_hash = md5($parse_line_raw);
            $parse_line = self::parse_line($parse_line_raw);

            // don't include unrecognized lines
            if($parse_line === null) { continue; }

            // don't include drop tables
            if( $parse_line["type"] == "drop" ) { continue; }

            // it could be the case, that diff (despite suppress common lines) deletes and inserts the same line. this is sorted out here
            if( strpos($diff__value, '> ') === 0 && array_key_exists($parse_line_hash,$diff_deleted) ) {
                unset($diff_deleted[$parse_line_hash]);
                continue;
            } 

            // save in appropiate array
            if( strpos($diff__value, '> ') === 0 ) { $diff_added[$parse_line_hash] = $parse_line; }
            else { $diff_deleted[$parse_line_hash] = $parse_line; }

        }

        // fetch final diff statements
        $statements = [];

        // alter table
        $statements_alter_deleted = [];
        $statements_alter_added = [];
        foreach($diff_deleted as $diff_deleted__key=>$diff_deleted__value) {
        foreach($diff_added as $diff_added__key=>$diff_added__value) {
            if(
                $diff_deleted__value["type"] == "create" &&
                $diff_added__value["type"] == "create" &&
                $diff_deleted__value["table"] == $diff_added__value["table"]
            ) {

                // find all columns that have been deleted
                while(1==1) {
                    $finish = true;
                    foreach(self::magic_modifier($diff_deleted__value['table'],$diff_deleted__value["columns"],'columns') as $diff_deleted_columns__key=>$diff_deleted_columns__value) {
                        $exists = false;
                        foreach($diff_added__value["columns"] as $diff_added_columns__key=>$diff_added_columns__value) {
                            if(
                                $diff_deleted_columns__value["name"] == $diff_added_columns__value["name"] &&
                                $diff_deleted_columns__value["args"] == $diff_added_columns__value["args"]
                            ) {
                                $exists = true;
                            }
                        }
                        if($exists === false) {
                            $statements[] = [
                                    "type" => "alter",
                                    "table" => $diff_added__value["table"],
                                    "query" => 'DROP COLUMN '.$diff_deleted_columns__value["name"].''
                            ];
                            self::$magic_modifier[] = [$diff_deleted__value['table'],'delete',$diff_deleted_columns__key];
                            $finish = false;
                            // begin again
                            break;
                        }
                    }
                    if( $finish === true ) { break; }
                }

                // find all columns that have been added
                while(1==1) {
                    $finish = true;
                    foreach($diff_added__value['columns'] as $diff_added_columns__key=>$diff_added_columns__value) {
                        $exists = false;
                        foreach(self::magic_modifier($diff_deleted__value['table'],$diff_deleted__value["columns"],'columns') as $diff_deleted_columns__key=>$diff_deleted_columns__value) {
                            if(
                                $diff_deleted_columns__value["name"] == $diff_added_columns__value["name"] &&
                                $diff_deleted_columns__value["args"] == $diff_added_columns__value["args"]
                            ) {
                                $exists = true;
                            }
                        }
                        if($exists === false) {
                            $statements[] = [
                                    "type" => "alter",
                                    "table" => $diff_added__value["table"],
                                    "query" => 'ADD '.$diff_added_columns__value["name"].' '.$diff_added_columns__value["args"].''
                            ];
                            self::$magic_modifier[] = [$diff_deleted__value['table'],'insert',$diff_added_columns__value["name"],$diff_added_columns__value["args"]];
                            $finish = false;
                            break;
                        }
                    }
                    if( $finish === true ) { break; }
                }

                // finally reorder all columns
                while(1==1) {
                    $finish = true;
                    $diff_deleted__value_columns = self::magic_modifier($diff_deleted__value['table'],$diff_deleted__value["columns"],'columns');
                    foreach($diff_deleted__value_columns as $diff_deleted_columns__key=>$diff_deleted_columns__value) {
                    foreach($diff_added__value["columns"] as $diff_added_columns__key=>$diff_added_columns__value) {
                        if(
                            $diff_deleted_columns__key != $diff_added_columns__key &&
                            $diff_deleted_columns__value["name"] == $diff_added_columns__value["name"] &&
                            $diff_deleted_columns__value["args"] == $diff_added_columns__value["args"]
                        ) {
                            $statements[] = [
                                "type" => "alter",
                                "table" => $diff_added__value["table"],
                                "query" => 'CHANGE COLUMN '.$diff_deleted_columns__value["name"].' '.$diff_deleted_columns__value["name"].' '.$diff_deleted_columns__value["args"].' AFTER '.$diff_deleted__value_columns[$diff_added_columns__key]["name"].''
                            ];
                            self::$magic_modifier[] = [$diff_deleted__value['table'],'move',$diff_deleted_columns__key,$diff_added_columns__key];
                            $finish = false;
                            break 2;
                        }
                    }    
                    }
                    if( $finish === true ) { break; }
                }

                // save in these arrays so that later we do not reuse those lines again
                $statements_alter_deleted[] = $diff_deleted__key;
                $statements_alter_added[] = $diff_added__key;
            }
        }
        }

        // drop table
        $statements_tables_deleted = [];
        foreach($diff_deleted as $diff_deleted__key=>$diff_deleted__value) {
            if(
                $diff_deleted__value["type"] == "create" &&
                !in_array($diff_deleted__key,$statements_alter_deleted)
            ) {
                $statements[] = [
                    "type" => "drop",
                    "table" => $diff_deleted__value["table"],
                ];
                $statements_tables_deleted[] = $diff_deleted__value["table"];
            }
        }      

        // create table
        foreach($diff_added as $diff_added__key=>$diff_added__value) {
            if(
                $diff_added__value["type"] == "create" &&
                !in_array($diff_added__key,$statements_alter_added)
            ) {
                $statements_this = [
                    "type" => "create",
                    "table" => $diff_added__value["table"],
                    "columns" => $diff_added__value["columns"],
                    "meta" => $diff_added__value["meta"],
                ];
                if( isset($diff_added__value['primary_key']) && !empty($diff_added__value['primary_key']) ) {
                    $statements_this['primary_key'] = $diff_added__value['primary_key'];
                }
                $statements[] = $statements_this;                
            }
        }

        // update
        $statements_update_deleted = [];
        $statements_update_added = [];
        foreach($diff_deleted as $diff_deleted__key=>$diff_deleted__value) {
        foreach($diff_added as $diff_added__key=>$diff_added__value) {
            if(
                $diff_deleted__value["type"] == 'insert' &&
                $diff_added__value["type"] == 'insert' &&
                $diff_deleted__value["table"] == $diff_added__value["table"] &&
                self::get_table_primary_key($diff_deleted__value["table"]) !== null &&
                $diff_deleted__value["values"][self::get_table_primary_key($diff_deleted__value["table"])] == $diff_added__value["values"][self::get_table_primary_key($diff_deleted__value["table"])]
            ) {
                $statements[] = [
                    "type" => "update",
                    "table" => $diff_added__value["table"],
                    "values" => $diff_added__value["values"],
                    "where" => self::magic_modifier($diff_deleted__value["table"],$diff_deleted__value["values"],'values')
                ];
                $statements_update_deleted[] = $diff_deleted__key;
                $statements_update_added[] = $diff_added__key;
            }
        }
        }

        // delete
        foreach($diff_deleted as $diff_deleted__key=>$diff_deleted__value) {
            if(
                $diff_deleted__value["type"] == 'insert' &&
                !in_array($diff_deleted__key,$statements_update_deleted) &&
                !in_array($diff_deleted__value["table"],$statements_tables_deleted)
            ) {
                $statements[] = [
                    "type" => "delete",
                    "table" => $diff_deleted__value["table"],
                    "where" => self::magic_modifier($diff_deleted__value["table"],$diff_deleted__value["values"],'values')
                ];
            }
        }

        // insert
        foreach($diff_added as $diff_added__key=>$diff_added__value) {
            if(
                $diff_added__value["type"] == 'insert' &&
                !in_array($diff_added__key,$statements_update_added)
            ) {
                $statements[] = [
                    "type" => "insert",
                    "table" => $diff_added__value["table"],
                    "values" => $diff_added__value["values"]
                ];
            }
        }

        // create final statements
        foreach($statements as $statements__value) {
            $parsed_line = self::reparse_line($statements__value);
            if( in_array($statements__value['type'],['create','alter','drop']) ) {
                $diff['diff']['schema'] .= $parsed_line."\n";
            }
            if( in_array($statements__value['type'],['insert','update','delete']) ) {
                $diff['diff']['data'] .= $parsed_line."\n";
            }
            $diff['diff']['all'] .= $parsed_line."\n";
        }

        return $diff;

    }

    public static function magic_modifier($table, $data, $type) {
        if(empty(self::$magic_modifier)) { return $data; }
        foreach(self::$magic_modifier as $magic_modifier__value) {
            if($magic_modifier__value[0] != $table) { continue; }

            if($magic_modifier__value[1] == 'move') {
                $move_from = $magic_modifier__value[2];
                $move_to = $magic_modifier__value[3];
                //echo 'moving from '.$move_from.' to '.$move_to.''.PHP_EOL;
                array_splice($data, $move_to, 0, array_splice($data, $move_from, 1));
            }
            if($magic_modifier__value[1] == 'delete') {
                $delete_index = $magic_modifier__value[2];
                //echo 'deleting '.$delete_index.''.PHP_EOL;
                unset($data[$delete_index]);
                $data = array_values($data);
            }
            if($magic_modifier__value[1] == 'insert') {
                if($type == 'columns') {
                    $data[] = [
                        "name" => $magic_modifier__value[2],
                        "args" => $magic_modifier__value[3]
                    ];
                }
                if($type == 'values') {
                    //echo 'inserting '.PHP_EOL;
                    // here a pseudo value is added which is later filtered out
                    $data[] = '_DEFAULT_UNKNOWN_VALUE';
                }
            }
        
        }
        return $data;
    }


    public static function parse_line($line) {
        $line = trim($line);
        if(strpos($line,'INSERT INTO') === 0) {
            return self::parse_line_insert($line);
        }
        if(strpos($line,'CREATE TABLE') === 0) { 
            return self::parse_line_create($line);
        }
        return null;
    }

    public static function reparse_line($data) {

        $final_column_names = self::get_column_names($data["table"]);

        $line = "";
        if($data['type'] == 'alter') {
            $line .= 'ALTER TABLE ';
            $line .= $data['table'].' ';
            $line .= $data['query'];
            $line .= ';';
        }
        if($data["type"] == "insert") {
            $line .= "INSERT INTO ";
            $line .= "`".$data["table"]."` ";
            $line .= "(".implode(",",$final_column_names).") ";
            $line .= "VALUES(".implode(",",$data["values"]).")";
            $line .= ";";
        }
        if($data["type"] == "delete") {
            $line .= "DELETE FROM ";
            $line .= "`".$data["table"]."` ";
            $line .= "WHERE ";
            $line_where = [];
            foreach($data["where"] as $data_where__key=>$data_where__value) {
                // don't include columns with unknown default values
                if( $data_where__value == '_DEFAULT_UNKNOWN_VALUE' ) { continue; }
                $line_where[] = $final_column_names[$data_where__key]." = ".$data_where__value;
            }
            $line .= implode(" AND ",$line_where);
            $line .= ";";
        }
        if($data["type"] == "update") {
            $line .= "UPDATE ";
            $line .= "`".$data["table"]."` ";
            $line .= "SET ";
            $line_values = [];
            foreach($data["values"] as $data_values__key=>$data_values__value) {
                // don't include obsolete ones
                if( $data["where"][$data_values__key] == $data_values__value ) { continue; }
                $line_values[] = $final_column_names[$data_values__key]." = ".$data_values__value;
            }        
            $line .= implode(", ",$line_values);
            $line .= " ";
            $line .= "WHERE ";
            $line_where = [];
            foreach($data["where"] as $data_where__key=>$data_where__value) {
                // don't include columns with unknown default values
                if( $data_where__value == '_DEFAULT_UNKNOWN_VALUE' ) { continue; }
                $line_where[] = $final_column_names[$data_where__key]." = ".$data_where__value;
            }
            $line .= implode(" AND ",$line_where);
            $line .= ";";
        }
        if($data["type"] == "drop") {
            $line .= "DROP TABLE IF EXISTS ";
            $line .= $data["table"];
            $line .= ";";
        }
        if($data["type"] == "create") {
            $line .= "CREATE TABLE ";
            $line .= $data["table"];
            $line .= "( ";
            $line_columns = [];
            foreach($data["columns"] as $data_columns__value) {
                $line_columns[] = "`".$data_columns__value["name"]."` ".$data_columns__value["args"];
            }
            if( isset($data['primary_key']) && !empty($data['primary_key']) ) {
                $line_columns[] = 'PRIMARY KEY (`'.$data['primary_key']['name'].'`)';
            }
            $line .= implode(", ",$line_columns);
            $line .= ") ";
            $line .= $data["meta"];
            $line .= ";";
        }
        return $line;
    }

    public static function get_column_names($table) {
        if(empty(self::$column_information)) { return []; }
        if(empty(self::$column_information[$table])) { return []; }
        $columns = [];
        foreach(self::$column_information[$table]["columns"] as $column_information__value) {
            $columns[] = $column_information__value["name"];
        }
        return $columns;
    }

    public static function get_table_primary_key($table) {
        if(empty(self::$column_information)) { return null; }
        if(empty(self::$column_information[$table])) { return null; }
        if(empty(self::$column_information[$table]['columns'])) { return null; }
        if(empty(self::$column_information[$table]['primary_key'])) { return null; }
        foreach(self::$column_information[$table]['columns'] as $column_information__key=>$column_information__value) {
            if( $column_information__value['name'] == self::$column_information[$table]['primary_key']['name'] ) {
                return $column_information__key;
            }
        }
        return null;
    }

    public static function parse_line_insert($line) {
        $data = ['type' => '', 'table' => '', 'values' => []];
        $data['type'] = 'insert';
        $pointer = -1;
        $context = 'statement';
        while(++$pointer < strlen($line) ) {
            $char_cur = substr($line,$pointer,1);
            $char_prev = substr($line,$pointer-1,1);
            $char_next = substr($line,$pointer+1,1);
            
            $map = [
                ['statement',       '`',        'table_open'],
                ['table_open',      '*',        'table'],
                ['table',           '`',        'table_close'],
                ['table_close',     '*',        'statement'],

                ['statement',       '(',        'values_open'],
                ['value',           ')',        'values_close'],
                ['string_close',    ')',        'values_close'],
                ['values_close',    '*',        'statement'],

                ['values',          'hyphen',   'string_open'],
                ['values',          '*',        'value_open'],
                ['values_open',     'hyphen',   'string_open'],
                ['values_open',     '*',        'value_open'],
                
                ['string_open',     '*',        'string'],
                ['string',          'hyphen',   'string_close'],

                ['string_close',    ',',        'values'],
                ['value',           ',',        'values'],
                ['value_open',      ',',        'values'],
                ['value_open',      '*',        'value'],
                
            ];

            foreach($map as $map__value) {
                if( $map__value[0] != $context ) { continue; }
                if( $map__value[1] == '*' ) { $context = $map__value[2]; break; }
                if( $map__value[1] == 'notempty' && $char_cur != ' ' ) { $context = $map__value[2]; break; }
                if( $map__value[1] == 'hyphen' && $char_cur == '\'' && $char_prev != '\\' ) { $context = $map__value[2]; break; }
                if( $map__value[1] == $char_cur ) { $context = $map__value[2]; break; }
            }

            //echo 'letter: '.$char_cur.' - context: '.$context.PHP_EOL;

            if( $context == 'value_open' || $context == 'string_open' ) {
                $data["values"][] = '';
            }

            // collect values based on context
            if( $context == "table" ) {
                $data["table"] .= $char_cur;
                }
            if( $context == "value_open" || $context == 'value' || $context == 'string_open' || $context == 'string_close' || $context == "string" ) {
                $data["values"][count($data["values"])-1] .= $char_cur;
            }
        }


        //{ echo'<pre>';print_r($line);print_r($data);die(); }


        return $data;   
    }

    public static function parse_line_create($line) {
        $data = ['type' => '', 'table' => '', 'columns' => [], 'primary_key' => '', 'keys' => [], 'meta' => ''];
        $data['type'] = 'create';
        $pointer = -1;
        $context = 'statement';
        while(++$pointer < strlen($line) ) {
            $char_cur = substr($line,$pointer,1);
            $char_prev = substr($line,$pointer-1,1);
            $char_next = substr($line,$pointer+1,1);

            // detect context changes (only one at a time)
            // rules: the context can only be changed once per iteration
            // furthermore the context is the context of the current character (not of the next one)

            $map = [
                ['statement',               '`',            'table_open'],
                ['table_open',              '*',            'table'],
                ['table',                   '`',            'table_close'],
                ['table_close',             '*',            'statement'],
                ['statement',               '(',            'columns_open'],
                ['columns_open',            '*',            'columns'],
                ['columns',                 '`',            'column_name_open'],
                ['column_name_open',        '*',            'column_name'],
                ['column_name',             '`',            'column_name_close'],
                ['column_name_close',       '*',            'column_started'],
                ['column_started',          '`',            'column_name_open'],
                ['column_started',          'notempty',     'column_args'],
                ['column_args',             '(',            'column_args_inner_open'],
                ['column_args_inner_open',  '*',            'column_args_inner'],
                ['column_args_inner',       ')',            'column_args_inner_close'],
                ['column_args_inner_close', '*',            'column_args'],
                ['column_args',             ',',            'columns'],
                ['column_args',             ')',            'columns_close'],
                ['columns_close',           ' ',            'meta_open'],
                ['meta_open',               '*',            'meta'],
                ['columns',                 'PRIMARY KEY ',  'primary_key_open'],
                ['primary_key_open',        '*',            'primary_key'],
                ['primary_key',             '`',            'primary_key_inner_open'],
                ['primary_key_inner_open',  '*',            'primary_key_inner'],
                ['primary_key_inner',       '`',            'primary_key_close'],
                ['primary_key_close',       '*',            'columns'],
                ['columns',                 'KEY ',          'key_open'],
                ['key_open',                '*',            'key'],
                ['key',                     '`',            'key_name_open'],
                ['key_name_open',           '*',            'key_name'],
                ['key_name',                '`',            'key_name_close'],
                ['key_name_close',          '*',            'key'],
                ['key',                     '(',            'key_inner_open'],
                ['key_inner_open',          '*',            'key_inner'],
                ['key_inner_close',         ',',            'columns'],
                ['key',                     ',',            'columns'],
                ['key',                     ')',            'columns_close'],
                ['key_inner',               '(',            'key_inner_inner_open'],
                ['key_inner_inner_open',    '*',            'key_inner_inner'],
                ['key_inner_inner',         ')',            'key_inner_inner_close'],
                ['key_inner_inner_close',   ')',            'key_inner_close'],
                ['key_inner_inner_close',   '*',            'key_inner'],
                ['key_inner',               ')',            'key_inner_close'],
                ['key_inner_close',         ')',            'columns_close'],
                ['key_inner_close',         '*',            'key'],
                ['meta',                    ';',            'statement']

            ];

            foreach($map as $map__value) {
                if( $map__value[0] != $context ) { continue; }
                if( $map__value[1] == '*' ) { $context = $map__value[2]; break; }
                if( $map__value[1] == 'notempty' && $char_cur != ' ' ) { $context = $map__value[2]; break; }
                if( strlen($map__value[1]) > 1 && substr($line,$pointer,strlen($map__value[1])) == $map__value[1] ) { $context = $map__value[2]; break; }
                if( $map__value[1] == $char_cur ) { $context = $map__value[2]; break; }
            }

            //echo 'letter: '.$char_cur.' - context: '.$context.PHP_EOL;

            // create new entries
            if( $context == 'column_name_open' ) {
                $data["columns"][] = ["name" => "", "args" => ""];
            }
            if( $context == 'primary_key_open' ) {
                $data["primary_key"] = ["name" => ""];
            }
            if( $context == 'key_open' ) {
                $data["keys"][] = ["name" => "", "args" => ""];
            }

            // collect values based on context
            if( $context == "table" ) {
                $data["table"] .= $char_cur;
            }
            if( $context == "column_name" ) {
                $data["columns"][count($data["columns"])-1]["name"] .= $char_cur;
            }
            if( $context == "column_args" || $context == 'column_args_inner' || $context == 'column_args_inner_open' || $context == 'column_args_inner_close' ) {
                $data["columns"][count($data["columns"])-1]["args"] .= $char_cur;
            }
            if( $context == "primary_key_inner" ) {
                $data["primary_key"]["name"] .= $char_cur;
            }
            if( $context == 'key_name' ) {
                $data["keys"][count($data["keys"])-1]["name"] .= $char_cur;
            }
            if( $context == 'key_inner' || $context == 'key_inner_inner_open' || $context == 'key_inner_inner' || $context == 'key_inner_inner_close' ) {
                $data["keys"][count($data["keys"])-1]["args"] .= $char_cur;
            }
            if( $context == "meta" ) {
                $data["meta"] .= $char_cur;
            }

        }

        return $data;   
    }

}


// cli usage
if (php_sapi_name() == 'cli' && isset($argv) && !empty($argv) && isset($argv[1])) {
	if(!isset($argv) || empty($argv) || !isset($argv[1]) || !in_array($argv[1],['setup','init','diff'])) { echo 'missing options'.PHP_EOL; die(); }
	if( $argv[1] == 'setup' ) {
		magicdiff::setup();
	}
	if( $argv[1] == 'init' ) {
		magicdiff::init();
	}
	if( $argv[1] == 'diff' ) {
		$diff = magicdiff::diff();
		if( empty($diff) ) { echo 'no differences'.PHP_EOL; die(); }
		foreach($diff as $diff__value) {
			print_r($diff__value['diff']['all']);
		}
	}
}