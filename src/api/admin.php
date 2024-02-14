<?php

include("_inc.php");
include("admin/MagratheaImagesAdmin.php");

use Magrathea2\Admin\AdminManager;
use MagratheaImages3\MagratheaImagesAdmin;

try {
	AdminManager::Instance()->Start(new MagratheaImagesAdmin());
} catch(Exception $ex) {
	\Magrathea2\p_r($ex);
}
