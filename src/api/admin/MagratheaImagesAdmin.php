<?php

namespace MagratheaImages3;

include("api.php");
use Magrathea2\Tests\TestsManager;
use Magrathea2\Admin\Admin;
use Magrathea2\Admin\AdminMenu;
use Magrathea2\Admin\Features\UserLogs\AdminFeatureUserLog;
use Magrathea2\Admin\Features\ApiExplorer\ApiExplorer;
use Magrathea2\Admin\Features\AppConfig\AdminFeatureAppConfig;
use MagratheaImages3\Apikey\ApikeyAdmin;
use MagratheaImages3\Images\ImagesAdmin;
use MagratheaImages3\MediaAdmin;
use MagratheaImages3\GeneratedFileAdmin;
use MagratheaImages3\MagratheaImagesApi;

class MagratheaImagesAdmin extends Admin implements \Magrathea2\Admin\iAdmin {
	public function Initialize() {
		$this->SetTitle("Magrathea Images Admin");
		$this->SetPrimaryColor("#910e04");
		$this->AddJs(__DIR__."/scripts.js");
		$this->loadTest();
	}

	public function Auth($user): bool {
		return !empty($user->id);
	}

	public function LoadApi() {
		$api = new MagratheaImagesApi();
		$apiFeature = new ApiExplorer();
		$apiFeature->SetApi($api);
		$this->features["api"] = $apiFeature;
		$this->AddFeature($apiFeature);
	}

	private $features = [];
	public function SetFeatures(){
		parent::SetFeatures();
		$this->LoadApi();
		$this->features["appconfig"] = new AdminFeatureAppConfig(true);
		$this->features["apikey"] = new ApikeyAdmin();
		$this->features["log"] = new AdminFeatureUserLog();
		$this->LoadImagesAdmin();
		$this->AddFeaturesArray($this->features);
	}

	public function LoadImagesAdmin() {
		$this->features["medias"] = new MediaAdmin();
		$this->features["gen-files"] = new GeneratedFileAdmin();
		$this->features["images-crud"] = new ImagesAdmin();
	}

	public function loadTest() {
		$testManager = TestsManager::Instance();
		$testFolder = __DIR__."/../tests";
		$testManager->AddTest([ $testFolder ], "MagratheaImage");
	}

	public function BuildMenu(): AdminMenu {
		$menu = new AdminMenu();
		$menu
		->Add($this->features["medias"]->GetMenuItem())
		->Add($this->features["gen-files"]->GetMenuItem())
		->Add($this->features["images-crud"]->GetMenuItem())

		->Add($menu->CreateTitle("Settings"))
		->Add($this->features["apikey"]->GetMenuItem())
		->Add($this->features["appconfig"]->GetMenuItem())
		
		->Add($menu->CreateTitle("Api"))
		->Add($this->features["api"]->GetMenuItem())

		->Add($menu->CreateSpace())

		->Add(["title" => "Magrathea", "type" => "main" ]);
		$this->AddMagratheaMenu($menu);

		$menu->Add($menu->GetLogoutMenuItem());
		return $menu;
	}
}
