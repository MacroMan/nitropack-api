<?php

namespace NitroPack\StorageDriver;

class Disk {
    public function getOsPath($parts) {
        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    public function deleteFile($path) {
        return @unlink($path);
    }

    public function createDir($dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        return true;
    }

    public function deleteDir($dir) {
        if (!is_dir($dir)) return true;
        return $this->trunkDir($dir) && rmdir($dir);
    }

    public function trunkDir($dir) {
        if (!is_dir($dir)) return true;
        $dh = opendir($dir);
        if ($dh === false) return false;

        while (false !== ($entry = readdir($dh))) {
            if ($entry == "." || $entry == "..") continue;
            $path = $this->getOsPath(array($dir, $entry));
            if (is_dir($path)) {
                if (!$this->deleteDir($path)) {
                    closedir($dh);
                    return false;
                }
            } else {
                if (!unlink($path)) {
                    closedir($dh);
                    return false;
                }
            }
        }
        closedir($dh);

        return true;
    }

    public function isDirEmpty($dir) {
        if (!is_dir($dir)) return false;
        $dh = opendir($dir);
        if ($dh === false) return false;

        $isEmpty = true;
        while (false !== ($entry = readdir($dh))) {
            if ($entry == "." || $entry == "..") continue;
            $isEmpty = false; // first entry which is not "." or ".." means the dir is not empty
            break;
        }
        closedir($dh);

        return $isEmpty;
    }

    public function dirForeach($dir, $callback) {
        if (!is_dir($dir)) return false;
        $dh = opendir($dir);
        if ($dh === false) return false;

        while (false !== ($entry = readdir($dh))) {
            if ($entry == "." || $entry == "..") continue;
            call_user_func($callback, $this->getOsPath(array($dir, $entry)));
        }
        closedir($dh);
        return true;
    }

    public function mtime($filePath) {
        return @filemtime($filePath);
    }

    public function touch($filePath) {
        return @touch($filePath);
    }

    public function exists($filePath) {
        return file_exists($filePath);
    }

    public function getContent($filePath) {
        return file_get_contents($filePath);
    }

    public function setContent($file, $content) {
        return @file_put_contents($file, $content);
    }

    public function rename($oldName, $newName) {
        return @rename($oldName, $newName);
    }
}
