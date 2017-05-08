<?php
namespace vielhuber\magicdiff;
use PDO;
class magicdiff
{

    public static $data;

    /* setup */

    public static function setup() {
        magicdiff::setupDir();
        magicdiff::setupConfig();
    }

    public static function setupDir() {
        if( !magicdiff::checkDir() ) {
            mkdir(magicdiff::path());
            //magicdiff::output('created folder /.magicdiff/...');
        }
        else {
            echo magicdiff::output('folder /.magicdiff/ already present...');
        }
    }

    public static function setupConfig() {
    	if( !magicdiff::checkConfig() ) {
            file_put_contents( magicdiff::path().'/config.json', magicdiff::getConfigBoilerplate() );
            //magicdiff::output('file /.magicdiff/config.json created. now edit config.json...');
        }
		else {
            magicdiff::output('file /.magicdiff/config.json already exists...');
        }
    }

    public static function checkDir() {
        return is_dir(magicdiff::path());
    }

    public static function checkConfig() {
        return file_exists(magicdiff::path().'/config.json');
    }

    public static function checkSetup() {
        if( !magicdiff::checkDir() || !magicdiff::checkConfig() ) { magicdiff::outputAndStop('do setup first...'); }
    }

	public static function getConfigBoilerplate() {
		$config = '{
            "engine": "mysql",
            "database": {
                "host": "localhost",
                "port": "3306",
                "database": "_test",
                "username": "root",
                "password": "root",
                "export": "C:\MAMP\bin\mysql\bin\mysqldump.exe",
                "import": "C:\MAMP\bin\mysql\bin\mysql.exe"
            },
            "ignore": [
                "s_table1",
                "s_table2",
                "s_table3"
            ]
        }';
        $config = str_replace('\\','\\\\',(str_replace('            ','    ',str_replace('        }','}',$config))));
        return $config;
	}

    /* init */

    public static function init() {
        magicdiff::checkSetup();
        magicdiff::exportWithIgnored('reference');	
    }

    public static function exportWithIgnored($filename) {
        magicdiff::export($filename, true);
    }

    public static function export($filename, $with_ignored = false) {
        $tables = magicdiff::getTables($with_ignored);
        if(!empty($tables)) {
			foreach($tables as $tables__value) {
                magicdiff::exportSchema($filename, $tables__value);
                magicdiff::exportData($filename, $tables__value);
			}
		}
    }

    public static function exportSchema($filename, $table) {
        magicdiff::command(magicdiff::conf('database.export').' --no-data --skip-add-drop-table --skip-add-locks --skip-comments --extended-insert=false --disable-keys --quick -h '.magicdiff::conf('database.host').' --port '.magicdiff::conf('database.port').' -u '.magicdiff::conf('database.username').' -p"'.magicdiff::conf('database.password').'" '.magicdiff::conf('database.database').' '.$table.' > '.magicdiff::path().'/_'.$filename.'_'.$table.'_schema.sql');
    }

    public static function exportData($filename, $table) {
        magicdiff::command(magicdiff::conf('database.export').' --no-create-info --skip-add-locks --skip-comments --extended-insert=false --disable-keys --quick -h '.magicdiff::conf('database.host').' --port '.magicdiff::conf('database.port').' -u '.magicdiff::conf('database.username').' -p"'.magicdiff::conf('database.password').'" '.magicdiff::conf('database.database').' '.$table.' > '.magicdiff::path().'/_'.$filename.'_'.$table.'_data.sql');
    }



    public static function getTables($with_ignored = true) {
        $tables = [];
        $ignored = magicdiff::conf('ignore');
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

    /* diff */

    public static function diff() {
        $diff = [];
        magicdiff::checkSetup();
        magicdiff::export('current');
        $tables = magicdiff::getTables();
        if(!empty($tables)) {
            foreach($tables as $tables__value) {
                $diff_table = magicdiff::diffTable($tables__value);
                if(magicdiff::checkDiff($diff_table)) { $diff[] = $diff_table; }  
            }
        }
        return $diff;
    }

    public static function diffTable($table) {
        $diff = magicdiff::diffReturnBoilerplate($table);
        magicdiff::diffCreateEmptyFiles($table);
        magicdiff::diffFormatCommandPerLineSchema($table);
        magicdiff::diffFetchColumnInformation($table);
        $diff['patch'] = magicdiff::diffCompareReferenceWithCurrent($table);
        if($diff['patch']['schema'] == '' && $diff['patch']['data'] == '') { return $diff; }
        $diff['patch']['all'] = magicdiff::diffPatchAll($diff['patch']['schema'], $diff['patch']['data']);
        $statements = [];        
        magicdiff::diffSplitAddedDeletedLines($diff['patch']['all']);
        $statements = magicdiff::diffAlterTable($statements);
        $statements = magicdiff::diffDropTable($statements);
        $statements = magicdiff::diffCreateTable($statements);
        $statements = magicdiff::diffUpdateTable($statements);
        $statements = magicdiff::diffDeleteTable($statements);
        $statements = magicdiff::diffInsertTable($statements);
        $diff['diff'] = magicdiff::diffCreateFinalStatements($statements);
        return $diff;
    }

    public static function checkDiff($diff) {
        return ($diff['diff']['all'] !== null && $diff['diff']['all'] != '');
    }

    public static function diffReturnBoilerplate($table) {
        return [
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
    }

    public static function diffCreateEmptyFiles($table) {
        foreach(['_reference_'.$table.'_schema','_reference_'.$table.'_data','_current_'.$table.'_schema','_current_'.$table.'_data'] as $file) {
            if( !file_exists(magicdiff::path().'/'.$file.'.sql') ) {
                touch(magicdiff::path().'/'.$file.'.sql');
            }
        }
    }

    public static function diffFormatCommandPerLineSchema($table) {
        $unique_delimiter = md5(uniqid(mt_rand(), true));
        foreach([magicdiff::path().'/_reference_'.$table.'_schema.sql',magicdiff::path().'/_current_'.$table.'_schema.sql'] as $file_line) {
            file_put_contents($file_line,str_replace("\r\n",$unique_delimiter,file_get_contents($file_line)));
            file_put_contents($file_line,str_replace("\r",$unique_delimiter,file_get_contents($file_line)));       
            file_put_contents($file_line,str_replace("\n",$unique_delimiter,file_get_contents($file_line)));
            file_put_contents($file_line,str_replace(";".$unique_delimiter,";\n",file_get_contents($file_line)));
            file_put_contents($file_line,str_replace($unique_delimiter,"",file_get_contents($file_line)));
        }
    }

    public static function diffFetchColumnInformation($table) {
        foreach(explode("\n",file_get_contents(magicdiff::path().'/_current_'.$table.'_schema.sql')) as $create_statement) {
            $parse_line = magicdiff::parseLine($create_statement);
            if( $parse_line === null || $parse_line['type'] != 'create' ) { continue; }
            magicdiff::$data['column_information'][$parse_line['table']] = $parse_line;
        }
    }

    public static function diffCompareReferenceWithCurrent($table) {
        $patch = ['schema' => '', 'data' => ''];
        foreach(['schema','data'] as $type) {
            if( md5_file(magicdiff::path().'/_reference_'.$table.'_'.$type.'.sql') == md5_file(magicdiff::path().'/_current_'.$table.'_'.$type.'.sql') ) { continue; }
            passthru('diff --speed-large-files --suppress-common-lines '.magicdiff::path().'/_reference_'.$table.'_'.$type.'.sql '.magicdiff::path().'/_current_'.$table.'_'.$type.'.sql > '.magicdiff::path().'/diff_'.$type.'.result');
            $patch[$type] = file_get_contents(magicdiff::path().'/diff_'.$type.'.result');
            unlink(magicdiff::path().'/diff_'.$type.'.result');
        }
        return $patch;
    }

    public static function diffPatchAll($patch_schema, $patch_data) {
        $patch = $patch_schema."\n".$patch_data;
        // fix line endings
        $patch = str_replace("\r\n","\n",$patch);
        // split diff in new lines (don't use PHP_EOL, because CRLF fails)
        $patch = explode("\n",$patch);
        return $patch;
    }

    public static function diffSplitAddedDeletedLines($patch) {
        magicdiff::$data['diff_added'] = [];
        magicdiff::$data['diff_deleted'] = [];
        foreach($patch as $diff__value) {
            // don't include empty lines
            if($diff__value == '' || trim($diff__value) == '') { continue; }
            // don't include meta lines
            if( strpos($diff__value, '> ') !== 0 && strpos($diff__value, '< ') !== 0 ) { continue; }
            // prepare parsed lines (with hash so we can later remove duplicates)
            $parse_line_raw = substr($diff__value,2);
            $parse_line_hash = md5($parse_line_raw);
            $parse_line = magicdiff::parseLine($parse_line_raw);
            // don't include unrecognized lines
            if($parse_line === null) { continue; }
            // don't include drop tables
            if( $parse_line['type'] == 'drop' ) { continue; }
            // it could be the case, that diff (despite suppress common lines) deletes and inserts the same line. this is sorted out here
            if( strpos($diff__value, '> ') === 0 && array_key_exists($parse_line_hash, magicdiff::$data['diff_deleted']) ) {
                unset(magicdiff::$data['diff_deleted'][$parse_line_hash]);
                continue;
            }
            // save in appropiate array
            if( strpos($diff__value, '> ') === 0 ) { magicdiff::$data['diff_added'][$parse_line_hash] = $parse_line; }
            else { magicdiff::$data['diff_deleted'][$parse_line_hash] = $parse_line; }
        }
    }

    public static function diffAlterTable($statements) {
        magicdiff::$data['alter_deleted'] = [];
        magicdiff::$data['alter_added'] = [];
        foreach(magicdiff::$data['diff_deleted'] as $diff_deleted__key=>$diff_deleted__value) {
        foreach(magicdiff::$data['diff_added'] as $diff_added__key=>$diff_added__value) {
            if(
                $diff_deleted__value['type'] == 'create' &&
                $diff_added__value['type'] == 'create' &&
                $diff_deleted__value['table'] == $diff_added__value['table']
            ) {

                // find all columns that have been deleted
                while(1==1) {
                    $finish = true;
                    foreach(magicdiff::getAlteredColumns($diff_deleted__value['table'], $diff_deleted__value['columns'], 'columns') as $diff_deleted_columns__key=>$diff_deleted_columns__value) {
                        $exists = false;
                        foreach($diff_added__value['columns'] as $diff_added_columns__key=>$diff_added_columns__value) {
                            if(
                                $diff_deleted_columns__value['name'] == $diff_added_columns__value['name'] &&
                                $diff_deleted_columns__value['args'] == $diff_added_columns__value['args']
                            ) {
                                $exists = true;
                            }
                        }
                        if($exists === false) {
                            $statements[] = [
                                'type' => 'alter',
                                'table' => $diff_added__value['table'],
                                'query' => 'DROP COLUMN '.$diff_deleted_columns__value['name'].''
                            ];
                            magicdiff::$data['altered_columns'][] = [$diff_deleted__value['table'], 'delete', $diff_deleted_columns__key];
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
                        foreach(magicdiff::getAlteredColumns($diff_deleted__value['table'],$diff_deleted__value['columns'],'columns') as $diff_deleted_columns__key=>$diff_deleted_columns__value) {
                            if(
                                $diff_deleted_columns__value['name'] == $diff_added_columns__value['name'] &&
                                $diff_deleted_columns__value['args'] == $diff_added_columns__value['args']
                            ) {
                                $exists = true;
                            }
                        }
                        if($exists === false) {
                            $statements[] = [
                                'type' => 'alter',
                                'table' => $diff_added__value['table'],
                                'query' => 'ADD '.$diff_added_columns__value['name'].' '.$diff_added_columns__value['args'].''
                            ];
                            magicdiff::$data['altered_columns'][] = [$diff_deleted__value['table'],'insert',$diff_added_columns__value['name'],$diff_added_columns__value['args']];
                            $finish = false;
                            break;
                        }
                    }
                    if( $finish === true ) { break; }
                }

                // finally reorder all columns
                while(1==1) {
                    $finish = true;
                    $diff_deleted__value_columns = magicdiff::getAlteredColumns($diff_deleted__value['table'],$diff_deleted__value['columns'],'columns');
                    foreach($diff_deleted__value_columns as $diff_deleted_columns__key=>$diff_deleted_columns__value) {
                    foreach($diff_added__value['columns'] as $diff_added_columns__key=>$diff_added_columns__value) {
                        if(
                            $diff_deleted_columns__key != $diff_added_columns__key &&
                            $diff_deleted_columns__value['name'] == $diff_added_columns__value['name'] &&
                            $diff_deleted_columns__value['args'] == $diff_added_columns__value['args']
                        ) {
                            $statements[] = [
                                'type' => 'alter',
                                'table' => $diff_added__value['table'],
                                'query' => 'CHANGE COLUMN '.$diff_deleted_columns__value['name'].' '.$diff_deleted_columns__value['name'].' '.$diff_deleted_columns__value['args'].' AFTER '.$diff_deleted__value_columns[$diff_added_columns__key]['name'].''
                            ];
                            magicdiff::$data['altered_columns'][] = [$diff_deleted__value['table'],'move',$diff_deleted_columns__key,$diff_added_columns__key];
                            $finish = false;
                            break 2;
                        }
                    }    
                    }
                    if( $finish === true ) { break; }
                }

                // save in these arrays so that later we do not reuse those lines again
                magicdiff::$data['alter_deleted'][] = $diff_deleted__key;
                magicdiff::$data['alter_added'][] = $diff_added__key;
            }
        }
        }
        return $statements;
    }

    public static function diffDropTable($statements) {
        magicdiff::$data['tables_deleted'] = [];
        foreach(magicdiff::$data['diff_deleted'] as $diff_deleted__key=>$diff_deleted__value) {
            if(
                $diff_deleted__value['type'] == 'create' &&
                !in_array($diff_deleted__key, magicdiff::$data['alter_deleted'])
            ) {
                $statements[] = [
                    'type' => 'drop',
                    'table' => $diff_deleted__value['table'],
                ];
                magicdiff::$data['tables_deleted'][] = $diff_deleted__value['table'];
            }
        }   
        return $statements;
    }

    public static function diffCreateTable($statements) {
        foreach(magicdiff::$data['diff_added'] as $diff_added__key=>$diff_added__value) {
            if(
                $diff_added__value['type'] == 'create' &&
                !in_array($diff_added__key, magicdiff::$data['alter_added'])
            ) {
                $statements_this = [
                    'type' => 'create',
                    'table' => $diff_added__value['table'],
                    'columns' => $diff_added__value['columns'],
                    'meta' => $diff_added__value['meta'],
                ];
                if( isset($diff_added__value['primary_key']) && !empty($diff_added__value['primary_key']) ) {
                    $statements_this['primary_key'] = $diff_added__value['primary_key'];
                }
                $statements[] = $statements_this;                
            }
        }
        return $statements;
    }

    public static function diffUpdateTable($statements) {
        magicdiff::$data['update_deleted'] = [];
        magicdiff::$data['update_added'] = [];
        foreach(magicdiff::$data['diff_deleted'] as $diff_deleted__key=>$diff_deleted__value) {
        foreach(magicdiff::$data['diff_added'] as $diff_added__key=>$diff_added__value) {
            if(
                $diff_deleted__value['type'] == 'insert' &&
                $diff_added__value['type'] == 'insert' &&
                $diff_deleted__value['table'] == $diff_added__value['table'] &&
                magicdiff::getTablePrimaryKey($diff_deleted__value['table']) !== null &&
                $diff_deleted__value['values'][magicdiff::getTablePrimaryKey($diff_deleted__value['table'])] == $diff_added__value['values'][magicdiff::getTablePrimaryKey($diff_deleted__value['table'])]
            ) {
                $statements[] = [
                    'type' => 'update',
                    'table' => $diff_added__value['table'],
                    'values' => $diff_added__value['values'],
                    'where' => magicdiff::getAlteredColumns($diff_deleted__value['table'],$diff_deleted__value['values'],'values')
                ];
                magicdiff::$data['update_deleted'][] = $diff_deleted__key;
                magicdiff::$data['update_added'][] = $diff_added__key;
            }
        }
        }
        return $statements;
    }

    public static function diffDeleteTable($statements) {
        foreach(magicdiff::$data['diff_deleted'] as $diff_deleted__key=>$diff_deleted__value) {
            if(
                $diff_deleted__value['type'] == 'insert' &&
                !in_array($diff_deleted__key, magicdiff::$data['update_deleted']) &&
                !in_array($diff_deleted__value['table'], magicdiff::$data['tables_deleted'])
            ) {
                $statements[] = [
                    'type' => 'delete',
                    'table' => $diff_deleted__value['table'],
                    'where' => magicdiff::getAlteredColumns($diff_deleted__value['table'],$diff_deleted__value['values'],'values')
                ];
            }
        }
        return $statements;
    }

    public static function diffInsertTable($statements) {
        foreach(magicdiff::$data['diff_added'] as $diff_added__key=>$diff_added__value) {
            if(
                $diff_added__value['type'] == 'insert' &&
                !in_array($diff_added__key, magicdiff::$data['update_added'])
            ) {
                $statements[] = [
                    'type' => 'insert',
                    'table' => $diff_added__value['table'],
                    'values' => $diff_added__value['values']
                ];
            }
        }
        return $statements;
    }

    public static function diffCreateFinalStatements($statements) {
        $diff = ['schema' => '', 'data' => '', 'all' => ''];
        foreach($statements as $statements__value) {
            $parsed_line = magicdiff::reparseLine($statements__value);
            if( in_array($statements__value['type'],['create','alter','drop']) ) {
                $diff['schema'] .= $parsed_line."\n";
            }
            if( in_array($statements__value['type'],['insert','update','delete']) ) {
                $diff['data'] .= $parsed_line."\n";
            }
            $diff['all'] .= $parsed_line."\n";
        }
        return $diff;
    }

    public static function getAlteredColumns($table, $data, $type) {
        if(empty(magicdiff::$data['altered_columns'])) { return $data; }
        foreach(magicdiff::$data['altered_columns'] as $altered_columns__value) {
            if($altered_columns__value[0] != $table) { continue; }

            if($altered_columns__value[1] == 'move') {
                $move_from = $altered_columns__value[2];
                $move_to = $altered_columns__value[3];
                //echo 'moving from '.$move_from.' to '.$move_to.''.PHP_EOL;
                array_splice($data, $move_to, 0, array_splice($data, $move_from, 1));
            }
            if($altered_columns__value[1] == 'delete') {
                $delete_index = $altered_columns__value[2];
                //echo 'deleting '.$delete_index.''.PHP_EOL;
                unset($data[$delete_index]);
                $data = array_values($data);
            }
            if($altered_columns__value[1] == 'insert') {
                if($type == 'columns') {
                    $data[] = [
                        'name' => $altered_columns__value[2],
                        'args' => $altered_columns__value[3]
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

    public static function parseLine($line) {
        $line = trim($line);
        if(strpos($line,'INSERT INTO') === 0) {
            return magicdiff::parseLineInsert($line);
        }
        if(strpos($line,'CREATE TABLE') === 0) { 
            return magicdiff::parseLineCreate($line);
        }
        return null;
    }

    public static function parseLineInsert($line) {
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

            // create new entries
            if( $context == 'value_open' || $context == 'string_open' ) {
                $data['values'][] = '';
            }

            // collect values based on context
            if( $context == 'table' ) {
                $data['table'] .= $char_cur;
                }
            if( $context == 'value_open' || $context == 'value' || $context == 'string_open' || $context == 'string_close' || $context == 'string' ) {
                $data['values'][count($data['values'])-1] .= $char_cur;
            }
        }

        return $data;   
    }

    public static function parseLineCreate($line) {
        $data = ['type' => '', 'table' => '', 'columns' => [], 'primary_key' => '', 'keys' => [], 'meta' => ''];
        $data['type'] = 'create';
        $pointer = -1;
        $context = 'statement';
        while(++$pointer < strlen($line) ) {
            $char_cur = substr($line,$pointer,1);
            $char_prev = substr($line,$pointer-1,1);
            $char_next = substr($line,$pointer+1,1);

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

            // create new entries
            if( $context == 'column_name_open' ) {
                $data['columns'][] = ['name' => '', 'args' => ''];
            }
            if( $context == 'primary_key_open' ) {
                $data['primary_key'] = ['name' => ''];
            }
            if( $context == 'key_open' ) {
                $data['keys'][] = ['name' => '', 'args' => ''];
            }

            // collect values based on context
            if( $context == 'table' ) {
                $data['table'] .= $char_cur;
            }
            if( $context == 'column_name' ) {
                $data['columns'][count($data['columns'])-1]['name'] .= $char_cur;
            }
            if( $context == 'column_args' || $context == 'column_args_inner' || $context == 'column_args_inner_open' || $context == 'column_args_inner_close' ) {
                $data['columns'][count($data['columns'])-1]['args'] .= $char_cur;
            }
            if( $context == 'primary_key_inner' ) {
                $data['primary_key']['name'] .= $char_cur;
            }
            if( $context == 'key_name' ) {
                $data['keys'][count($data['keys'])-1]['name'] .= $char_cur;
            }
            if( $context == 'key_inner' || $context == 'key_inner_inner_open' || $context == 'key_inner_inner' || $context == 'key_inner_inner_close' ) {
                $data['keys'][count($data['keys'])-1]['args'] .= $char_cur;
            }
            if( $context == 'meta' ) {
                $data['meta'] .= $char_cur;
            }
        }
        return $data;   
    }

    public static function reparseLine($data) {
        if($data['type'] == 'alter') {
            return magicdiff::reparseLineAlter($data);
        }
        if($data['type'] == 'insert') {
            return magicdiff::reparseLineInsert($data);
        }
        if($data['type'] == 'delete') {
            return magicdiff::reparseLineDelete($data);
        }
        if($data['type'] == 'update') {
            return magicdiff::reparseLineUpdate($data);
        }
        if($data['type'] == 'drop') {
            return magicdiff::reparseLineDrop($data);
        }
        if($data['type'] == 'create') {
            return magicdiff::reparseLineCreate($data);
        }
        return null;
    }

    public static function reparseLineAlter($data) {
        $line = '';
        $line .= 'ALTER TABLE ';
        $line .= $data['table'].' ';
        $line .= $data['query'];
        $line .= ';';
        return $line;
    }

    public static function reparseLineInsert($data) {
        $line = '';
        $line .= 'INSERT INTO ';
        $line .= '`'.$data['table'].'` ';
        $line .= '('.implode(',',magicdiff::getColumnNames($data['table'])).') ';
        $line .= 'VALUES('.implode(',',$data['values']).')';
        $line .= ';';
        return $line;
    }

    public static function reparseLineDelete($data) {
        $line = '';
        $line .= 'DELETE FROM ';
        $line .= '`'.$data['table'].'` ';
        $line .= 'WHERE ';
        $line_where = [];
        foreach($data['where'] as $data_where__key=>$data_where__value) {
            // don't include columns with unknown default values
            if( $data_where__value == '_DEFAULT_UNKNOWN_VALUE' ) { continue; }
            $line_where[] = magicdiff::getColumnNames($data['table'])[$data_where__key].' = '.$data_where__value;
        }
        $line .= implode(' AND ',$line_where);
        $line .= ';';
        return $line;
    }
    
    public static function reparseLineUpdate($data) {
        $line = '';
        $line .= 'UPDATE ';
        $line .= '`'.$data['table'].'` ';
        $line .= 'SET ';
        $line_values = [];
        foreach($data['values'] as $data_values__key=>$data_values__value) {
            // don't include obsolete ones
            if( $data['where'][$data_values__key] == $data_values__value ) { continue; }
            $line_values[] = magicdiff::getColumnNames($data['table'])[$data_values__key].' = '.$data_values__value;
        }        
        $line .= implode(', ',$line_values);
        $line .= ' ';
        $line .= 'WHERE ';
        $line_where = [];
        foreach($data['where'] as $data_where__key=>$data_where__value) {
            // don't include columns with unknown default values
            if( $data_where__value == '_DEFAULT_UNKNOWN_VALUE' ) { continue; }
            $line_where[] = magicdiff::getColumnNames($data['table'])[$data_where__key].' = '.$data_where__value;
        }
        $line .= implode(' AND ',$line_where);
        $line .= ';';
        return $line;
    }

    public static function reparseLineDrop($data) {
        $line = '';
        $line .= 'DROP TABLE IF EXISTS ';
        $line .= $data['table'];
        $line .= ';';
        return $line;
    }

    public static function reparseLineCreate($data) {
        $line = '';
        $line .= 'CREATE TABLE ';
        $line .= $data['table'];
        $line .= '( ';
        $line_columns = [];
        foreach($data['columns'] as $data_columns__value) {
            $line_columns[] = '`'.$data_columns__value['name'].'` '.$data_columns__value['args'];
        }
        if( isset($data['primary_key']) && !empty($data['primary_key']) ) {
            $line_columns[] = 'PRIMARY KEY (`'.$data['primary_key']['name'].'`)';
        }
        $line .= implode(', ',$line_columns);
        $line .= ') ';
        $line .= $data['meta'];
        $line .= ';';
        return $line;
    }

    public static function getColumnNames($table) {
        if(empty(magicdiff::$data['column_information'])) { return []; }
        if(empty(magicdiff::$data['column_information'][$table])) { return []; }
        $columns = [];
        foreach(magicdiff::$data['column_information'][$table]['columns'] as $column_information__value) {
            $columns[] = $column_information__value['name'];
        }
        return $columns;
    }

    public static function getTablePrimaryKey($table) {
        if(empty(magicdiff::$data['column_information'])) { return null; }
        if(empty(magicdiff::$data['column_information'][$table])) { return null; }
        if(empty(magicdiff::$data['column_information'][$table]['columns'])) { return null; }
        if(empty(magicdiff::$data['column_information'][$table]['primary_key'])) { return null; }
        foreach(magicdiff::$data['column_information'][$table]['columns'] as $column_information__key=>$column_information__value) {
            if( $column_information__value['name'] == magicdiff::$data['column_information'][$table]['primary_key']['name'] ) {
                return $column_information__key;
            }
        }
        return null;
    }


    /* helper functions */
    public static function path() {
        $path = getcwd();
        if( strpos($path,'\src') !== false ) { $path = str_replace('\src','',$path); }
        $path .= '/.magicdiff/';
        return $path;
	}

    public static function output($message) {
        echo $message;
        echo PHP_EOL;
    }

    public static function outputAndStop($message) {
        magicdiff::output($message);
        die();
    }

	public static function conf($path) {
		$config = magicdiff::getConfig();
        $keys = explode('.', $path);
        foreach($keys as $key) {
	        if(isset($config[$key])) { $config = $config[$key]; }
	        else { return null; }
        }
        return $config;       
	}

    public static function getConfig() {
        $config = file_get_contents(magicdiff::path().'/config.json');
        // if kiwi config is available, chose this configuration instead
        if( file_exists(magicdiff::path().'/../.kiwi/config.json') ) {
            $config = file_get_contents(magicdiff::path().'/../.kiwi/config.json');
        }
        $config = json_decode($config);
        if( json_last_error() != JSON_ERROR_NONE ) { magicdiff::outputAndStop('corrupt config file.'); }
        $config = json_decode(json_encode($config),true);
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

    public static function reset() {
        array_map('unlink', glob(magicdiff::path().'/*'));
        rmdir(magicdiff::path());
    }

    public static function lb($message = '') {
        if(!isset($GLOBALS['performance'])) { $GLOBALS['performance'] = []; }
        $GLOBALS['performance'][] = ['time' => microtime(true), 'message' => $message];
    }
    public static function le() {
        echo 'script '.$GLOBALS['performance'][count($GLOBALS['performance'])-1]['message'].' execution time: '.number_format((microtime(true)-$GLOBALS['performance'][count($GLOBALS['performance'])-1]['time']),5). ' seconds'.PHP_EOL;
        unset($GLOBALS['performance'][count($GLOBALS['performance'])-1]);
        $GLOBALS['performance'] = array_values($GLOBALS['performance']);
    }

}


// cli usage
if (php_sapi_name() == 'cli' && isset($argv) && !empty($argv) && isset($argv[1])) {
	if(!isset($argv) || empty($argv) || !isset($argv[1]) || !in_array($argv[1],['setup','init','diff'])) {
        magicdiff::outputAndStop('missing options');
    }
	if( $argv[1] == 'setup' ) {
		magicdiff::setup();
	}
	if( $argv[1] == 'init' ) {
		magicdiff::init();
	}
	if( $argv[1] == 'diff' ) {
		$diff = magicdiff::diff();
		if( empty($diff) ) { magicdiff::outputAndStop('no differences'); }
		foreach($diff as $diff__value) {
			print_r($diff__value['diff']['all']);
		}
	}
}