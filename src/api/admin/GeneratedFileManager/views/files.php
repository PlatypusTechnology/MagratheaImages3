<?php

use Magrathea2\Admin\AdminElements;
use MagratheaImages3\Images\FileManager;
use MagratheaImages3\Images\ImageUploader;

$elements = AdminElements::Instance();
$control = new FileManager();

echo "<br/>";

try {
	$uploadControl = new ImageUploader();
	$extensions = $uploadControl->extensions;
	$control->SetPath($folderExplore)->AllowedExtensions($extensions);
	$files = $control->GetFiles();
} catch(\Exception $ex) {
	$elements->Alert("error loading file", "danger", true);
	die;
}

if(empty($files)) {
	$elements->Alert("no files in folder", "warning", true);
}

echo "<ul>";
foreach ($files as $f) {
	$filenamePieces = explode("/", $f);
	$filename = end($filenamePieces);
	echo "<li class='li-file' onclick='viewImage(\"".$folder."\", \"".$filename."\");'>";
	echo $filename;
	echo "</li>";
}
echo "</ul>";

