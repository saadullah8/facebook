<?php

/**
 * Database Functions File
 * DO NOT define constants here - they're already defined in config.php
 * This file only contains functions
 */

/**
 * Establish database connection using PDO
 */
function getDatabaseConnection()
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            // Simple error message
            die("Database Connection Error: " . $e->getMessage());
        }
    }

    return $pdo;
}

function db()
{
    return getDatabaseConnection();
}

/**
 * Sanitize input data
 */
function sanitize($data)
{
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }

    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

    return $data;
}

/**
 * Generate CSRF token
 */
function generateCsrfToken()
{
    // Session is already started in config.php
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}


