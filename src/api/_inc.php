<?php
// Shared bootstrap for every entry point (index.php, admin.php, magrathea.php, backup.php,
// the tests) and for the MagratheaPHP CLI (`vendor/bin/magrathea`, declared in composer.json's
// extra.magrathea.bootstrap). It prints nothing and catches nothing: each entry point handles
// its own errors, and the CLI discards any output with a warning.
// Every path is anchored on __DIR__, so it loads the same from any working directory (cron, CLI).

require __DIR__."/../vendor/autoload.php";

if(file_exists(__DIR__."/sentry.php")){
	include(__DIR__."/sentry.php");
}

require_once __DIR__."/shared/Helper.php";

Magrathea\MagratheaPHP::Instance()
	->MinVersion("3.0.0")
	->AppPath(realpath(__DIR__))
	->AddRootCodeFolder(
		"admin",
		"admin/GeneratedFileManager",
		"admin/MediaManager",
		"shared",
		"error-manager",
	)
	->AddFeature("Apikey", "Images")
	->AddFeature("R2")
	// ->Debug()
	// ->Dev()
	->Load()
	->Connect();
