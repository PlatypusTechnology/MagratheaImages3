<?php

namespace MagratheaImages3\Images;

use GdImage;
use Magrathea2\Exceptions\MagratheaApiException;
use MagratheaImages3\Images\Images;
use MagratheaImages3\Configs\ImagesConfigs;

class ImageViewer {

	public Images $image;
	public string $file;
	public ?ImageResizer $resizer = null;
	public bool $saveImage = true;
	public bool $debugOn = false;

	public function __construct(Images $img) {
		$this->image = $img;
	}

	public function Debug(): ImageViewer {
		$this->debugOn = true;
		return $this;
	}
	public function GetResizerDebug(): array {
		return $this->resizer->GetDebug();
	}
	public function StartResizer(): ImageResizer {
		if($this->resizer == null) {
			new ImageResizer($this->image);
		}
		return $this->resizer;
	}
	public function ForceGeneration(): bool {
		return (@$_GET["generate"] == "true");
	}
	public function DontSave(): ImageViewer {
		$this->saveImage = false;
		return $this;
	}

	public function SetFile($filename) {
		$this->file = $filename;
		return $this;
	}

	public function DisplayFromGD(GdImage $gd, string $extension) {
		self::HeaderExtension($extension);
		ob_start();
		switch($extension) {
			case "jpg": case "jpeg": imagejpeg($gd); break;
			case "png": imagepng($gd); break;
			case "bmp": imagebmp($gd); break;
			case "gif": imagegif($gd); break;
			case "webp": imagewebp($gd); break;
			case "wbmp": imagewbmp($gd); break;
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

	public function ViewFile() {
		if($this->debugOn) return $this->DebugView();
		self::HeaderExtension($this->image->extension);
		header("Content-Length: ".filesize($this->file));
		// dump the picture and stop the script
		$fp = fopen($this->file, 'rb');
		fpassthru($fp);
		exit;
	}

	public function ViewGD() {
		return $this->DisplayFromGD($this->resizer->GetGD(), $this->resizer->extension);
	}

	public function Raw() {
		return $this->SetFile($this->image->GetRawFile())->ViewFile();
	}

	public function Thumb(): ImageViewer {
		$file = $this->image->GetThumbFile();
		$this->SetFile($file);
		if($this->ShouldGenerate()) {
			try {
				$this->resizer = new ImageResizer($this->image);
				if($this->debugOn) $this->resizer->DebugOn();
				$thumbSize = $this->resizer->GetThumbSize();
				$this->resizer->SetDimensions($thumbSize, $thumbSize);
				$this->resizer->SetNewFile($file);
				$this->resizer->Resize();
				if($this->saveImage) $this->resizer->Save();
			} catch(MagratheaApiException $ex) {
				throw $ex;
			} catch(\Exception $ex) {
				throw new MagratheaApiException("Error generating image: ".$ex->getMessage(), true, 500, $ex);
			}
		}
		return $this;
	}

	public function Size($w, $h, $stretch=false): ImageViewer {
		$file = $this->image->GetFileName($w, $h, $stretch);
		$this->SetFile($file);
		if($this->ShouldGenerate()) {
			try {
				$this->resizer = new ImageResizer($this->image);
				if($this->debugOn) $this->resizer->DebugOn();
				$this->resizer->SetNewFile($file);
				$this->resizer->SetDimensions($w, $h);
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
		if($this->ForceGeneration()) return true;
		return !file_exists($this->file);
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
