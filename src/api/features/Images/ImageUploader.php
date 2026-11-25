<?php

namespace MagratheaImages3\Images;

use MagratheaImages3\Apikey\Apikey;
use Magrathea\Exceptions\MagratheaException;
use Magrathea\MagratheaHelper;
use Magrathea\Logger;
use MagratheaImages3\ErrorCodes;
use Magrathea\ConfigApp;

class ImageUploader {

	public $file = [];
	public Apikey|null $key = null;
	public ?string $subFolder = null;
	public $extensions = ["jpg", "jpeg", "png", "bmp", "webp", "wbmp", "svg"];
	private static $allowedMimes = [
		'image/jpeg'   => 'jpg',
		'image/png'    => 'png',
		'image/webp'   => 'webp',
		'image/bmp'    => 'bmp',
		'image/x-bmp'  => 'bmp',
		'image/gif'    => 'gif',
		'image/svg+xml' => 'svg',
	];

	public function SetKey(Apikey $key): ImageUploader {
		$this->key = $key;
		return $this;
	}
	public function SetFile(array $file): ImageUploader {
		if(empty($file)) {
			ErrorCodes::Instance()->ThrowException(4001, null, "empty file");
		}
		$max = self::getMaximumFileUploadSize();
		// PHP refuses a file over its own limits (the error codes); a lower max_upload_size is
		// only enforced here. $file["size"] is what PHP received, not what the client claimed.
		// $max is 0 only with post_max_size = 0, which is PHP's "no limit".
		$overMax = $max > 0 && (int) @$file["size"] > $max;
		if($overMax || in_array(@$file["error"], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE])) {
			$limit = \MagratheaImages3\Helper::GetSize($max);
			ErrorCodes::Instance()->ThrowException(4002, null, "maximum allowed size is {$limit}");
		}
		$this->file = $file;
		return $this;
	}
	public function SetSubfolder(string $folder): ImageUploader {
		$this->subFolder = $folder;
		return $this;
	}

	public function CreateImage(): Images {
		$image = new Images();
		$image->folder = $this->key->folder;
		$image->upload_key = $this->key->GetID();
		if(@$this->subFolder) $image->subfolder = $this->subFolder;
		return $image;
	}

	public function getImageReturn($image): array {
		$rsData = [
			"image" => $image,
		];
		if($this->key) {
			$rsData["public_key"] = $this->key->GetPublicKey();
		}
		return $rsData;
	}

	/**
	 * Uploads $_FILES["file"]. Every failure is thrown, never returned, so the API answers it
	 * with its real HTTP status: ErrorCodes' own exceptions as they are, anything else (a DB
	 * error) as 5001 in ImagesApi::UploadWithKey(). Before 3.7.0 this caught its own errors and
	 * returned them, which the API sent as HTTP 200 with `success: true`.
	 */
	public function Upload(): array {
		$path = $this->GetDestination();
		$this->ValidateDestination($path);
		$image = $this->CreateImage()->FromUploadFile($this->file);
		$this->ValidateExtension($image->extension);

		$finalName = MagratheaHelper::EnsureTrailingSlash($path).$image->filename;
		// the move's own result, not file_exists(): a file left there by a name collision isn't this upload
		if(!move_uploaded_file($_FILES["file"]["tmp_name"], $finalName)) {
			ErrorCodes::Instance()->ThrowException(5007, null, $image->filename);
		}
		try {
			$this->ReadDimensions($image, $finalName);
			$image->Insert();
		} catch(\Throwable $ex) {
			$this->RemoveUnsavedFile($finalName);
			throw $ex;
		}
		$this->key->IncrementUses();
		return $this->getImageReturn($image);
	}

	/**
	 * Strips the metadata of a stored raw file, then reads its dimensions into $image. A file
	 * without readable dimensions is 4153, except an SVG (it has none to read).
	 * @return array|false getimagesize()'s result
	 */
	private function ReadDimensions(Images $image, string $file): array|false {
		MetadataStripper::Strip($file, $image->extension);
		$size = @getimagesize($file);
		if($size === false && $image->extension != "svg") {
			ErrorCodes::Instance()->ThrowException(4153);
		}
		$image->width = $size ? $size[0] : 0;
		$image->height = $size ? $size[1] : 0;
		return $size;
	}

	/**
	 * Deletes a raw file whose upload failed before its row was inserted. Nothing references it,
	 * so nothing else would ever delete it. Not called once the row exists: a file with a row is
	 * a stored image, even when a later step (the key's use count) fails.
	 */
	private function RemoveUnsavedFile(string $file): void {
		if(file_exists($file) && !@unlink($file)) {
			Logger::Instance()->Log("upload failed and its raw file could not be removed: [".$file."]");
		}
	}

	public function GetExternalContent($url) {
		$context = stream_context_create([
			"http" => [ "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36" ]
		]);
		$handle = @fopen($url, "rb", false, $context);
		if($handle === false) return false;

		$maxSize = self::getMaximumFileUploadSize();
		$content = "";
		$length = 0;
		while(!feof($handle)) {
			$chunk = fread($handle, 8192);
			if($chunk === false) break;
			$length += strlen($chunk);
			if($length > $maxSize) {
				fclose($handle);
				$limit = \MagratheaImages3\Helper::GetSize($maxSize);
				ErrorCodes::Instance()->ThrowException(4002, null, "maximum allowed size is {$limit}");
			}
			$content .= $chunk;
		}
		fclose($handle);
		return $content;
	}

	// like Upload(): every failure is thrown (ImagesApi::UploadUrlWithKey() maps non-API ones to 5001)
	public function UploadUrl($url) {
		$cleanUrl = strtok($url, '?');
		$path = $this->GetDestination();
		$this->ValidateDestination($path);

		$content = $this->GetExternalContent($url);
		$ext = $this->ValidateMime($content);

		$image = $this->CreateImage()->FromUrl($cleanUrl, $ext);

		$finalName = MagratheaHelper::EnsureTrailingSlash($path).$image->filename;
		// a short write (a full disk) leaves a truncated file: remove it like any other failure
		if(file_put_contents($finalName, $content) !== strlen($content)) {
			$this->RemoveUnsavedFile($finalName);
			ErrorCodes::Instance()->ThrowException(5007, null, $image->filename);
		}
		try {
			$size = $this->ReadDimensions($image, $finalName);
			$image->file_type = $size ? $size["mime"] : $image->file_type;
			$image->size = filesize($finalName);
			$image->Insert();
		} catch(\Throwable $ex) {
			$this->RemoveUnsavedFile($finalName);
			throw $ex;
		}
		$this->key->IncrementUses();
		return $this->getImageReturn($image);
	}

	public function GetDestination(): string {
		return PathManager::GetRawFolder($this->key->folder);
	}

	public function ValidateDestination(string $path): bool {
		$destinationOk = PathManager::CheckDestinationFolder($path);
		if(!$destinationOk["success"]) {
			ErrorCodes::Instance()->ThrowException(5003, $destinationOk["path"], $destinationOk["error"]);
		}
		return true;
	}

	public function ValidateExtension($ext): bool {
		if(!in_array($ext, $this->extensions)) {
			ErrorCodes::Instance()->ThrowException(4151, null, $ext);
		}
		return true;
	}

	public function ValidateMime(string $content): string {
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->buffer($content);
		if(!isset(self::$allowedMimes[$mime])) {
			ErrorCodes::Instance()->ThrowException(4152, null, $mime);
		}
		return self::$allowedMimes[$mime];
	}

	public function ValidateUpload(): bool {
		if(empty($this->key)) return false;
		if(empty($this->file)) return false;
		return true;
	}

	public static function getMaximumFileUploadSize() {
		$phpLimit = min(self::convertPHPSizeToBytes(ini_get('post_max_size')), self::convertPHPSizeToBytes(ini_get('upload_max_filesize')));
		$override = ConfigApp::Instance()->Get("max_upload_size");
		if($override && self::IsValidSizeFormat($override)) {
			$overrideBytes = self::convertPHPSizeToBytes($override);
			if($overrideBytes > 0) return min($phpLimit, $overrideBytes);
		}
		return $phpLimit;
	}
	public static function IsValidSizeFormat($value): bool {
		return is_string($value) && preg_match('/^\d+[KMGTP]?$/i', trim($value)) === 1;
	}
	public static function convertPHPSizeToBytes($sSize): int {
		$sSuffix = strtoupper(substr($sSize, -1));
		if (!in_array($sSuffix,array('P','T','G','M','K'))){
			return (int)$sSize;
		}
		$iValue = substr($sSize, 0, -1);
		switch ($sSuffix) {
			case 'P': $iValue *= 1024;
			case 'T': $iValue *= 1024;
			case 'G': $iValue *= 1024;
			case 'M': $iValue *= 1024;
			case 'K': $iValue *= 1024;
			break;
		}
		return (int)$iValue;
	}
}
