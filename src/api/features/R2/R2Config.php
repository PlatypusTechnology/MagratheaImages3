<?php
namespace MagratheaImages3\R2;

use Magrathea2\Config;
use Magrathea2\ConfigFile;
use Magrathea2\MagratheaPHP;

class R2Config {

	// Overridden by tests to point at a scratch directory instead of the real
	// `configs/` folder, so a test run never has to touch (or risk overwriting)
	// a developer's real r2.conf.
	private static ?string $configRootOverride = null;

	public static function SetConfigRootForTests(?string $path): void {
		self::$configRootOverride = $path;
	}

	private static function GetConfigRootPath(): string {
		return self::$configRootOverride ?? MagratheaPHP::Instance()->GetConfigRoot();
	}

	/**
	 * Returns the config section for the current environment, or an empty
	 * array if `r2.conf` is absent -- an absent file must produce a clean
	 * "disabled", never a `MagratheaConfigException` from `ConfigFile`.
	 */
	public static function Get(): array {
		$path = self::GetConfigRootPath();
		$file = $path."/r2.conf";
		if(!file_exists($file)) return [];
		$section = (new ConfigFile())->SetPath($path)->SetFile("r2.conf")
			->GetConfigSection(Config::Instance()->GetEnvironment());
		return $section ?: [];
	}

	public static function IsEnabled(): bool {
		$c = self::Get();
		return !empty($c["enabled"])
			&& !empty($c["account_id"])
			&& !empty($c["bucket"])
			&& !empty($c["access_key"])
			&& !empty($c["secret_key"]);
	}

	public static function GetAccountId(): ?string {
		return self::Get()["account_id"] ?? null;
	}

	public static function GetBucket(): ?string {
		return self::Get()["bucket"] ?? null;
	}

	public static function GetAccessKey(): ?string {
		return self::Get()["access_key"] ?? null;
	}

	public static function GetSecretKey(): ?string {
		return self::Get()["secret_key"] ?? null;
	}

	public static function GetEndpoint(): ?string {
		$accountId = self::GetAccountId();
		if(empty($accountId)) return null;
		return "https://".$accountId.".r2.cloudflarestorage.com";
	}

	public static function GetBatchSize(): int {
		return intval(self::Get()["batch_size"] ?? 200);
	}

	public static function GetMaxAttempts(): int {
		return intval(self::Get()["max_attempts"] ?? 5);
	}

	public static function GetDeleteCap(): int {
		return intval(self::Get()["delete_cap"] ?? 100);
	}

}
