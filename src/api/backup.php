<?php
// CLI entrypoint for the R2 backup module. `_inc.php` anchors its own paths on
// __DIR__ and connects the database; the chdir() keeps any other relative path
// resolving from src/api, as it does for the web server and phpunit (cron
// starts in $HOME).
chdir(__DIR__);
require __DIR__."/_inc.php";

use MagratheaImages3\R2\BackupRunner;
use MagratheaImages3\R2\R2Config;

$args = getopt("", ["push", "reconcile", "limit::", "dry-run", "verbose"]);
$verbose = isset($args["verbose"]);

if(!R2Config::IsEnabled()) {
	if($verbose) fwrite(STDOUT, "R2 backup disabled, nothing to do.\n");
	exit(0);
}

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
