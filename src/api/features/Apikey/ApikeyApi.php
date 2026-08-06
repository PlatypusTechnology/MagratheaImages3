<?php

namespace MagratheaImages3\Apikey;

use Exception;
use Magrathea2\ConfigApp;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;
use MagratheaImages3\Apikey\Apikey;
use MagratheaImages3\ErrorCodes;
use MagratheaImages3\Images\ImagesControl;

use function Magrathea2\now;

class ApikeyApi extends MagratheaApiControl {

	public function __construct() {
		$this->model = get_class(new Apikey());
		$this->service = new ApikeyControl();
	}

	private function _GetKey($params): Apikey {
		$val = $params["private_key"];
		if(empty($val)) {
			ErrorCodes::Instance()->ThrowException(4005);
		}
		$key = $this->service->GetByKey($val);
		if(empty($key)) {
			ErrorCodes::Instance()->ThrowException(4042, null, $val);
		}
		return $key;
	}

	public function GetByKey($params) {
		return $this->_GetKey($params);
	}

	public function GetAll($params) {
		return $this->service->GetAll();
	}

	public function ViewImages($params) {
		$key = $this->_GetKey($params);
		$count = @$_GET["count"] ? intval($_GET["count"]) : 12;
		$page = intval(@$_GET["page"]);
		$subfolder = @$_GET["subfolder"];
		$imageControl = new ImagesControl();
		$imgs = $imageControl->GetLast($key->id, $page, $count, $subfolder);
		return [
			"private_key" => $key->private_key,
			"public_key" => $key->public_key,
			"page" => $page,
			"images" => $imgs,
			"has_more" => (count($imgs) == $count),
			"timestamp" => now(),
		];
	}

	public function GetCached($params) {
		$key = $params["private_key"];
		return $this->service->GetCached($key);

	}

	// {"secret":<< api-secret >>, "folder":<< folder-name >>}
	public function NewKey($params) {
		$secret = ConfigApp::Instance()->Get("secret");
		if(!$secret) ErrorCodes::Instance()->ThrowException(5002, null, "Magrathea Images setup not complete");
		if(@$_POST["secret"] != $secret) ErrorCodes::Instance()->ThrowException(4011);
		try {
			$k = $this->service->Create($_POST);
		} catch(MagratheaApiException $ex) {
			throw $ex;
		} catch(Exception $ex) {
			ErrorCodes::Instance()->ThrowException(5001, null, $ex->getMessage());
		}
		return $k;
	}

}
