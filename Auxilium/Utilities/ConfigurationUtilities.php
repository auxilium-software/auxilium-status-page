<?php

namespace Auxilium\Utilities;

use Auxilium\Enumerators\EnvironmentVariable;
use Exception;
use Symfony\Component\Yaml\Yaml;

final class ConfigurationUtilities
{
    public static array $SystemConfiguration;
    public static array $UserConfiguration;


    public static function GetSystemConfiguration(): array
    {
        if (isset(self::$SystemConfiguration)) {
            return self::$SystemConfiguration;
        }

        $filePath = __DIR__ . "/../../Configuration/System/System.yaml";

        if ($filePath !== false) {
            $filePath = str_replace(search: '"', replace: '', subject: $filePath);
            if (file_exists($filePath)) {
                $temp = file_get_contents($filePath);
                self::$SystemConfiguration = Yaml::parse($temp);
                return self::$SystemConfiguration;
            }
            throw new Exception("Configuration file not found at " . $filePath);
        }
        throw new Exception("Configuration file not specified");
    }

    public static function GetUserConfiguration(): array
    {
        if (isset(self::$UserConfiguration)) {
            return self::$UserConfiguration;
        }

        $filePath = getenv(EnvironmentVariable::CONFIG_FILE_LOCATION->value);

        if ($filePath !== false) {
            $filePath = str_replace(search: '"', replace: '', subject: $filePath);
            if (file_exists($filePath)) {
                $temp = file_get_contents($filePath);
                self::$UserConfiguration = Yaml::parse($temp);
                return self::$UserConfiguration;
            }
            throw new Exception("Configuration file not found at " . $filePath);
        }
        throw new Exception("Configuration file not specified");
    }
}
