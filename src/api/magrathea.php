<?php

include("_inc.php");

use Magrathea2\Admin\Admin;
use Magrathea2\Admin\AdminManager;

\Magrathea2\MagratheaPHP::Instance()->Dev();

try {
	$admin = new Admin();
	$admin->SetTitle("Magrathea Images");
	$admin->AddMenuItem(
		["title" => "Links", "type" => "sub"],
		["title" => "Admin", "link" => "/admin.php"]
	);
	AdminManager::Instance()->Start($admin);
} catch(Exception $ex) {
	\Magrathea2\p_r($ex);
}
