<?php
// Bootstrap DB credentials for the live server.
// admin-secrets.local.php (gitignored) takes priority — create it for local dev.
// On the live server this file provides the fallback credentials.
//
// Live server uses localhost (MySQL on same host as Apache).
// Local dev: copy to admin-secrets.local.php and change host to promanaged-it.com.

return [
    'MOTORLINK_DB_HOST' => 'localhost',
    'MOTORLINK_DB_USER' => 'p601229',
    'MOTORLINK_DB_PASS' => '2:p2WpmX[0YTs7',
    'MOTORLINK_DB_NAME' => 'p601229_motorlinkmalawi_db'
];
