<?php

namespace MagratheaImages3\Apikey;

use Exception;
use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\iAdminFeature;

class ApikeyAdmin extends AdminFeature implements iAdminFeature { 

	public string $featureName = "Api Keys";
	public string $featureId = "AdminApikey";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
		$this->AddJs(__DIR__."/admin/scripts.js");
		$this->AddCSS(__DIR__."/admin/styles.css");
	}

	public function HasEditPermission($user): bool {
		$loggedUser = AdminManager::Instance()->GetLoggedUser();
		return !empty($loggedUser->id);
	}

	public function GetPage() {
		include("admin/index.php");
	}

	public function ReturnError($err) {
		echo json_encode(["success" => false, "error" => $err]);
		die;
	}

	public function List() {
		$control = new ApikeyControl();
		$list = $control->GetAll();		
		include("admin/list.php");
	}

	public function Form() {
		$id = @$_GET["id"];
		$key = new Apikey($id);
		$control = new ApikeyControl();
		$control->initializeKeys($key);
		include("admin/form.php");
	}

	public function Create() {
		$id = @$_POST["id"];
		$control = new ApikeyControl();
		try {
			if(!empty($id)) {
				$k = $control->Update($id, $_POST);
			} else {
				$k = $control->Create($_POST);
			}
		} catch(Exception $ex) {
			echo json_encode([
				"success" => false,
				"error" => $ex->getMessage(),
			]);
			die;
		}
		echo json_encode([
			"success" => true,
			"data" => $k,
			"type" => ($id ? "update" : "insert")
		]);
	}

	public function Cache() {
		include("admin/cache.php");
	}

	public function CreateCache() {
		$control = new CacheClassCreator();
		$elements = AdminElements::Instance();
		if($control->Generate()) {
			$elements->Alert("File generated!", "success");
		} else {
			$elements->Alert("Error creating file!", "danger");
		}
		return $this->Cache();
	}

}
