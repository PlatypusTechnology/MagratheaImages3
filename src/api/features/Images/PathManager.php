<?php

namespace MagratheaImages3\Images;

use Magrathea2\Config;
use Magrathea2\ConfigApp;
use Magrathea2\Singleton;
use Magrathea2\MagratheaHelper;
use Magrathea2\Logger;


class PathManager {

	public static function GetMediaFolder($apiFolder): string {
		$folder = Config::Instance()->Get("medias_path");
		return MagratheaHelper::EnsureTrailingSlash($folder).$apiFolder."/";
	}

	public static function GetRawFolder($folder): string {
		return self::GetMediaFolder($folder)."raw/";
	}

	public static function GetGeneratedFolder($folder): string {
		return self::GetMediaFolder($folder)."generated/";
	}

	public static function CheckDestinationFolder($path): array {
		if (is_dir($path)) return [ "success" => true, "path" => $path ];
		try {
			if(@mkdir($path, 0755, true)) {
				return [ "success" => true, "path" => $path ];
			} else {
				$error = error_get_last();
				return [ "success" => false, "error" => $error["message"], "path" => $path ];
			}
			if(!is_writeable($path)){
				return [ "success" => false, "error" => "destination path has no writing permission", "path" => $path ];
			}
		} catch(\Exception $e) {
			Logger::Instance()->Log("error creating upload folder ". $e->getMessage());
			return [ "success" => false, "error" => $e->getMessage(), "path" => $path ];
		}
	}


}
