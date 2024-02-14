<?php

namespace MagratheaImages3;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\iAdminFeature;
use Magrathea2\Config;
use MagratheaImages3\Apikey\Apikey;
use MagratheaImages3\Images\Images;

class MediaAdmin extends AdminFeature implements iAdminFeature {
	public string $featureName = "Medias";
	public string $featureId = "AdminMedia";

	public function __construct() {
		parent::__construct();
		$this->AddJs(__DIR__."/views/scripts.js");
		$this->AddCSS(__DIR__."/views/styles.css");
	}

	public function Index() {
		include("views/index.php");
	}

	public function Home() {
		$apiKeyId = $_GET["apikey"];
		$apikey = new Apikey($apiKeyId);
		$images = $apikey->GetImages();
		include("views/home.php");
	}

	public function ViewImage() {
		$id = $_GET["id"];
		$apiUrl = Config::Instance()->Get("app_url");
		$api = "/image/".$id;
		$imgApi = $apiUrl.$api."/thumb";
		$image = new Images($id);
		include("views/view-image.php");
	}

	public function Preview() {
		$id = $_GET["id"];
		$size = $_GET["size"];
		$api = Config::Instance()->Get("app_url")."/image/".$id."/preview/".$size;
		include("views/preview.php");
	}

	public function Upload() {
		$id = $_GET["apikey"];
		$apikey = new Apikey($id);
		$key = $apikey->GetKey();
		$api = "/key/".$key."/upload";
		$uploadApi = Config::Instance()->Get("app_url").$api;
		include("views/uploader.php");
	}

}

