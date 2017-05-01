<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Setup;

use DB_Helper;
use Eventum\Db\Adapter\AdapterInterface;
use Eventum\Db\DatabaseException;
use Eventum\Db\Migrate;
use Exception;
use Misc;
use RuntimeException;
use Setup;

class DatabaseSetup
{
    /** @var AdapterInterface */
    private $conn;

    public function __construct()
    {
        $this->conn = $this->getDb();
    }

    /**
     *
     * Check the CREATE and DROP privileges by trying to create and drop a test table.
     *
     * @param string $db_name
     */
    private function checkDatabaseAccess($db_name)
    {
        // check if we can use the database
        try {
            $this->conn->query("USE {{{$db_name}}}");
        } catch (DatabaseException $e) {
            throw new RuntimeException($this->getErrorMessage('select_db', $e->getMessage()));
        }

        $table_list = $this->getTableList();
        if (!in_array('eventum_test', $table_list)) {
            try {
                $this->conn->query('CREATE TABLE eventum_test (test CHAR(1))');
            } catch (DatabaseException $e) {
                throw new RuntimeException($this->getErrorMessage('create_test', $e->getMessage()));
            }
        }
        try {
            $this->conn->query('DROP TABLE eventum_test');
        } catch (DatabaseException $e) {
            throw new RuntimeException($this->getErrorMessage('drop_test', $e->getMessage()));
        }
    }

    /**
     * init database with with upgrade tool
     *
     * @return array output from upgrade script
     */
    private function migrateDatabase()
    {
        $buffer = [];
        try {
            $dbmigrate = new Migrate(APP_PATH . '/upgrade');
            $dbmigrate->setLogger(
                function ($e) use (&$buffer) {
                    $buffer[] = $e;
                }
            );
            $dbmigrate->patch_database();
            $e = false;
        } catch (Exception $e) {
        }

        if ($e) {
            $upgrade_script = APP_PATH . '/bin/upgrade.php';
            $error = [
                'Database setup failed on upgrade:',
                "<tt>{$e->getMessage()}</tt>",
                '',
                "You may want run update script <tt>$upgrade_script</tt> manually",
            ];
            throw new RuntimeException(implode('<br/>', $error));
        }

        return $buffer;
    }

    public function run($db_config)
    {
        $db_exists = $this->checkDatabaseExists($db_config['db_name']);
        if (!$db_exists) {
            if ($db_config['create_db']) {
                $this->createDatabase($db_config['db_name']);
            } else {
                throw new RuntimeException(
                    'The provided database name could not be found. Review your information or specify that the database should be created in the form below.'
                );
            }
        }

        // create the new user, if needed
        if ($db_config['alternate_user']) {
            if ($db_config['create_user']) {
                $this->createUser($db_config['db_name'], $db_config['user'], $db_config['password']);
            }

            if (!$this->userExists($db_config['user'])) {
                throw new RuntimeException(
                    'The provided MySQL username could not be found. Review your information or specify that the username should be created in the form below.'
                );
            }
        }

        // set sql mode (sad that we rely on old bad mysql defaults)
        $this->conn->query("SET SQL_MODE = ''");

        $this->checkDatabaseAccess($db_config['db_name']);

        // if requested. drop tables first
        if ($db_config['drop_tables']) {
            $this->dropTables();
        }

        // write db config now that database and access is configured
        $this->writeDatabaseConfig($db_config);

        return $this->migrateDatabase();
    }

    /**
     * Update database config with db name.
     * Initial database config was written by Setup.
     *
     * @param array $db_config
     */
    private function writeDatabaseConfig($db_config)
    {
        $setup = [];
        $setup['database'] = $db_config['db_name'];

        if ($db_config['alternate_user']) {
            $setup['username'] = $db_config['username'];
            $setup['password'] = $db_config['password'];
        }

        Setup::save(['database' => $setup]);
    }

    private function getDb()
    {
        try {
            return DB_Helper::getInstance(false);
        } catch (DatabaseException $e) {
        }

        $err = $e->getMessage();

        // Given such PDO Exception:
        // "SQLSTATE[HY000] [2002] No such file or directory"
        // indicate that mysql default socket may be wrong
        if (strpos($err, 'No such file or directory') !== false) {
            $ini = 'pdo_mysql.default_socket';
            $err .= sprintf(". Please check that PHP ini parameter $ini='%s' is correct", ini_get($ini));
        }

        throw new RuntimeException($err, $e->getCode());
    }

    private function get_queries($file)
    {
        $contents = file_get_contents($file);
        $queries = explode(';', $contents);
        $queries = Misc::trim($queries);
        $queries = array_filter($queries);

        return $queries;
    }

    private function getErrorMessage($type, $message)
    {
        if (!$message) {
            return '';
        }

        if (stristr($message, 'Unknown MySQL Server Host')) {
            return 'Could not connect to the MySQL database server with the provided information.';
        }
        if (stristr($message, 'Unknown database')) {
            return 'The database name provided does not exist.';
        }
        if ($type == 'create_test' && (stristr($message, 'Access denied'))) {
            return 'The provided MySQL username doesn\'t have the appropriate permissions to create tables. Please contact your local system administrator for further assistance.';
        }
        if ($type == 'drop_test' && (stristr($message, 'Access denied'))) {
            return 'The provided MySQL username doesn\'t have the appropriate permissions to drop tables. Please contact your local system administrator for further assistance.';
        }

        return $message;
    }

    /***
     * @param string $database
     * @return array|null
     */
    private function checkDatabaseExists($database)
    {
        $exists = $this->conn->getOne('SHOW DATABASES LIKE ?', [$database]);

        return $exists;
    }

    private function createDatabase($db_name)
    {
        try {
            $this->conn->query("CREATE DATABASE {{{$db_name}}}");
        } catch (DatabaseException $e) {
            throw new RuntimeException($this->getErrorMessage('create_db', $e->getMessage()));
        }
    }

    private function dropTables()
    {
        $queries = $this->get_queries(APP_PATH . '/upgrade/drop.sql');
        foreach ($queries as $stmt) {
            try {
                $this->conn->query($stmt);
            } catch (DatabaseException $e) {
                throw new RuntimeException($this->getErrorMessage('drop_table', $e->getMessage()));
            }
        }
    }

    /**
     * @return array
     */
    private function getUserList()
    {
        // avoid "1046 ** No database selected" error
        $this->conn->query('USE mysql');
        try {
            $users = $this->conn->getColumn('SELECT DISTINCT User FROM user');
        } catch (DatabaseException $e) {
            // if the user cannot select from the mysql.user table, then return an empty list
            return [];
        }

        return $users;
    }

    private function userExists($user)
    {
        $user_list = $this->getUserList();

        return in_array($user, $user_list);
    }

    private function createUser($db_name, $user, $password)
    {
        if ($this->userExists($user)) {
            return;
        }

        $permissions = 'SELECT, UPDATE, DELETE, INSERT, ALTER, DROP, CREATE, INDEX';
        $stmt
            = "GRANT {$permissions} ON {{{$db_name}}}.* TO ?@'%' IDENTIFIED BY ?";
        try {
            $this->conn->query($stmt, [$user, $password]);
        } catch (DatabaseException $e) {
            throw new RuntimeException($this->getErrorMessage('create_user', $e->getMessage()));
        }
    }

    /**
     * @return array
     */
    private function getTableList()
    {
        return $this->conn->getColumn('SHOW TABLES');
    }
}
