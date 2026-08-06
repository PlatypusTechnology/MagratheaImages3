<?php
namespace MagratheaImages3\Images;

class Images extends \MagratheaImages3\Images\Base\ImagesBase {

	public bool $placeholder = false;

	// Leaves headroom under the 255-byte filesystem limit and the `name`/`filename`
	// varchar(255) columns once the "{id}_" prefix is added.
	const MAX_FILE_SEGMENT_LENGTH = 200;

	public function SetPlaceholder(): Images {
		$this->placeholder = true;
		return $this;
	}

	public function CanResize(): bool {
		return ($this->extension != "svg");
	}

	private static function TruncatedName(string $source): string {
		return "truncated-".substr(sha1($source), 0, 16);
	}

	public function FromUploadFile(array $file): Images {
		$imageName = str_replace(" ", "_", $file["name"]);
		$imageNameArr = explode(".", $imageName);

		$this->extension = array_pop($imageNameArr);
		$this->name = implode(" ", $imageNameArr);
		$safeFile = $imageName;
		if(strlen($imageName) > self::MAX_FILE_SEGMENT_LENGTH) {
			$this->name = self::TruncatedName($imageName);
			$safeFile = $this->extension ? $this->name.".".$this->extension : $this->name;
		}
		$this->SetFilename($safeFile);
		$this->size = $file["size"];
		$this->file_type = $file["type"];
		return $this;
	}

	public function FromUrl($url, ?string $detectedExt = null): Images {
		$urlPieces = explode('/', $url);
		$file = end($urlPieces);
		$file = str_replace(" ", "_", $file);
		$pieces = explode(".", $file);
		if(count($pieces) > 1) {
			$this->extension = array_pop($pieces);
		} else {
			$this->extension = $detectedExt;
			if($detectedExt) $file .= '.'.$detectedExt;
		}
		$this->name = implode(" ", $pieces);
		if(strlen($file) > self::MAX_FILE_SEGMENT_LENGTH) {
			$this->name = self::TruncatedName($file);
			$file = $this->extension ? $this->name.".".$this->extension : $this->name;
		}
		$this->SetFilename($file);
		return $this;
	}

	/**
	 * Sets filename for image
	 * @param 	string 		$name 		file name
	 * @return  Images
	 */
	public function SetFilename($name): Images {
		$nextId = $this->GetNextID();
		$this->filename = $nextId."_".$name;
		return $this;
	}

	public function GetRawFile(): string {
		return PathManager::GetRawFolder($this->folder).$this->filename;
	}

	public function GetThumbFile(): string {
		$name = "thumb";
		if($this->placeholder) $name .= "_placeholder";
		return PathManager::GetGeneratedFolder($this->folder).$this->BuildGenFileName($name);
	}

	public function GetFileName($w, $h, $stretch=false): string {
		$name = $w."x".$h;
		if($stretch) $name .= "-s";
		if($this->placeholder) $name .= "_placeholder";
		return PathManager::GetGeneratedFolder($this->folder).$this->BuildGenFileName($name);
	}

	public function BuildGenFileName(string $addon): string {
		return $this->id."_".$addon;
	}

	public function BuildFilename(string $addon): string {
		$fileArr = explode('.', $this->filename);
		$ext = array_pop($fileArr);
		return implode('.', $fileArr)."_".$addon.".".$ext;
	}
	
	public function GetSize(): string {
		return \MagratheaImages3\Helper::GetSize($this->size);
	}

	public function IsSquare(): bool {
		return ($this->width == $this->height);
	}
	public function IsPortrait(): bool {
		return ($this->width < $this->height);
	}
	public function IsLandscape(): bool {
		return ($this->width > $this->height);
	}

}
