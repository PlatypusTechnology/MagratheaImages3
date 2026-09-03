<?php

namespace MagratheaImages3;

use Magrathea2\Config;
use Magrathea2\ConfigApp;
use Magrathea2\DB\Database;
use Magrathea2\MagratheaApiControl;
use Magrathea2\MagratheaHelper;
use Magrathea2\MagratheaPHP;
use MagratheaImages3\Images\ImageUploader;

class SystemApi extends MagratheaApiControl {

	public function Clean(): string {
		$q = @$_POST["q"];
		return Helper::Clean($q);
	}

	public function GetSettings(): array {
		$upload = ImageUploader::getMaximumFileUploadSize();
		return [
			"thumb_size" => ConfigApp::Instance()->Get("thumb_size"),
			"upload_limit_bytes" => $upload,
			"upload_limit" => MagratheaHelper::FormatSize($upload),
			"force_uuid" => boolval(Config::Instance()->Get("force_uuid")),
		];
	}

	public function GetChangelog(): array {
		$this->Cache("changelog");
		$filepath = MagratheaHelper::EnsureTrailingSlash(MagratheaPHP::Instance()->magRoot)."changelog.md";
		if (!file_exists($filepath)) return [];
		$content = file_get_contents($filepath);
		$lines = preg_split('/\r?\n/', $content);
		$changelog = [];
		$current = null;
		foreach ($lines as $line) {
			$line = trim($line);
			if (preg_match('/^## v\.([0-9.]+)/i', $line, $m) || preg_match('/^## ([0-9.]+)/i', $line, $m)) {
				if ($current) $changelog[] = $current;
				$current = ['version' => $m[1], 'date' => null, 'changes' => []];
			} elseif (preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})/', $line, $m)) {
				if ($current) $current['date'] = $m[1];
			} elseif (preg_match('/^- \*\*(new|fix|improvement|improvements):?\*\*\s*(.+)$/i', $line, $m)) {
				if ($current) $current['changes'][] = ['type' => strtolower($m[1]), 'description' => $m[2]];
			}
		}
		if ($current) $changelog[] = $current;
		return array_slice($changelog, 0, 5);
	}

	public function GetErrorCodes(): array {
		$this->Cache("error_codes");
		return ErrorCodes::Instance()->GetAll();
	}

	public function Validate(): array {
		$checks = [
			"max_upload_size" => $this->CheckMaxUploadSize(),
			"medias_path" => $this->CheckFolder(Config::Instance()->Get("medias_path")),
			"logs_path" => $this->CheckFolder(Config::Instance()->Get("logs_path")),
			"database" => $this->CheckDatabase(),
		];
		return [
			"valid" => !in_array(false, array_map(fn($status) => $status === "ok", $checks), true),
			"checks" => $checks,
		];
	}

	private function CheckMaxUploadSize(): string {
		$value = ConfigApp::Instance()->Get("max_upload_size");
		if(!$value) return "ok";
		return ImageUploader::IsValidSizeFormat($value) ? "ok" : "invalid";
	}

	private function CheckFolder(?string $path): string {
		if(empty($path) || !is_dir($path)) return "invalid";
		if(!is_writable($path)) return "permissions_failed";
		return "ok";
	}

	private function CheckDatabase(): string {
		try {
			$database = Database::Instance();
			$database->OpenConnectionPlease();
			$database->CloseConnectionThanks();
			return "ok";
		} catch(\Throwable $e) {
			return "fail";
		}
	}

}
