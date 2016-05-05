<?php
/*!
 * Light House Database Framework
 * http://rukhsar.me.uk/lighthouse
 * Version 1.0.0
 *
 * Copyright 2016, Rukhsar Manzoor
 * Released under the MIT license
 */

class LightHouse {

    // General
    protected $database_type;

    protected $charset;

    protected $database_name;

    // For MySQL, MariaDB, MSSQL, Sybase, PostgreSQL, Oracle
    protected $server;

    protected $username;

    protected $password;

    // For SQLite
    protected $database_file;

    // For MySQL or MariaDB with unix_socket
    protected $socket;

    // Optional
    protected $port;

    protected $prefix;

    protected $option = array();

    // Variable
    protected $logs = array();

    protected $debug_mode = false;

    public function __construct($options = null)
    {
        try {
            $commands = array();
            $dsn = '';

            if (is_array($options))
            {
                foreach ($options as $option => $value)
                {
                    $this->$option = $value;
                }
            }
            else
            {
                return false;
            }

            if (
                isset($this->port) &&
                is_int($this->port * 1)
            )
            {
                $port = $this->port;
            }

            $type = strtolower($this->database_type);
            $is_port = isset($port);

            if (isset($options[ 'prefix' ]))
            {
                $this->prefix = $options[ 'prefix' ];
            }

            switch ($type)
            {
                case 'mariadb':
                    $type = 'mysql';

                case 'mysql':
                    if ($this->socket)
                    {
                        $dsn = $type . ':unix_socket=' . $this->socket . ';dbname=' . $this->database_name;
                    }
                    else
                    {
                        $dsn = $type . ':host=' . $this->server . ($is_port ? ';port=' . $port : '') . ';dbname=' . $this->database_name;
                    }

                    // Make MySQL using standard quoted identifier
                    $commands[] = 'SET SQL_MODE=ANSI_QUOTES';
                    break;

                case 'pgsql':
                    $dsn = $type . ':host=' . $this->server . ($is_port ? ';port=' . $port : '') . ';dbname=' . $this->database_name;
                    break;

                case 'sybase':
                    $dsn = 'dblib:host=' . $this->server . ($is_port ? ':' . $port : '') . ';dbname=' . $this->database_name;
                    break;

                case 'oracle':
                    $dbname = $this->server ?
                        '//' . $this->server . ($is_port ? ':' . $port : ':1521') . '/' . $this->database_name :
                        $this->database_name;

                    $dsn = 'oci:dbname=' . $dbname . ($this->charset ? ';charset=' . $this->charset : '');
                    break;

                case 'mssql':
                    $dsn = strstr(PHP_OS, 'WIN') ?
                        'sqlsrv:server=' . $this->server . ($is_port ? ',' . $port : '') . ';database=' . $this->database_name :
                        'dblib:host=' . $this->server . ($is_port ? ':' . $port : '') . ';dbname=' . $this->database_name;

                    // Keep MSSQL QUOTED_IDENTIFIER is ON for standard quoting
                    $commands[] = 'SET QUOTED_IDENTIFIER ON';
                    break;

                case 'sqlite':
                    $dsn = $type . ':' . $this->database_file;
                    $this->username = null;
                    $this->password = null;
                    break;
            }

            if (
                in_array($type, explode(' ', 'mariadb mysql pgsql sybase mssql')) &&
                $this->charset
            )
            {
                $commands[] = "SET NAMES '" . $this->charset . "'";
            }

            $this->pdo = new PDO(
                $dsn,
                $this->username,
                $this->password,
                $this->option
            );

            foreach ($commands as $value)
            {
                $this->pdo->exec($value);
            }
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }
    }
    public function query($query)
    {
        if ($this->debug_mode)
        {
            echo $query;

            $this->debug_mode = false;

            return false;
        }

        array_push($this->logs, $query);

        return $this->pdo->query($query);
    }
    public function exec($query)
    {
        if ($this->debug_mode)
        {
            echo $query;

            $this->debug_mode = false;

            return false;
        }

        array_push($this->logs, $query);

        return $this->pdo->exec($query);
    }

    public function quote($string)
    {
        return $this->pdo->quote($string);
    }

    protected function column_quote($string)
    {
        return '"' . str_replace('.', '"."', preg_replace('/(^#|\(JSON\)\s*)/', '', $string)) . '"';
    }

    protected function column_push($columns)
    {
        if ($columns == '*')
        {
            return $columns;
        }

        if (is_string($columns))
        {
            $columns = array($columns);
        }

        $stack = array();

        foreach ($columns as $key => $value)
        {
            preg_match('/([a-zA-Z0-9_\-\.]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $value, $match);

            if (isset($match[ 1 ], $match[ 2 ]))
            {
                array_push($stack, $this->column_quote( $match[ 1 ] ) . ' AS ' . $this->column_quote( $match[ 2 ] ));
            }
            else
            {
                array_push($stack, $this->column_quote( $value ));
            }
        }

        return implode($stack, ',');
    }
    protected function array_quote($array)
    {
        $temp = array();
        foreach ($array as $value)
        {
            $temp[] = is_int($value) ? $value : $this->pdo->quote($value);
        }
        return implode($temp, ',');
    }
    protected function inner_conjunct($data, $conjunctor, $outer_conjunctor)
    {
        $haystack = array();
        foreach ($data as $value)
        {
            $haystack[] = '(' . $this->data_implode($value, $conjunctor) . ')';
        }
        return implode($outer_conjunctor . ' ', $haystack);
    }
    protected function fn_quote($column, $string)
    {
        return (strpos($column, '#') === 0 && preg_match('/^[A-Z0-9\_]*\([^)]*\)$/', $string)) ?
            $string :
            $this->quote($string);
    }

    protected function data_implode($data, $conjunctor, $outer_conjunctor = null)
    {
        $wheres = array();
        foreach ($data as $key => $value)
        {
            $type = gettype($value);
            if (
                preg_match("/^(AND|OR)(\s+#.*)?$/i", $key, $relation_match) &&
                $type == 'array'
            )
            {
                $wheres[] = 0 !== count(array_diff_key($value, array_keys(array_keys($value)))) ?
                    '(' . $this->data_implode($value, ' ' . $relation_match[ 1 ]) . ')' :
                    '(' . $this->inner_conjunct($value, ' ' . $relation_match[ 1 ], $conjunctor) . ')';
            }
            else
            {
                preg_match('/(#?)([\w\.\-]+)(\[(\>|\>\=|\<|\<\=|\!|\<\>|\>\<|\!?~)\])?/i', $key, $match);
                $column = $this->column_quote($match[ 2 ]);
                if (isset($match[ 4 ]))
                {
                    $operator = $match[ 4 ];
                    if ($operator == '!')
                    {
                        switch ($type)
                        {
                            case 'NULL':
                                $wheres[] = $column . ' IS NOT NULL';
                                break;
                            case 'array':
                                $wheres[] = $column . ' NOT IN (' . $this->array_quote($value) . ')';
                                break;
                            case 'integer':
                            case 'double':
                                $wheres[] = $column . ' != ' . $value;
                                break;
                            case 'boolean':
                                $wheres[] = $column . ' != ' . ($value ? '1' : '0');
                                break;
                            case 'string':
                                $wheres[] = $column . ' != ' . $this->fn_quote($key, $value);
                                break;
                        }
                    }
                    if ($operator == '<>' || $operator == '><')
                    {
                        if ($type == 'array')
                        {
                            if ($operator == '><')
                            {
                                $column .= ' NOT';
                            }
                            if (is_numeric($value[ 0 ]) && is_numeric($value[ 1 ]))
                            {
                                $wheres[] = '(' . $column . ' BETWEEN ' . $value[ 0 ] . ' AND ' . $value[ 1 ] . ')';
                            }
                            else
                            {
                                $wheres[] = '(' . $column . ' BETWEEN ' . $this->quote($value[ 0 ]) . ' AND ' . $this->quote($value[ 1 ]) . ')';
                            }
                        }
                    }
                    if ($operator == '~' || $operator == '!~')
                    {
                        if ($type != 'array')
                        {
                            $value = array($value);
                        }
                        $like_clauses = array();
                        foreach ($value as $item)
                        {
                            $item = strval($item);
                            $suffix = mb_substr($item, -1, 1);
                            if ($suffix === '_')
                            {
                                $item = substr_replace($item, '%', -1);
                            }
                            elseif ($suffix === '%')
                            {
                                $item = '%' . substr_replace($item, '', -1, 1);
                            }
                            elseif (preg_match('/^(?!%).+(?<!%)$/', $item))
                            {
                                $item = '%' . $item . '%';
                            }
                            $like_clauses[] = $column . ($operator === '!~' ? ' NOT' : '') . ' LIKE ' . $this->fn_quote($key, $item);
                        }
                        $wheres[] = implode(' OR ', $like_clauses);
                    }
                    if (in_array($operator, array('>', '>=', '<', '<=')))
                    {
                        if (is_numeric($value))
                        {
                            $wheres[] = $column . ' ' . $operator . ' ' . $value;
                        }
                        elseif (strpos($key, '#') === 0)
                        {
                            $wheres[] = $column . ' ' . $operator . ' ' . $this->fn_quote($key, $value);
                        }
                        else
                        {
                            $wheres[] = $column . ' ' . $operator . ' ' . $this->quote($value);
                        }
                    }
                }
                else
                {
                    switch ($type)
                    {
                        case 'NULL':
                            $wheres[] = $column . ' IS NULL';
                            break;
                        case 'array':
                            $wheres[] = $column . ' IN (' . $this->array_quote($value) . ')';
                            break;
                        case 'integer':
                        case 'double':
                            $wheres[] = $column . ' = ' . $value;
                            break;
                        case 'boolean':
                            $wheres[] = $column . ' = ' . ($value ? '1' : '0');
                            break;
                        case 'string':
                            $wheres[] = $column . ' = ' . $this->fn_quote($key, $value);
                            break;
                    }
                }
            }
        }
        return implode($conjunctor . ' ', $wheres);
    }




}


?>