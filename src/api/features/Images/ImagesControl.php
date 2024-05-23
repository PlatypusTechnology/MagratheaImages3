<?php
namespace MagratheaImages3\Images;

use Magrathea2\DB\Query;
use Magrathea2\Exceptions\MagratheaApiException;
use MagratheaImages3\Apikey\ApikeyControl;

class ImagesControl extends \MagratheaImages3\Images\Base\ImagesControlBase {

	public function GetLast(string $key, $page=0, $amount=12): array {
		$query = Query::Select()
			->Obj(new Images())
			->Limit($amount)
			->Page($page)
			->Where(["upload_key" => $key])
			->OrderBy("id DESC");
		return $this->Run($query);
	}

	public function Remove(string $privateKey, $id) {
		$apiControl = new ApikeyControl();
		$api = $apiControl->GetByKey($privateKey);
		$image = new Images($id);
		if($image->upload_key != $api->id) {
			throw new MagratheaApiException("Key does not belong to image");
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
			"del_generated" => $manager->DeleteGeneratedPattern($img->id."_*"),
		];
	}

}
