<?php

namespace MagratheaImages3;

use AuthApi;
use Magrathea2\Config;
use Magrathea2\ConfigApp;
use Magrathea2\DB\Database;
use Magrathea2\Exceptions\MagratheaApiException;
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
		$this->SetUrl();
		$this->AddApikey();
		$this->AddImages();
		$this->GeneralApis();
	}

	private function GeneralApis() {
		$this->Version();
		$this->HealthCheck(true);
		$this->Add("POST", "clean", null, function($params) {
			$q = @$_POST["q"];
			return Helper::Clean($q);
		}, self::OPEN);
		$this->Add("GET", "test-sentry", null, function($params) {
			throw new MagratheaApiException("Test exception for Sentry", 500);
		}, self::OPEN);
		$this->Add("GET", "settings", null, function($params) {
			$upload = ImageUploader::getMaximumFileUploadSize();
			return[
				"thumb_size" => ConfigApp::Instance()->Get("thumb_size"),
				"upload_limit_bytes" => $upload,
				"upload_limit" => MagratheaHelper::FormatSize($upload),
				"force_uuid" => boolval(Config::Instance()->Get("force_uuid")),
			];
		}, self::OPEN);
		$this->Add("GET", "changelog", new \MagratheaImages3\SystemApi(), "GetChangelog", self::OPEN);
		$this->Add("GET", "error-codes", new \MagratheaImages3\SystemApi(), "GetErrorCodes", self::OPEN);
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
		$this->Add("GET", "key/:private_key/view", $api, "GetByKey", self::OPEN);
		$this->Add("GET", "key/:private_key/images", $api, "ViewImages", self::OPEN, "GET: subfolder=?");
		$this->Add("GET", "key/:private_key/cached", $api, "GetCached", self::LOGGED);
		$this->Add("POST", "key/create", $api, "NewKey", self::OPEN);
	}

	private function AddImages() {
		$api = new ImagesApi();
		$this->Add("POST", "upload", $api, "Upload", self::OPEN);
		$this->Add("POST", "upload-url", $api, "Upload", self::OPEN, "post: [private_key], [url]");
		$this->Add("POST", "key/:private_key/upload", $api, "Upload", self::OPEN);
		$this->Add("POST", "key/:private_key/upload-url", $api, "Upload", self::OPEN, "(private_key) post: [url]");
		$this->Add("DELETE", "key/:private_key/delete/:id", $api, "Remove", self::OPEN);
		$this->SecureImages();
	}

	// gets: generate, placeholder, stretch
	private function SecureImages() {
		$api = new ImagesApi();
		$this->Add("GET", "image/:public_key/:id/details", $api, "ViewImageDetails", self::OPEN);
		$this->Add("GET", "image/:public_key/:id", $api, "ViewImage", self::OPEN);
		$this->Add("GET", "image/:public_key/:id/x/:size", $api, "ViewImage", self::OPEN);
		$this->Add("GET", "image/:public_key/:id/raw", $api, "ViewRaw", self::OPEN);
		$this->Add("GET", "image/:public_key/:id/thumb", $api, "ViewThumb", self::OPEN);
		$this->Add("GET", "image/:public_key/:id/preview/:size", $api, "Preview", self::OPEN, "Gets the image in the given size without saving it");
	}

	public function ReturnApiException($exception) {
		$exCode = $exception->getCode();
		if($exCode >= 100 && $exCode <= 599) {
			$httpStatus = $exCode;
		} else if($exCode >= 1000 && $exCode <= 9999) {
			$httpStatus = intval($exCode / 10);
		} else {
			$httpStatus = 500;
		}
		if($httpStatus >= 500 && function_exists("Sentry\\captureException")) {
			\Sentry\captureException($exception);
		}
		return parent::ReturnApiException($exception);
	}

	public function ReturnError($code = 500, $message = "", $data = null, $status = 200) {
		if($code >= 500 && function_exists("Sentry\\captureMessage")) {
			\Sentry\captureMessage($message ?: "Unknown API error (code {$code})", \Sentry\Severity::error());
		}
		return parent::ReturnError($code, $message, $data, $status);
	}

	private function Version() {
		$this->Add("GET", "version", null, function($params) {
			$configRoot = MagratheaPHP::Instance()->getConfigRoot();
			require $configRoot."/version.php";
			return [
				"api" => "Magrathea Images 3",
				"version" => MagratheaPHP::Instance()->AppVersion(),
				"environment" => Config::Instance()->GetEnvironment(),
				...$version,
				"magrathea_version" => MagratheaPHP::Instance()->Version(),
			];
		}, self::OPEN);
	}

}
