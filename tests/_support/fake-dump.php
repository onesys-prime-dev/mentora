<?php

/**
 * Stands in for the mysqldump binary in MysqldumpRunnerTest. Behaviour comes
 * from the FAKE_DUMP environment variable:
 *   ok      prints a few lines of SQL and exits 0
 *   fail    prints what the real tool might (including the password it was
 *           given in MYSQL_PWD) to stderr and exits 2
 */
$mode = getenv('FAKE_DUMP') ?: 'ok';

if ($mode === 'fail') {
    fwrite(STDERR, 'mysqldump: Got error: Access denied using password ' . getenv('MYSQL_PWD') . "\n");
    exit(2);
}

echo "-- fake dump\nCREATE TABLE things (id INT);\nINSERT INTO things VALUES (1);\n";
exit(0);
