<?php

namespace NitroPack;

class Filesystem {
    public static $storageDriver = NULL;

    public static function setStorageDriver($driver) {
        Filesystem::$storageDriver = $driver;
    }

    public static function getStorageDriver() {
        if (Filesystem::$storageDriver === NULL) {
            Filesystem::$storageDriver = new StorageDriver\Disk();
        }

        return Filesystem::$storageDriver;
    }

    public static function getOsPath($parts) {
        return Filesystem::getStorageDriver()->getOsPath($parts);
    }

    public static function deleteFile($path) {
        if (self::fileExists($path)) {
            return Filesystem::getStorageDriver()->deleteFile($path);
        }

        return true;
    }

    public static function createDir($dir) {
        return Filesystem::getStorageDriver()->createDir($dir);
    }

    public static function deleteDir($dir) {
        return Filesystem::getStorageDriver()->deleteDir($dir);
    }

    public static function trunkDir($dir) {
        return Filesystem::getStorageDriver()->trunkDir($dir);
    }

    public static function isDirEmpty($dir) {
        return Filesystem::getStorageDriver()->isDirEmpty($dir);
    }

    public static function createCacheDir($dir) {
        if (!self::createDir($dir . "/mobile")
            || !self::createDir($dir . "/tablet")
            || !self::createDir($dir . "/desktop")
        ) {
            return false;
        }

        return true;
    }

    public static function dirForeach($dir, $callback) {
        return Filesystem::getStorageDriver()->dirForeach($dir, $callback);
    }

    public static function fileMTime($filePath) {
        return self::fileExists($filePath) ? Filesystem::getStorageDriver()->mtime($filePath) : 0;
    }

    public static function touch($filePath) {
        return Filesystem::getStorageDriver()->touch($filePath);
    }

    public static function fileGetHeaders($filePath) {
        return self::fileGetAll($filePath)->headers;
    }

    public static function fileGetContents($filePath) {
        return self::fileGetAll($filePath)->content;
    }

    public static function fileSize($filePath) {
        return self::fileGetAll($filePath)->size;
    }

    public static function fileExists($filePath) {
        return Filesystem::getStorageDriver()->exists($filePath);
    }

    public static function fileGetAll($filePath) {
        if (!self::fileExists($filePath)) throw new \Exception("File not found: $filePath");

        $res = new \stdClass();
        $res->headers = [];
        $res->content = "";
        $res->size = 0;

        list($res->headers, $res->content) = self::explodeByHeaders(Filesystem::getStorageDriver()->getContent($filePath));
        $res->size = strlen($res->content);

        return $res;
    }

    public static function filePutContents($file, $content, $headers = NULL) {
        if ($headers === NULL) {
            return Filesystem::getStorageDriver()->setContent($file, $content);
        } else if (is_array($headers)) {
            $headerStr = implode("\r\n", $headers) . "\r\n\r\n";
            return Filesystem::getStorageDriver()->setContent($file, $headerStr . $content);
        } else if (is_string($headers)) {
            return Filesystem::getStorageDriver()->setContent($file, $headers . $content);
        } else {
            return Filesystem::getStorageDriver()->setContent($file, $content);
        }
    }

    public static function rename($oldName, $newName) {
        if (self::fileExists($oldName)) {
            return Filesystem::getStorageDriver()->rename($oldName, $newName);
        }

        return false;
    }

    private static function explodeByHeaders($content) {
        $headers = [];
        $pos = strpos($content, "\r\n\r\n");
        if ($pos !== false) {
            $headerStr = substr($content, 0, $pos);
            $content = substr($content, $pos+4);
            if ($headerStr) {
                $lines = explode("\r\n", $headerStr);
                foreach ($lines as $line) {
                    $parts = explode(":", $line);
                    $name = strtolower(array_shift($parts));
                    $value = trim(implode(":", $parts));
                    if (!empty($headers[$name])) {
                        if (!is_array($headers[$name])) {
                            $headers[$name] = array($headers[$name]);
                        }
                        $headers[$name][] = $value;
                    } else {
                        $headers[$name] = $value;
                    }
                }
            }
        }

        return array($headers, $content);
    }
}
