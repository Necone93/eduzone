<?php
// Kopiraj kao db_connect.php SAMO lokalno ili na hostingu.
// db_connect.php je iskljucen iz Git-a. Primer ne sadrzi stvarne pristupne podatke.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$servername = getenv('EDUZONE_DB_HOST') ?: 'localhost';
$username = getenv('EDUZONE_DB_USER') ?: 'YOUR_DATABASE_USER';
$password = getenv('EDUZONE_DB_PASSWORD') ?: 'YOUR_DATABASE_PASSWORD';
$dbname = getenv('EDUZONE_DB_NAME') ?: 'YOUR_DATABASE_NAME';
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('EduZone: database connection failed.');
    http_response_code(503);
    exit('Baza trenutno nije dostupna.');
}
