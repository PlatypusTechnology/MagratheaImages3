<?php

namespace MagratheaImages3\Apikey;

use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;
use MagratheaImages3\Apikey\Apikey;

class ApikeyApi extends MagratheaApiControl {

	public function __construct() {
		$this->model = get_class(new Apikey());
		$this->service = new ApikeyControl();
	}

	private function _GetKey($params): Apikey {
		$val = $params["key"];
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
		return $key->GetImages();
	}

}
