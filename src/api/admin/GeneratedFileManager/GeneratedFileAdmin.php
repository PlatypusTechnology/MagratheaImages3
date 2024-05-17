<?php

namespace MagratheaImages3;

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\AdminUrls;
use Magrathea2\Admin\iAdminFeature;
use MagratheaImages3\Apikey\Apikey;
use MagratheaImages3\Images\FileManager;
use MagratheaImages3\Images\ImageViewer;
use MagratheaImages3\Images\PathManager;

class GeneratedFileAdmin extends AdminFeature implements iAdminFeature {
	public string $featureName = "Generated Files";
	public string $featureId = "AdminGenerated";

	public function __construct() {
		parent::__construct();
		$this->AddJs(__DIR__."/views/scripts.js");
		$this->AddCSS(__DIR__."/views/styles.css");
	}

	public function Index() {
		include("views/index.php");
	}

	public function Folder() {
		$id = $_GET["folder_id"];
		$apikey = new Apikey($id);
		$folder = $apikey->GetDestinationFolder();
		include("views/folder.php");
	}

	public function Files() {
		$id = $_POST["apikey"];
		$apikey = new Apikey($id);
		$mediaF = $apikey->GetDestinationFolder();
		$folder = $_POST["folder"];
		$folderExplore = $mediaF.$folder;
		include("views/files.php");
	}

	public function Preview() {
		$apikey = @$_POST["apikey"];
		$name = @$_POST["image"];
		$folder = @$_POST["folder"];
		$imgUrl = $this->generateDirectFileUrl($apikey, $name, $folder);
		if(empty($apikey) || empty($name)) {
			die("error: empty data for image");
		}
		$showDelete = ($folder == "generated");
		include("views/preview.php");
	}

	private function generateDirectFileUrl($apikey, $image, $folder) {
		$qs = [
			"apikey" => $apikey,
			"image" => $image,
			"folder" => $folder,
		];
		return strtok($_SERVER["REQUEST_URI"], '?').AdminUrls::Instance()->GetFeatureActionUrl($this->featureId, "View", $qs);
	}

	public function View() {
		$apikey = @$_GET["apikey"];
		$name = @$_GET["image"];
		$folder = @$_GET["folder"];
		$apikey = new Apikey($apikey);
		$folder = $apikey->GetDestinationFolder()."/".@$_GET["folder"];
		$path = $folder."/".$name;
		ImageViewer::ViewFromAbsolute($path);
	}

	public function DeleteFile() {
		$apikey = $_POST["apikey"];
		$file = "generated/".$_POST["file"];

		try {
			$manager = new FileManager();
			$manager->SetApiKeyId($apikey);
			$rs = $manager->DeleteFile($file);
		} catch(\Exception $ex) {
			AdminElements::Instance()->Alert($ex->getMessage(), 'danger');
		}
		return $rs;
	}
}

