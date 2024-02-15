<?php

namespace MagratheaImages3;

use AuthApi;
use Magrathea2\Config;
use Magrathea2\MagratheaApi;
use MagratheaImages3\Apikey\ApikeyApi;
use MagratheaImages3\Images\ImagesApi;

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
		]);
		$this->SetAuth();
		$this->SetUrl();
		$this->AddApikey();
		$this->AddImages();
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
	}

	private function AddImages() {
		$api = new ImagesApi();
		$this->Add("POST", "upload", $api, "Upload", self::OPEN);
		$this->Add("POST", "upload-url", $api, "Upload", self::OPEN);
		$this->Add("POST", "key/:key/upload", $api, "Upload", self::OPEN);
		$this->Add("POST", "key/:key/upload-url", $api, "Upload", self::OPEN);
		$this->Add("GET", "image/:id/details", $api, "ViewImageDetails", self::OPEN);
		$this->Add("GET", "image/:id", $api, "ViewImage", self::OPEN);
		$this->Add("GET", "image/:id/x/:size", $api, "ViewImage", self::OPEN);
		$this->Add("GET", "image/:id/raw", $api, "ViewRaw", self::OPEN);
		$this->Add("GET", "image/:id/thumb", $api, "ViewThumb", self::OPEN);
		$this->Add("GET", "image/:id/preview/:size", $api, "Preview", self::OPEN, "Gets the image in the given size without saving it");
		$this->Add("GET", "image/:id/debug/:size", $api, "DebugResize", self::OPEN);
	}

}
