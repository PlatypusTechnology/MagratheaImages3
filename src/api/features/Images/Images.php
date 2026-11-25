<?php
namespace MagratheaImages3\Images;

use Magrathea\DB\Database;
use Magrathea\MagratheaModel;
use MagratheaImages3\Apikey\Apikey;

class Images extends MagratheaModel implements \JsonSerializable {

	protected $dbTable = "images";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"uuid" => "uuid",
		"name" => "string",
		"filename" => "string",
		"extension" => "string",
		"folder" => "string",
		"subfolder" => "string",
		"width" => "int",
		"height" => "int",
		"file_type" => "string",
		"size" => "int",
		"upload_key" => "string",
	];
	// the CRUD admin (ImagesAdmin) shows upload_key as a select of api keys
	protected $relations = [
		"external" => ["upload_key" => "\\MagratheaImages3\\Apikey\\Apikey"],
	];
	// MagratheaPHP 3 defaults to strict types (int/bool fields as native JSON types); pinned off so
	// the API keeps 2.x's string values. Removing it is an API change: see docs/migration.md.
	protected $strictTypes = false;

	// created_at/updated_at are declared by MagratheaModel and owned by the database
	public $id, $uuid, $name, $filename, $extension, $folder, $subfolder, $width, $height, $file_type, $size, $upload_key;

	public bool $placeholder = false;

	// Set per-request by ImagesApi::ResolveImage() to whichever identifier (id or
	// uuid) the current request actually resolved through; not a DB field.
	public ?string $accessId = null;

	// Leaves headroom under the 255-byte filesystem limit and the `name`/`filename`
	// varchar(255) columns once the "{id}_" prefix is added.
	const MAX_FILE_SEGMENT_LENGTH = 200;

	/**
	 * The public API shape of an image, matching the `Image` schema in `swagger.yaml`.
	 *
	 * The API json_encode()s models directly, which would otherwise emit every public
	 * property, including the internal render flags `placeholder`/`accessId`.
	 *
	 * This is deliberately an explicit list rather than `ToArray()` or the public properties:
	 * an allowlist can only ever emit what is written here (`Apikey`, reachable through
	 * `GetApikey()`, exposes `private_key` as a public property).
	 */
	public function jsonSerialize(): mixed {
		return [
			"id" => $this->id,
			"uuid" => $this->uuid,
			"name" => $this->name,
			"filename" => $this->filename,
			"extension" => $this->extension,
			"folder" => $this->folder,
			"subfolder" => $this->subfolder,
			"width" => $this->width,
			"height" => $this->height,
			"file_type" => $this->file_type,
			"size" => $this->size,
			"upload_key" => $this->upload_key,
			"created_at" => $this->created_at,
			"updated_at" => $this->updated_at,
		];
	}

	/**
	 * The api key this image was uploaded with (one query per call)
	 */
	public function GetApikey(): Apikey {
		return new Apikey($this->upload_key);
	}

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
		$nextId = $this->NextAutoIncrement();
		$this->filename = $nextId."_".$name;
		return $this;
	}

	/**
	 * The `images` table's next auto-increment value: what MagratheaPHP 2's `GetNextID()` returned
	 * (removed in 3.0). Raw files are stored as "{id}_{name}", so this keeps that naming.
	 * Racy by nature (two concurrent uploads can read the same value), as it always was.
	 */
	private function NextAutoIncrement() {
		$data = Database::Instance()->QueryRow("SHOW TABLE STATUS LIKE '".$this->dbTable."'");
		return $data["auto_increment"] ?? null;
	}

	public function GetRawFile(): string {
		return PathManager::GetRawFolder($this->folder).$this->filename;
	}

	/**
	 * The name of a generated file, without folder or extension:
	 * "{accessId}_{size}[-f|-s][_placeholder]". "thumb" never gets a mode suffix; fit wins over stretch.
	 *
	 * The single builder for both sides of the cache: the path a file is generated at
	 * (GetFileName()/GetThumbFile()) and the webp quick-access lookup
	 * (ImagesApi::QuickAccessFileName()), which runs before the image is loaded. When the two names
	 * differ, quick access silently never hits.
	 */
	public static function GeneratedName(string $accessId, string $size, bool $stretch, bool $fit, bool $placeholder): string {
		$name = $accessId."_".$size;
		if($size != "thumb") {
			if($fit) $name .= "-f";
			else if($stretch) $name .= "-s";
		}
		if($placeholder) $name .= "_placeholder";
		return $name;
	}

	public function GetThumbFile(): string {
		return PathManager::GetGeneratedFolder($this->folder)
			.self::GeneratedName($this->GenAccessId(), "thumb", false, false, $this->placeholder);
	}

	public function GetFileName($w, $h, $stretch=false, $fit=false): string {
		return PathManager::GetGeneratedFolder($this->folder)
			.self::GeneratedName($this->GenAccessId(), $w."x".$h, (bool) $stretch, (bool) $fit, $this->placeholder);
	}

	private function GenAccessId(): string {
		return (string) ($this->accessId ?? $this->id);
	}

	public function BuildGenFileName(string $addon): string {
		return $this->GenAccessId()."_".$addon;
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
