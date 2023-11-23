<?php

namespace MagratheaImages3\Images;

use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;
use MagratheaImages3\Apikey\ApikeyControl;

class ImagesApi extends MagratheaApiControl {

	public function __construct() {
		$this->model = get_class(new Images());
		$this->service = new ImagesControl();
	}

	public function GetById($params) {
		try {
			$id = $params["id"];
			$img = new Images($id);
			if(empty($img->name)) throw new MagratheaApiException("Image not found", true, 404, $params);
			return $img;
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	public function ViewImageDetails($params) {
		$key = @$_GET["key"];
		if(empty($key)) throw new MagratheaApiException("Param [key] for upload key is empty", true, 400);
		try {
			$image = $this->GetById($params);
			if($image->upload_key != $key) throw new MagratheaApiException("Invalid key [".$key."]", true, 400, $key);
			return $image;
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	public function ViewImage($params) {
		try {
			$image = $this->GetById($params);
			$viewer = new ImageViewer($image);
			return $viewer->ViewRaw();
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	public function ViewRaw($params) {
		try {
			$image = $this->GetById($params);
			$viewer = new ImageViewer($image);
			return $viewer->ViewRaw();
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	public function ViewThumb($params) {
		try {
			$image = $this->GetById($params);
			$viewer = new ImageViewer($image);
			return $viewer->ViewThumb();
		} catch(\Exception $e) {
			throw new MagratheaApiException($e->getMessage(), true, $e->getCode(), $e);
		}
	}

	private function GetApiKeyByPost($post) {
		$keyControl = new ApikeyControl();
		$key = @$post["key"];
		if(empty($key)) {
			throw new MagratheaApiException("Api Key cannot be empty", true, 500, $post);
		}
		$apiK = $keyControl->GetByKey($key);
		if(empty($apiK->id)) {
			throw new MagratheaApiException("Api Key is invalid: [".$key."]", true, 500, $post);
		}
		return $apiK;
	}

	public function Upload($params) {
		$post = $this->GetPost();
		$uploader = new ImageUploader();
		try {
			$key = $this->GetApiKeyByPost($post);
			$uploader->SetKey($key);
			if(empty($_FILES)) {
				throw new MagratheaApiException("File not received", true, 500, $params);
			}
			$uploader->SetFile($_FILES["file"]);
			return $uploader->Upload();
		} catch(\Exception $e) {
			throw $e;
		}
	}

}