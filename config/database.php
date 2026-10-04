<?php
// ============================================================
// config/database.php
//
// WHAT THIS FILE DOES:
//   Creates ONE database connection and reuses it everywhere.
//   This pattern is called a "Singleton" — only one connection
//   object is ever created, no matter how many times you call
//   Database::getInstance().
//
// WHY PDO?
//   PDO (PHP Data Objects) lets us use "prepared statements"
//   which protect against SQL Injection attacks. Never build
//   SQL queries by gluing user input directly into a string.
// ============================================================

class Database {
  // Holds the single PDO connection object.
  // 'static' means it belongs to the class, not an instance.
  // '?PDO' means it can be null (before first connection) or a PDO object.
  private static ?PDO $instance = null;

  // Private constructor prevents anyone from doing: new Database()
  // They MUST use Database::getInstance() instead.
  private function __construct() {
  }

  /**
   * Returns the shared PDO connection.
   * Creates it on the first call; returns the existing one after that.
   */
  public static function getInstance(): PDO {
    if (self::$instance === null) {
      // DSN = Data Source Name — tells PDO what to connect to
      $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        DB_CHARSET
      );

      try {
        self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
          // Show errors as exceptions (easier to debug)
          PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
          // Return rows as associative arrays: $row['column_name']
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          // IMPORTANT: disable emulated prepares for real security
          PDO::ATTR_EMULATE_PREPARES   => false,
          // Strict mode — prevents silent data truncation
          PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
      } catch (PDOException $e) {
        // In production: log the error, show a friendly message.
        // NEVER show the raw error to users — it reveals your DB structure.
        error_log('Database connection failed: ' . $e->getMessage());
        die('A database error occurred. Please contact the administrator.');
      }
    }

    return self::$instance;
  }

  // Prevent cloning the singleton
  private function __clone() {
  }
}
