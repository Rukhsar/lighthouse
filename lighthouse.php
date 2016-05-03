<?php
/*!
 * Light House Database Framework
 * http://rukhsar.me.uk/lighthouse
 * Version 1.0.0
 *
 * Copyright 2016, Rukhsar Manzoor
 * Released under the MIT license
 */

class lighthouse {

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

}


?>