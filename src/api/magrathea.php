<?php

use Magrathea\Admin\Admin;
use Magrathea\Admin\AdminManager;

try {
	require_once __DIR__."/_inc.php";
	\Magrathea\MagratheaPHP::Instance()->Dev();
	$admin = new Admin();
	$admin->SetTitle("Magrathea Images");
	$admin->AddMenuItem(
		["title" => "Links", "type" => "sub"],
		["title" => "Admin", "link" => "/admin.php"]
	);
	AdminManager::Instance()->Start($admin);
} catch(\Throwable $ex) {
	// never print the exception: it carries paths, SQL and config values
	if(function_exists("Sentry\\captureException")) {
		\Sentry\captureException($ex);
	}
	error_log((string) $ex);
	http_response_code(500);
	echo "Internal Server Error";
}
