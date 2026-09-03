<?php
namespace MagratheaImages3\Images;

use Magrathea2\DB\Query;
use Magrathea2\Exceptions\MagratheaApiException;
use MagratheaImages3\Apikey\ApikeyControl;
use MagratheaImages3\ErrorCodes;

class ImagesControl extends \MagratheaImages3\Images\Base\ImagesControlBase {

	const DEFAULT_CLONE_SUBFOLDER = "cloned";

	public function GetByUuid(string $uuid): ?Images {
		$q = Query::Select()->Obj(new Images())->Where(["uuid" => $uuid]);
		return $this->RunRow($q);
	}

	public function GetLast(string $key, $page=0, $amount=12, ?string $subfolder = null): array {
		$where = ["upload_key" => $key];
		if($subfolder != null) $where["subfolder"] = $subfolder;
		$query = Query::Select()
			->Obj(new Images())
			->Limit($amount)
			->Page($page)
			->Where($where)
			->OrderBy("id DESC");
		return $this->Run($query);
	}

	public function Remove(string $privateKey, $id) {
		$apiControl = new ApikeyControl();
		$api = $apiControl->GetByKey($privateKey);
		$image = new Images($id);
		if($image->upload_key != $api->id) {
			ErrorCodes::Instance()->ThrowException(4032, ["id" => $id]);
		}
		return $this->RemoveImage($image);
	}

	public function RemoveImage(Images $image): array {
		try {
			$delImage = $image->Delete();
			$delFile = $this->RemoveRawFile($image);
			return [
				"del_image" => $delImage,
				"del_file" => [
					"file" => $image->filename,
					"deleted" => $delFile,
				]
			];
		} catch(\Exception $ex) {
			throw $ex;
		}
	}

	public function RemoveRawFile(Images $img): array {
		$manager = new FileManager();
		$manager->SetApiKeyId($img->upload_key);
		return [
			"del_file" => $manager->DeleteFile("raw/".$img->filename),
			"del_generated" => [
				"id" => $manager->DeleteGeneratedPattern($img->id."_*"),
				"uuid" => $manager->DeleteGeneratedPattern($img->uuid."_*"),
			],
		];
	}

	public function CloneImage(string $privateKey, string $publicKey, string $uuid, ?string $subfolder = null): array {
		$apiControl = new ApikeyControl();

		// 1. Resolve + validate destination key (the actor). Same checks Upload() applies.
		$destKey = $apiControl->GetByKey($privateKey); // private=true default
		if(empty($destKey->id)) {
			ErrorCodes::Instance()->ThrowException(4042, null, $privateKey);
		}
		$validation = $destKey->ValidateKey();
		if(!$validation["ok"]) {
			ErrorCodes::Instance()->ThrowException($validation["code"] ?? 403, null, $validation["data"]);
		}

		// 2. Resolve source image by UUID, scoped to the given public key.
		$image = $this->GetByUuid($uuid);
		if(empty($image) || empty($image->name)) {
			ErrorCodes::Instance()->ThrowException(4043, ["uuid" => $uuid]);
		}
		$sourceKey = $apiControl->GetByKey($publicKey, false); // private=false: look up by public_key column
		if(empty($sourceKey->id)) {
			ErrorCodes::Instance()->ThrowException(4041, $publicKey);
		}
		if($image->upload_key != $sourceKey->id) {
			ErrorCodes::Instance()->ThrowException(4032, ["uuid" => $uuid]);
		}

		// 3. The guard rail: public_key and private_key must not be the same apikey row.
		if($sourceKey->id == $destKey->id) {
			ErrorCodes::Instance()->ThrowException(4036, null, "source and destination belong to the same key pair");
		}

		// 4. Copy the raw file on disk.
		$sourcePath = $image->GetRawFile();
		if(!file_exists($sourcePath)) {
			ErrorCodes::Instance()->ThrowException(5005, null, $sourcePath);
		}
		$destFolder = PathManager::GetRawFolder($destKey->folder);
		$folderOk = PathManager::CheckDestinationFolder($destFolder);
		if(!$folderOk["success"]) {
			ErrorCodes::Instance()->ThrowException(5003, $folderOk["path"], $folderOk["error"]);
		}

		// 5. Build the new Images row — same shape ImageUploader::CreateImage() builds,
		//    minus a re-upload: reuses SetFilename() so the new row gets its own
		//    "{new_id}_name.ext" filename and its own auto-generated uuid on Insert().
		$clone = new Images();
		$clone->folder     = $destKey->folder;
		$clone->upload_key = $destKey->id;
		$clone->subfolder  = $subfolder ?: self::DEFAULT_CLONE_SUBFOLDER;
		$clone->width      = $image->width;
		$clone->height     = $image->height;
		$clone->file_type  = $image->file_type;
		$clone->size       = $image->size;
		$clone->extension  = $image->extension;
		$clone->name       = $image->name;
		$originalBasename  = $image->extension ? $image->name.".".$image->extension : $image->name;
		$clone->SetFilename($originalBasename);

		$destPath = $destFolder.$clone->filename;
		if(!copy($sourcePath, $destPath)) {
			ErrorCodes::Instance()->ThrowException(5006, null, "could not copy [".$sourcePath."] to [".$destPath."]");
		}

		$clone->Insert();
		$destKey->IncrementUses();

		return [
			"image" => $clone,
			"public_key" => $destKey->public_key,
		];
	}

}
