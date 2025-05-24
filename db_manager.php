<?php declare(strict_types=1);

// --- Database Configuration ---
// IMPORTANT: In a production environment, these should be configured via environment variables 
// (e.g., using getenv() or a library like Dotenv) and this file should have restrictive permissions
// if it ever contains live credentials, and ideally should not be web-accessible.
// Example: define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_HOST', '127.0.0.1'); // Or your MySQL host
define('DB_NAME', 'meta_social_platform'); // Your database name
// define('DB_USER', 'YOUR_DB_USER_HERE'); // Placeholder for your database username
// define('DB_PASS', 'YOUR_DB_PASSWORD_HERE'); // Placeholder for your database password
define('DB_CHARSET', 'utf8mb4');

/**
 * Class DBManager
 *
 * Manages database connections and provides helper functions for common CRUD operations
 * using PDO.
 * 
 * Note on Long-Running Processes: This class uses a static PDO instance, which is generally
 * suitable for typical PHP request lifecycles. For very long-running CLI scripts or
 * application server environments (e.g., Swoole, RoadRunner), more sophisticated
 * connection management (like connection pooling, explicit close/reconnect logic, or
 * checking connection validity) might be necessary to prevent stale connections.
 */
class DBManager
{
    private static ?PDO $pdoInstance = null;

    /**
     * Gets a PDO database connection instance.
     *
     * Initializes the connection if it hasn't been established yet.
     * Configures PDO for error exceptions and associative array fetching.
     *
     * @return PDO The PDO connection instance.
     * @throws PDOException If the connection fails.
     */
    public static function getConnection(): PDO
    {
        if (self::$pdoInstance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // For true prepared statements
            ];

            try {
                self::$pdoInstance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // In a real application, log this error to a file or error tracking service
                $logMessage = "DBManager::getConnection() - Database Connection Error: " . $e->getMessage() . " (DSN: mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ")";
                error_log($logMessage);
                throw new PDOException("Database Connection Error: " . $e->getMessage(), (int)$e->getCode()); // Re-throw original for higher level handling
            }
        }
        return self::$pdoInstance;
    }

    /**
     * Executes a non-query SQL statement (INSERT, UPDATE, DELETE).
     *
     * @param string $sql The SQL query string.
     * @param array $params Parameters for the prepared statement.
     * @return int The number of affected rows.
     * @throws PDOException If the query execution fails.
     */
    public static function executeNonQuery(string $sql, array $params = []): int
    {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $logMessage = "DBManager::executeNonQuery() Error: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params);
            error_log($logMessage);
            // Consider if logging $e->getTraceAsString() is needed for debug mode
            // if (defined('DEBUG_MODE') && DEBUG_MODE) { error_log($e->getTraceAsString()); }
            throw new PDOException("NonQuery Execution Error: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    /**
     * Fetches a single row from the database.
     *
     * @param string $sql The SQL query string.
     * @param array $params Parameters for the prepared statement.
     * @return array|false The fetched row as an associative array, or false if no row is found.
     * @throws PDOException If the query execution fails.
     */
    public static function fetchOne(string $sql, array $params = []): array|false
    {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            $logMessage = "DBManager::fetchOne() Error: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params);
            error_log($logMessage);
            throw new PDOException("FetchOne Error: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    /**
     * Fetches all rows from the database that match the query.
     *
     * @param string $sql The SQL query string.
     * @param array $params Parameters for the prepared statement.
     * @return array An array of associative arrays representing the fetched rows.
     * @throws PDOException If the query execution fails.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $logMessage = "DBManager::fetchAll() Error: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params);
            error_log($logMessage);
            throw new PDOException("FetchAll Error: " . $e->getMessage(), (int)$e->getCode());
        }
    }

    /**
     * Gets the ID of the last inserted row.
     *
     * @return string|false The ID of the last inserted row, or false if it cannot be retrieved.
     * @throws PDOException If there is an issue with the database connection.
     */
    public static function getLastInsertId(): string|false
    {
        try {
            return self::getConnection()->lastInsertId();
        } catch (PDOException $e) {
            $logMessage = "DBManager::getLastInsertId() Error: " . $e->getMessage();
            error_log($logMessage);
            throw new PDOException("GetLastInsertId Error: " . $e->getMessage(), (int)$e->getCode());
        }
    }
}

/*
// --- Example Usage (Commented Out) ---

// Ensure you have a table like this for the example:
// CREATE TABLE IF NOT EXISTS users (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     name VARCHAR(255) NOT NULL,
//     email VARCHAR(255) NOT NULL UNIQUE,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
// );

// try {
//     // 1. Get PDO connection
//     $pdo = DBManager::getConnection();
//     echo "Successfully connected to the database.\n";

//     // 2. Insert a new user
//     $insertSql = "INSERT INTO users (name, email) VALUES (:name, :email)";
//     $userParams = [
//         ':name' => 'John Doe ' . time(), // Add timestamp to make email unique for reruns
//         ':email' => 'john.doe.' . time() . '@example.com'
//     ];
//     $affectedRows = DBManager::executeNonQuery($insertSql, $userParams);
//     echo "Inserted {$affectedRows} row(s).\n";

//     // 3. Get the ID of the last inserted user
//     $lastId = DBManager::getLastInsertId();
//     echo "Last inserted ID: {$lastId}\n";

//     // 4. Fetch the inserted user by ID
//     if ($lastId) {
//         $selectOneSql = "SELECT * FROM users WHERE id = :id";
//         $user = DBManager::fetchOne($selectOneSql, [':id' => $lastId]);
//         if ($user) {
//             echo "\nFetched User by ID ({$lastId}):\n";
//             print_r($user);
//         } else {
//             echo "User with ID {$lastId} not found.\n";
//         }
//     }
    
//     // 5. Fetch all users
//     $selectAllSql = "SELECT * FROM users ORDER BY created_at DESC LIMIT 5";
//     $allUsers = DBManager::fetchAll($selectAllSql);
//     echo "\nFetched All Users (LIMIT 5):\n";
//     foreach ($allUsers as $u) {
//         print_r($u);
//     }

//     // Example of an update (ensure a user with ID 1 exists or change the ID)
//     // $updateSql = "UPDATE users SET name = :name WHERE id = :id";
//     // $updatedRows = DBManager::executeNonQuery($updateSql, [':name' => 'John Doe Updated', ':id' => $lastId]);
//     // echo "\nUpdated {$updatedRows} row(s).\n";
//     // $updatedUser = DBManager::fetchOne($selectOneSql, [':id' => $lastId]);
//     // echo "User after update:\n";
//     // print_r($updatedUser);


//     // Example of a delete (be careful with this in a real DB)
//     // if ($lastId) {
//     //     $deleteSql = "DELETE FROM users WHERE id = :id";
//     //     $deletedRows = DBManager::executeNonQuery($deleteSql, [':id' => $lastId]);
//     //     echo "\nDeleted {$deletedRows} row(s) with ID {$lastId}.\n";
//     // }


// } catch (PDOException $e) {
//     echo "Database operation failed: " . $e->getMessage() . "\n";
//     // In a real application, log the error details rather than echoing them to the user.
// } catch (Exception $e) {
//     echo "An unexpected error occurred: " . $e->getMessage() . "\n";
// }

*/

?>
