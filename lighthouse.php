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


}


?>