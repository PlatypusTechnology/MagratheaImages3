<?php

namespace MagratheaImages3;

use AuthApi;
use Magrathea2\Config;
use Magrathea2\ConfigApp;
use Magrathea2\MagratheaApi;
use Magrathea2\MagratheaHelper;
use Magrathea2\MagratheaPHP;
use MagratheaImages3\Apikey\ApikeyApi;
use MagratheaImages3\Images\ImagesApi;
use MagratheaImages3\Images\ImageUploader;

class MagratheaImagesApi extends MagratheaApi {

	public $authApi = null;
	const OPEN = false;
	const LOGGED = "IsLogged";
	const ADMIN = "IsAdmin"; //

	public function __construct() {
		$this->Initialize();
	}
	public function Initialize() {
		\Magrathea2\MagratheaPHP::Instance()->StartDb();
		$this->AllowAll();
		$this->AddAcceptHeaders([
			"Authorization",
			"Access-Control-Allow-Origin",
			"cache-control",
			"x-requested-with",
			"Content-type",
			"pragma", "expires",
		]);
		$this->DisableCache();
		$this->SetAuth();
		$this->Version();
		$this->SetUrl();
		$this->AddApikey();
		$this->AddImages();
		$this->Add("POST", "clean", null, function($params) {
			$q = @$_POST["q"];
			return Helper::Clean($q);
		}, self::OPEN);
		$this->Add("GET", "settings", null, function($params) {
			$upload = ImageUploader::getMaximumFileUploadSize();
			return[
				"thumb_size" => ConfigApp::Instance()->Get("thumb_size"),
				"secure" => Config::Instance()->Get("secure_api"),
				"upload_limit_bytes" => $upload,
				"upload_limit" => MagratheaHelper::FormatSize($upload),
			];
		}, self::OPEN);
	}

	private function SetAuth() {
		$authApi = new \Magrathea2\MagratheaApiAuth();
		$this->BaseAuthorization($authApi, self::LOGGED);
		$this->Add("GET", "token", $authApi, "GetTokenInfo", self::OPEN);
//		$this->Add("POST", "login", $authApi, "Login", self::OPEN);
	}

	private function SetUrl() {
		$url = Config::Instance()->Get("app_url");
		$this->SetAddress($url);
	}

	private function AddApikey() {
		$api = new ApikeyApi();
		$this->Add("GET", "keys", $api, "GetAll", self::LOGGED);
		$this->Add("GET", "key/:key/view", $api, "GetByKey", self::OPEN);
		$this->Add("GET", "key/:key/images", $api, "ViewImages", self::OPEN);
		$this->Add("GET", "key/:public_key/cached", $api, "GetCached", self::LOGGED);
		$this->Add("POST", "key/create", $api, "NewKey", self::OPEN);
	}

	private function AddImages() {
		$api = new ImagesApi();
		$this->Add("POST", "upload", $api, "Upload", self::OPEN);
		$this->Add("POST", "upload-url", $api, "Upload", self::OPEN);
		$this->Add("POST", "key/:key/upload", $api, "Upload", self::OPEN);
		$this->Add("POST", "key/:key/upload-url", $api, "Upload", self::OPEN);
		$this->Add("DELETE", "key/:key/delete/:id", $api, "Remove", self::OPEN);
		if(Config::Instance()->Get("secure_api")) {
			$this->SecureImages();
		} else {
			$this->PublicImages();
		}
	}

	// gets: generate, placeholder, stretch
	private function PublicImages() {
		$api = new ImagesApi();
		$this->Add("GET", "image/:id/details", $api, "ViewImageDetails", self::OPEN);
		$this->Add("GET", "image/:id", $api, "ViewImage", self::OPEN);
		$this->Add("GET", "image/:id/x/:size", $api, "ViewImage", self::OPEN);
		$this->Add("GET", "image/:id/raw", $api, "ViewRaw", self::OPEN);
		$this->Add("GET", "image/:id/thumb", $api, "ViewThumb", self::OPEN);
		$this->Add("GET", "image/:id/preview/:size", $api, "Preview", self::OPEN, "Gets the image in the given size without saving it");
		$this->Add("GET", "image/:id/debug/:size", $api, "DebugResize", self::OPEN);
	}
	private function SecureImages() {
		$api = new ImagesApi();
		$this->Add("GET", "image/:key/:id/details", $api, "ViewImageDetails", self::OPEN);
		$this->Add("GET", "image/:key/:id", $api, "ViewImage", self::OPEN);
		$this->Add("GET", "image/:key/:id/x/:size", $api, "ViewImage", self::OPEN);
		$this->Add("GET", "image/:key/:id/raw", $api, "ViewRaw", self::OPEN);
		$this->Add("GET", "image/:key/:id/thumb", $api, "ViewThumb", self::OPEN);
		$this->Add("GET", "image/:key/:id/preview/:size", $api, "Preview", self::OPEN, "Gets the image in the given size without saving it");
	}

	private function Version() {
		$this->Add("GET", "version", null, function($params) {
			$configRoot = MagratheaPHP::Instance()->getConfigRoot();
			require $configRoot."/version.php";
			return [
				"api" => "Magrathea Images 3",
				"version" => MagratheaPHP::Instance()->AppVersion(),
				"secure" => Config::Instance()->Get("secure_api"),
				"environment" => Config::Instance()->GetEnvironment(),
				...$version,
				"magrathea_version" => MagratheaPHP::Instance()->Version(),
			];
		}, self::OPEN);
	}

}
