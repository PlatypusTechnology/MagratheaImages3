<?php

namespace MagratheaImages3\Images;

use Magrathea2\Config;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;
use MagratheaImages3\Apikey\ApikeyControl;

class ImagesApi extends MagratheaApiControl {

	private bool $isSecure = false;

	public function __construct() {
		$this->model = get_class(new Images());
		$this->service = new ImagesControl();
		$this->isSecure = boolval(Config::Instance()->Get("secure_api"));
	}

	public function GetById($params) {
		try {
			$id = $params["id"];
			$img = new Images($id);
			if(empty($img->name)) throw new MagratheaApiException("Image not found", true, 404, $params);
			if($this->isSecure) {
				$key = @$params["key"];
				if(empty($key)) throw new MagratheaApiException("Key is invalid");
				$keyControl = new ApikeyControl();
				$imgKey = $keyControl->GetCached($img->upload_key);
				if($imgKey != $key) throw new MagratheaApiException("Key-Image relation invalid");
			}
			return $img;
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	public function ViewImageDetails($params) {
		try {
			$image = $this->GetById($params);
			unset($image->placeholder);
			return $image;
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	public function GetWidthHeight($sizes=null): array {
		if($sizes == null) {
			$width = @$_GET["w"] ? $_GET["w"] : @$_GET["width"];
			$height = @$_GET["h"] ? $_GET["h"] : @$_GET["height"];
		} else {
			$pieces = explode('x', $sizes);
			$width = @$pieces[0];
			$height = @$pieces[1];
		}
		return [
			'width' => $width,
			'height' => $height,
		];
	}

	public function ViewImage($params) {
		$size = @$params["size"];
		if(@$size == "raw") return $this->ViewRaw($params);
		if(!empty($size)) {
			$dimensions = $this->GetWidthHeight($size);
		} else {
			$dimensions = $this->GetWidthHeight(@$_GET["size"]);
		}
		if($dimensions["width"] == null || $dimensions["height"] == null) {
			return $this->ViewThumb($params);
		}
		$stretch = @$_GET["stretch"] == '1';
		$placeholder = @$_GET["placeholder"] == '1';
		$forceGen = @$_GET["generate"] == '1';
		try {
			$image = $this->GetById($params);
			$viewer = new ImageViewer($image);
			if($placeholder) $viewer->Placeholder();
			if($forceGen) $viewer->ForceGeneration();
			$viewer->Size($dimensions["width"], $dimensions["height"], $stretch);
			return $viewer->ViewFile();
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	public function ViewRaw($params) {
		try {
			$image = $this->GetById($params);
			$viewer = new ImageViewer($image);
			return $viewer->Raw();
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	public function ViewThumb($params) {
		try {
			$image = $this->GetById($params);
			$viewer = new ImageViewer($image);
			$viewer->Thumb()->ViewFile();
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	private function GetApiKeyByValue($key) {
		$keyControl = new ApikeyControl();
		if(empty($key)) {
			throw new MagratheaApiException("Api Key cannot be empty", true, 500);
		}
		$apiK = $keyControl->GetByKey($key);
		if(empty($apiK->id)) {
			throw new MagratheaApiException("Api Key is invalid: [".$key."]", true, 500);
		}
		return $apiK;
	}

	public function Upload($params) {
		$post = $this->GetPost();
		if($params["key"]) {
			$keyVal = $params["key"];
		}	else {
			$keyVal = @$post["key"];
		}
		$url = @$post["url"];
		try {
			$key = $this->GetApiKeyByValue($keyVal);
			if($url) {
				return $this->UploadUrlWithKey($key, $url);
			} else {
				return $this->UploadWithKey($key);
			}
		} catch(\Exception $e) {
			throw $e;
		}
	}

	public function UploadWithKey($key) {
		$uploader = new ImageUploader();
		try {
			$uploader->SetKey($key);
			if(empty($_FILES)) {
				throw new MagratheaApiException("File not received", true, 500);
			}
			$uploader->SetFile($_FILES["file"]);
			return $uploader->Upload();
		} catch(\Exception $e) {
			throw $e;
		}
	}

	public function UploadUrlWithKey($key, string $url) {
		if(!filter_var($url, FILTER_VALIDATE_URL))
			throw new MagratheaApiException("not a valid url: [".$url."]");
		$uploader = new ImageUploader();
		try {
			$uploader->SetKey($key);
			return $uploader->UploadUrl($url);
		} catch(\Exception $e) {
			throw $e;
		}
	}

	public function Preview($params) {
		$size = @$params["size"];
		$size = strtolower($size);
		if(empty($size)) $size = "thumb";
		$debug = false;
		if(@$_REQUEST["debug"]) {
			$debug = true;
		}
		$placeholder = @$_GET["placeholder"] == '1';
		try {
			if($size == "raw") return $this->ViewRaw($params);

			$image = $this->GetById($params);
			$viewer = new ImageViewer($image);
			$viewer->DontSave();
			$viewer->ForceGeneration();
			if($debug) $viewer->Debug();
			if($placeholder) $viewer->Placeholder();

			if($size == "thumb") {
				$viewer->Thumb();
			} else {
				$stretch = false;
				if (@$_REQUEST["stretch"]) {
					$stretch = true;
				}
				$sizes = explode('x', $size);
				$viewer->Size($sizes[0], $sizes[1], $stretch);
			}
			if($debug) {
				return $viewer->GetResizerDebug();
			}
			$viewer->ViewGD();
		} catch(\Exception $ex) {
			throw new MagratheaApiException($ex->getMessage(), true, 500);
		}
	}

	public function Remove($params) {
		try {
			$key = @$params["key"];
			$id = @$params["id"];
			if(!$key || !$id) {
				throw new MagratheaApiException("empty data");
			}
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}

	}

}