<?php

namespace MagratheaImages3\Images;

use Magrathea2\Config;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\MagratheaApiControl;
use MagratheaImages3\Apikey\ApikeyControl;
use MagratheaImages3\ErrorCodes;

class ImagesApi extends MagratheaApiControl {

	public function __construct() {
		$this->model = get_class(new Images());
		$this->service = new ImagesControl();
	}

	public function LookForImage($params, $size) {
		if(@$_GET["generate"] == "1") return false;
		$quickAccess = boolval(Config::Instance()->Get("webp_quick_access"));
		if(!$quickAccess) return false;
		$id = $params["id"];
		$key = $params["public_key"];
		$imageName = $id."_".$size;
		if(@$_GET["stretch"] == '1') $imageName .= "-s";
		if(@$_GET["placeholder"] == '1') $imageName .= "_placeholder";
		ImageViewer::ViewQuickAccess($key, $imageName.".webp");
	}

	private function ResolveImage($idOrUuid): ?Images {
		$forceUuid = boolval(Config::Instance()->Get("force_uuid"));
		$looksLikeUuid = (bool) preg_match('/^[0-9a-f-]{36}$/i', $idOrUuid);
		if ($forceUuid && !$looksLikeUuid) {
			ErrorCodes::Instance()->ThrowException(4004, null, $idOrUuid);
		}
		$image = $looksLikeUuid ? $this->service->GetByUuid($idOrUuid) : new Images($idOrUuid);
		if ($image) $image->accessId = $looksLikeUuid ? $image->uuid : (string) $image->id;
		return $image;
	}

	public function GetById($params) {
		try {
			$id = $params["id"];
			$img = $this->ResolveImage($id);
			if(empty($img) || empty($img->name)) ErrorCodes::Instance()->ThrowException(4043, $params);
			$key = @$params["public_key"];
			if(empty($key)) ErrorCodes::Instance()->ThrowException(400, null, "public key is missing");
			$keyControl = new ApikeyControl();
			$imgKey = $keyControl->GetCached($key);
			if(empty($imgKey)) ErrorCodes::Instance()->ThrowException(4041, $key);
			if($imgKey["id"] != $img->upload_key) ErrorCodes::Instance()->ThrowException(4032, $params);
			return $img;
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
		}
	}

	public function ViewImageDetails($params) {
		try {
			$image = $this->GetById($params);
			unset($image->placeholder);
			return $image;
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
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
		$s = $dimensions["width"]."x".$dimensions["height"];
		$this->LookForImage($params, $s);
		$stretch = @$_GET["stretch"] == '1';
		$placeholder = @$_GET["placeholder"] == '1';
		$forceGen = @$_GET["generate"] == '1';
		try {
			$image = $this->GetById($params);
			if(!$image->CanResize()) return $this->ViewRaw($params);
			$viewer = new ImageViewer($image);
			if($placeholder) $viewer->Placeholder();
			if($forceGen) $viewer->ForceGeneration();
			$viewer->Size($dimensions["width"], $dimensions["height"], $stretch);
			return $viewer->ViewFile();
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
		}
	}

	public function ViewRaw($params) {
		try {
			$image = $this->GetById($params);
			$viewer = new ImageViewer($image);
			return $viewer->Raw();
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
		}
	}

	public function ViewThumb($params) {
		$this->LookForImage($params, "thumb");
		$placeholder = @$_GET["placeholder"] == '1';
		$forceGen = @$_GET["generate"] == '1';
		try {
			$image = $this->GetById($params);
			if(!$image->CanResize()) return $this->ViewRaw($params);
			$viewer = new ImageViewer($image);
			if($placeholder) $viewer->Placeholder();
			if($forceGen) $viewer->ForceGeneration();
			$viewer->Thumb()->ViewFile();
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
		}
	}

	private function GetApiKeyByValue($key) {
		$keyControl = new ApikeyControl();
		if(empty($key)) {
			ErrorCodes::Instance()->ThrowException(4005);
		}
		$apiK = $keyControl->GetByKey($key);
		if(empty($apiK->id)) {
			ErrorCodes::Instance()->ThrowException(4042, null, $key);
		}
		$validation = $apiK->ValidateKey();
		if(!$validation["ok"]) {
			ErrorCodes::Instance()->ThrowException($validation["code"] ?? 403, null, $validation["data"]);
		}
		return $apiK;
	}

	public function Upload($params) {
		$post = $this->GetPost();
		if(@$params["private_key"]) {
			$keyVal = $params["private_key"];
		}	else {
			$keyVal = @$post["private_key"];
		}
		if(!$keyVal) {
			ErrorCodes::Instance()->ThrowException(4005);
		}
		$url = @$post["url"];
		try {
			$key = $this->GetApiKeyByValue($keyVal);
			$subfolder = @$post["subfolder"];
			if($url) {
				return $this->UploadUrlWithKey($key, $url, $subfolder);
			} else {
				return $this->UploadWithKey($key, $subfolder);
			}
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
		}
	}

	private function PostSizeExceeded(): bool {
		$contentLength = (int) (@$_SERVER["CONTENT_LENGTH"] ?? 0);
		return $contentLength > 0 && empty($_POST) && $contentLength > ImageUploader::getMaximumFileUploadSize();
	}

	public function UploadWithKey($key, ?string $subfolder=null) {
		$uploader = new ImageUploader();
		if($subfolder) $uploader->SetSubfolder($subfolder);
		try {
			$uploader->SetKey($key);
			if(empty($_FILES)) {
				if($this->PostSizeExceeded()) {
					$limit = \MagratheaImages3\Helper::GetSize(ImageUploader::getMaximumFileUploadSize());
					ErrorCodes::Instance()->ThrowException(4003, null, "maximum allowed size is {$limit}");
				}
				ErrorCodes::Instance()->ThrowException(4001, null, "file not received");
			}
			$uploader->SetFile($_FILES["file"]);
			return $uploader->Upload();
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
		}
	}

	public function UploadUrlWithKey($key, string $url, ?string $subfolder=null) {
		if(!filter_var($url, FILTER_VALIDATE_URL)) {
			ErrorCodes::Instance()->ThrowException(4006, null, $url);
		}
		$uploader = new ImageUploader();
		if($subfolder) $uploader->SetSubfolder($subfolder);
		try {
			$uploader->SetKey($key);
			return $uploader->UploadUrl($url);
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
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
			if(!$image->CanResize()) return $this->ViewRaw($params);
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
		} catch(MagratheaApiException $ex) {
			throw $ex;
		} catch(\Exception $ex) {
			ErrorCodes::Instance()->ThrowException(5001, null, $ex->getMessage());
		}
	}

	public function Remove($params) {
		try {
			$key = @$params["private_key"];
			$id = @$params["id"];
			if(!$key || !$id) {
				ErrorCodes::Instance()->ThrowException(4007);
			}
			return $this->service->Remove($key, $id);
		} catch(MagratheaApiException $e) {
			throw $e;
		} catch(\Exception $e) {
			ErrorCodes::Instance()->ThrowException(5001, null, $e->getMessage());
		}

	}

}