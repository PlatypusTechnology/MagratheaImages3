<?php

use Magrathea\Admin\AdminManager;
use MagratheaImages3\MagratheaImagesAdmin;

try {
	require_once __DIR__."/_inc.php";
	require_once __DIR__."/admin/MagratheaImagesAdmin.php";
	AdminManager::Instance()->Start(new MagratheaImagesAdmin());
} catch(\Throwable $ex) {
	// never print the exception: it carries paths, SQL and config values
	if(function_exists("Sentry\\captureException")) {
		\Sentry\captureException($ex);
	}
	error_log((string) $ex);
	http_response_code(500);
	echo "Internal Server Error";
}
