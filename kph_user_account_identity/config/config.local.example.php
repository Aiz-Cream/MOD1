<?php
// Copy this file to config.local.php and fill in your credentials.
// This file is ignored by Git (see .gitignore) so your credentials are never committed.
// 
// For Azure MySQL (shared kph-database):
//   - Obtain credentials from your Azure portal
//   - Download the CA certificate from Azure → Connection security → CA certificate
//   - Place the certificate at config/ssl/DigiCertGlobalRootG2.crt.pem
//   - Set DB_ENV=azure (or configure below)
// 
// For local XAMPP development:
//   - Set DB_ENV=local (or configure below)
//   - Use your local MySQL credentials

return [
    // Environment selection: 'azure' or 'local'
    // Can also be set via DB_ENV environment variable
    // "env" => "azure",

    // ---- Azure MySQL Configuration (shared kph-database) ----
    // "host"    => "kamaera-ph-mysql.mysql.database.azure.com",
    // "port"    => 3306,
    // "dbname"  => "kph-database",
    // "user"    => "sqladmin",
    // "pass"    => "YOUR_AZURE_PASSWORD_HERE",
    // "charset" => "utf8mb4",
    // "ssl_ca"  => __DIR__ . '/ssl/DigiCertGlobalRootG2.crt.pem',

    // ---- Local XAMPP Configuration ----
    // "host"    => "localhost",
    // "port"    => 3306,
    // "dbname"  => "kph_user_account_identity",
    // "user"    => "root",
    // "pass"    => "",
    // "charset" => "utf8mb4",
    // "ssl_ca"  => "",
];