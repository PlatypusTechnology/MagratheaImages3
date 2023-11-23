<?php
namespace MagratheaImages3\Images;

class Images extends \MagratheaImages3\Images\Base\ImagesBase {

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
		return PathManager::GetGeneratedFolder($this->folder).$this->BuildFilename("thumb");
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
