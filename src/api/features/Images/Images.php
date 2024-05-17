<?php
namespace MagratheaImages3\Images;

class Images extends \MagratheaImages3\Images\Base\ImagesBase {

	public bool $placeholder = false;

	public function SetPlaceholder(): Images {
		$this->placeholder = true;
		return $this;
	}

	public function FromUploadFile(array $file): Images {
		$imageName = str_replace(" ", "_", $file["name"]);
		$imageNameArr = explode(".", $imageName);
	
		$this->extension = array_pop($imageNameArr);
		$this->name = implode(" ", $imageNameArr);
		$this->SetFilename($imageName);
		$this->size = $file["size"];
		$this->file_type = $file["type"];
		return $this;
	}

	public function FromUrl($url): Images {
		$urlPieces = explode('/', $url);
		$file = end($urlPieces);
		$file = str_replace(" ", "_", $file);
		$pieces = explode(".", $file);
		$this->extension = array_pop($pieces);
		$this->name = implode(" ", $pieces);
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
		return PathManager::GetGeneratedFolder($this->folder).$this->BuildFilename($name);
	}

	public function GetFileName($w, $h, $stretch=false): string {
		$name = $w."x".$h;
		if($stretch) $name .= "-s";
		if($this->placeholder) $name .= "_placeholder";
		return PathManager::GetGeneratedFolder($this->folder).$this->BuildFilename($name);
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
