<?php

use Magrathea2\Admin\AdminManager;

include("_inc.php");
include("ImagesAdmin.php");

try {
	AdminManager::Instance()->Start(new ImagesAdmin());
} catch(Exception $ex) {
	\Magrathea2\p_r($ex);
}
