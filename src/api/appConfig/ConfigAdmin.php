<?php

namespace MagratheaImages3\AppConfig;

use Exception;
use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\iAdminFeature;
use MagratheaContacts\Source\SourceControl;

class ConfigAdmin extends AdminFeature implements iAdminFeature { 

	public string $featureName = "App Configuration";
	public string $featureId = "AdminConfig";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
		$this->AddJs(__DIR__."/admin/scripts.js");
	}

	public function GetPage() {
		include("admin/index.php");
	}

	public function ReturnError($err) {
		echo json_encode(["success" => false, "error" => $err]);
		die;
	}

}
