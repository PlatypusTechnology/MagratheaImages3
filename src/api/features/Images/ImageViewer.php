<?php

namespace MagratheaImages3\Images;

use GdImage;
use Magrathea2\Config;
use Magrathea2\Exceptions\MagratheaApiException;
use MagratheaImages3\Apikey\ApikeyControl;
use MagratheaImages3\Images\Images;

class ImageViewer {

	public Images $image;
	public string $file;
	public bool $fileExists = false;
	public ?ImageResizer $resizer = null;
	public bool $saveImage = true;
	public bool $forceGeneration = false;
	public bool $debugOn = false;
	public bool $lowQuality = false;
	public bool $rawFile = false;

	public function __construct(Images $img) {
		$this->image = $img;
	}

	public function Debug(): ImageViewer {
		$this->debugOn = true;
		return $this;
	}
	public function ForceGeneration(): ImageViewer {
		$this->forceGeneration = true;
		return $this;
	}
	public function DontSave(): ImageViewer {
		$this->saveImage = false;
		return $this;
	}
	public function GetResizerDebug(): array {
		if(!$this->resizer) {
			return ["no-resizer"];
		}
		return $this->resizer->GetDebug();
	}
	public function StartResizer(): ImageResizer {
		if($this->resizer == null) {
			new ImageResizer($this->image);
		}
		return $this->resizer;
	}

	public function SetFile($filename) {
		$this->file = $filename;
		return $this;
	}

	public function DisplayFromGD(GdImage $gd, string $extension) {
		self::HeaderExtension($extension);
		ob_start();
		switch($extension) {
			case "bmp": imagebmp($gd); break;
			case "gif": imagegif($gd); break;
			case "webp": imagewebp($gd); break;
			case "wbmp": imagewbmp($gd); break;
			case "png": imagepng($gd); break;
			case "jpg": case "jpeg": default: imagejpeg($gd); break;
		}
		ob_get_contents();
		ob_end_flush();
	}

	public static function HeaderExtension(string $extension): bool {
		$ctH = "Content-Type: ";
		switch($extension) {
			case "jpg":
			case "jpeg":
				header($ctH.image_type_to_mime_type(IMAGETYPE_JPEG));
				return true;
			case "png":
				header($ctH.image_type_to_mime_type(IMAGETYPE_PNG));
				return true;
			case "bmp":
				header($ctH.image_type_to_mime_type(IMAGETYPE_BMP));
				return true;
			case "gif":
				header($ctH.image_type_to_mime_type(IMAGETYPE_GIF));
				return true;
			case "webp":
				header($ctH.image_type_to_mime_type(IMAGETYPE_WEBP));
				return true;
			case "wbmp":
				header($ctH.image_type_to_mime_type(IMAGETYPE_WBMP));
				return true;
			case "ico":
				header($ctH.image_type_to_mime_type(IMAGETYPE_ICO));
				return true;
			case "svg":
				header($ctH."image/svg+xml");
				return true;
		}
		return false;
	}

	public static function ViewFromAbsolute($path) {
		$pieces = explode('.', $path);
		$extension = end($pieces);
		$isImage = self::HeaderExtension($extension);
		if($isImage) {
			header("Content-Length: " . filesize($path));
			echo file_get_contents($path);
			// $fp = fopen($path, 'rb');
			// fpassthru($fp);
		} else return false;
	}

	public static function ViewQuickAccess(string $key, string $fileName) {
		$folder = ApikeyControl::GetCachedFolder($key);
		$file = PathManager::GetGeneratedFolder($folder).$fileName;
		if(file_exists($file)) {
			self::HeaderExtension("webp");
			header("Content-Length: ".filesize($file));
			$fp = fopen($file, 'rb');
			if(!$fp) throw new MagratheaApiException("could not open file [".$file."]");
			fpassthru($fp);
			exit;
		}
	}

	public function ViewFile() {
		if($this->debugOn) return $this->DebugView();
		$webp = boolval(Config::Instance()->Get("webp_quick_access"));
		$ext = $webp ? "webp" : $this->image->extension;
		$this->file = $this->rawFile ? $this->file : $this->file.".".$ext;
		self::HeaderExtension($ext);
		header("Content-Length: ".filesize($this->file));
		// dump the picture and stop the script
		$fp = fopen($this->file, 'rb');
		if(!$fp) throw new MagratheaApiException("could not open file [".$this->file."]");
		fpassthru($fp);
		exit;
	}

	public function ViewGD() {
		if($this->resizer == null) {
			if($this->fileExists) return $this->ViewFile();
			else throw new MagratheaApiException("Resizer is empty and file does not exists", 500);
		} 
		return $this->DisplayFromGD($this->resizer->GetGD(), $this->resizer->extension);
	}

	public function Raw() {
		$this->rawFile = true;
		return $this->SetFile($this->image->GetRawFile())->ViewFile();
	}

	public function Placeholder(): ImageViewer {
		$this->lowQuality = true;
		return $this;
	}

	public function Thumb(): ImageViewer {
		if($this->lowQuality) $this->image->SetPlaceholder();
		$file = $this->image->GetThumbFile();
		$this->SetFile($file);
		$this->resizer = new ImageResizer($this->image);
		$thumbSize = $this->resizer->GetThumbSize();
		return $this->Process($thumbSize, $thumbSize);
	}

	public function Size($w, $h, $stretch=false): ImageViewer {
		if($this->lowQuality) $this->image->SetPlaceholder();
		$file = $this->image->GetFileName($w, $h, $stretch);
		$this->SetFile($file);
		$this->resizer = new ImageResizer($this->image);
		return $this->Process($w, $h, $stretch);
	}

	public function Process(int $width, int $height, bool $stretch=false): ImageViewer {
		if($this->ShouldGenerate()) {
			try {
				if($this->debugOn) $this->resizer->DebugOn();
				if($this->lowQuality) $this->resizer->SetPlaceholder();
				$this->resizer->SetNewFile($this->file);
				$this->resizer->SetDimensions($width, $height);
				$this->resizer->Resize();
				if($stretch) $this->resizer->keepAspectRatio = false;
				if($this->saveImage) $this->resizer->Save();
			} catch(MagratheaApiException $ex) {
				throw $ex;
			} catch(\Exception $ex) {
				throw new MagratheaApiException("Error generating image: ".$ex->getMessage(), true, 500, $ex);
			}
		}
		return $this;
	}

	public function ShouldGenerate() {
		if($this->debugOn) return true;
		if($this->forceGeneration) return true;
		$this->fileExists = file_exists($this->file);
		return !$this->fileExists;
	}
	private function DebugView(): array {
		$img = $this->image;
		$arrFile = explode("/", $this->file);
		$rs = [];
		if(!empty($this->resizer)) {
			$rs["generator"] = $this->resizer->GetDebug();
		}
		if(!file_exists($this->file)) {
			$rs = [
				"id" => $img->id,
				"name" => $img->name,
				"extension" => $img->extension,
				"file" => array_pop($arrFile),
				"error" => "FILE DOES NOT EXISTS",
			];
		} else {
			list($w, $h) = @getimagesize($this->file);
			if(empty($w)) {
				$rs["error"] = "Could not read generated image!";
			}
			return array_merge($rs, [
				"id" => $img->id,
				"name" => $img->name,
				"extension" => $img->extension,
				"file" => array_pop($arrFile),
				"width" => $w,
				"height" => $h,
				"dimensions" => $w."x".$h,
				"size" => \MagratheaImages3\Helper::GetSize(filesize($this->file)),
			]);
		}
		$rs["location"] = implode("/", $arrFile);
		return $rs;
	}

}
