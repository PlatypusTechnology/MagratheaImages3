<?php

include("api.php");
use Magrathea2\Admin\Admin;
use Magrathea2\Admin\AdminMenu;
use Magrathea2\Admin\Features\UserLogs\AdminFeatureUserLog;
use Magrathea2\Admin\Features\ApiExplorer\ApiExplorer;
use MagratheaImages3\AppConfig;
use MagratheaImages3\Apikey\ApikeyAdmin;
use MagratheaImages3\AppConfig\ConfigAdmin as AppConfigConfigAdmin;
use MagratheaImages3\MagratheaImagesApi;

class ImagesAdmin extends Admin implements \Magrathea2\Admin\iAdmin {
	public function Initialize() {
		$this->SetTitle("Magrathea Images Admin");
		$this->SetPrimaryColor("#910e04");
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
		$this->LoadApi();
		$this->features["appconfig"] = new AppConfigConfigAdmin();
		$this->features["apikey"] = new ApikeyAdmin();
		$this->features["log"] = new AdminFeatureUserLog();
		$this->AddFeaturesArray($this->features);
	}

	public function BuildMenu(): AdminMenu {
		$menu = new AdminMenu();
		$menu
		->Add($this->features["appconfig"]->GetMenuItem())
		->Add($this->features["apikey"]->GetMenuItem())
		
		->Add($menu->CreateTitle("Api"))
		->Add($this->features["api"]->GetMenuItem())

		->Add($menu->CreateSpace())

		->Add($menu->GetDebugSection())
		->Add($this->features["log"]->GetMenuItem())

		->Add($menu->CreateSpace())
		->Add($menu->GetHelpSection())

		->Add($menu->CreateTitle("Magrathea"))
		->Add($menu->GetItem("objects"))
		->Add(["title" => "Magrathea Admin", "link" => "/magrathea.php"])

		->Add($menu->GetLogoutMenuItem());
		return $menu;
	}
}
