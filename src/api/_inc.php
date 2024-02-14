<?php

require "../vendor/autoload.php";
include("shared/Helper.php");

try {
	Magrathea2\MagratheaPHP::Instance()
		->AppPath(realpath(dirname(__FILE__)))
		->AddCodeFolder(
			"admin",
			"admin/GeneratedFileManager",
			"admin/MediaManager"
		)
		->AddFeature("Apikey", "Images")
//		->Debug()
//		->Dev()
//		->StartDB()
		->Load();
} catch(Exception $ex) {
	\Magrathea2\p_r($ex);
}
