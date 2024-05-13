<?php
namespace MagratheaImages3\Images;

use Magrathea2\DB\Query;

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


}
