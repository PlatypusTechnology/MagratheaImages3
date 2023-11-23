<?php

namespace MagratheaImages3\Images;

use Magrathea2\Exceptions\MagratheaApiException;
use MagratheaImages3\Images\Images;
use Magrathea2\Helper;
use MagratheaImages3\Configs\ImagesConfigs;

class ImageViewer {

	public Images $image;
	public string $file;
	public ImageResizer $resizer;

	public function __construct(Images $img) {
		$this->image = $img;
	}

	public function IsDebug(): bool {
		return (@$_GET["debug"] == "true");
	}
	public function ForceGeneration(): bool {
		return (@$_GET["generate"] == "true");
	}

	public function SetFile($filename) {
		$this->file = $filename;
		return $this;
	}

	public function ViewRaw() {
		return $this->SetFile($this->image->GetRawFile())->Img();
	}

	public function ViewThumb() {
		$file = $this->image->GetThumbFile();
		$this->SetFile($file);
		if($this->ShouldGenerate()) {
			try {
				$thumbSize = ImagesConfigs::Instance()->GetThumbSize();
				$this->resizer = new ImageResizer($this->image);
				if($this->IsDebug()) $this->resizer->DebugOn();
				$this->resizer->SetDimensions($thumbSize, $thumbSize);
				$this->resizer->SetNewFile($file);
				$this->resizer->Generate();
			} catch(MagratheaApiException $ex) {
				throw $ex;
			} catch(\Exception $ex) {
				throw new MagratheaApiException("Error generating image: ".$ex->getMessage(), true, 500, $ex);
			}
		}
		return $this->Img();
	}

	public function ShouldGenerate() {
		if($this->ForceGeneration()) return true;
		return !file_exists($this->file);
	}

	public function Img() {
		if($this->IsDebug()) return $this->DebugView();
		header("Content-Type: image/png");
		header("Content-Length: " . filesize($this->file));
		// dump the picture and stop the script
		$fp = fopen($this->file, 'rb');
		fpassthru($fp);
		exit;
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
