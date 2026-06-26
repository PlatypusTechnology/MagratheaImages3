<?php

namespace MagratheaImages3\Apikey;

use Exception;
use Magrathea2\ConfigApp;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;
use MagratheaImages3\Apikey\Apikey;
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
			throw new MagratheaApiException("key is empty", true, 404, $val);
		}
		$key = $this->service->GetByKey($val);
		if(empty($key)) {
			throw new MagratheaApiException("key [".$val."] does not exists", true, 404, $val);
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
		if(!$secret) throw new MagratheaApiException("Magrathea Images setup not complete");
		if(@$_POST["secret"] != $secret) throw new MagratheaApiException("invalid secret for key creation");
		try {
			$k = $this->service->Create($_POST);
		} catch(Exception $ex) {
			throw new MagratheaApiException($ex->getMessage(), 500);
		}
		return $k;
	}

}
