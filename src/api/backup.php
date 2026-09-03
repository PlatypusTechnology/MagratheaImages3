<?php
// CLI entrypoint for the R2 backup module. Must run with cwd = src/api --
// `_inc.php`'s includes (../vendor/autoload.php, sentry.php, shared/Helper.php)
// are relative to the working directory, which cron does not set the way the
// web server and phpunit do.
chdir(__DIR__);
require "_inc.php";

use MagratheaImages3\R2\BackupRunner;
use MagratheaImages3\R2\R2Config;

$args = getopt("", ["push", "reconcile", "limit::", "dry-run", "verbose"]);
$verbose = isset($args["verbose"]);

if(!R2Config::IsEnabled()) {
	if($verbose) fwrite(STDOUT, "R2 backup disabled, nothing to do.\n");
	exit(0);
}

\Magrathea2\MagratheaPHP::Instance()->StartDB();

$runner = new BackupRunner();

if(isset($args["push"])) {
	$limit = isset($args["limit"]) ? intval($args["limit"]) : null;
	$result = $runner->Push($limit, $verbose);
	if($verbose) fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT)."\n");
	exit((($result["failed"] ?? 0) > 0) ? 1 : 0);
} else if(isset($args["reconcile"])) {
	$result = $runner->Reconcile(isset($args["dry-run"]));
	fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT)."\n");
	exit(!empty($result["aborted"]) ? 1 : 0);
} else {
	fwrite(STDERR, "Usage: php backup.php --push [--limit=N] [--verbose]\n");
	fwrite(STDERR, "       php backup.php --reconcile [--dry-run]\n");
	exit(1);
}
