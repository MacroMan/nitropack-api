<?php
// Disclaimer mtimes are only accurate for the final entries. But mtimes for parent directories (more than 1 level up the hierarchy) might not be updated correctly when children have been modified
// This is also a bad design for emulating a file system performance wise. Only use this driver when you need shared storage between multiple servers. On a single server with an SSD using the Disk diver is a better idea.
namespace NitroPack\StorageDriver;

class Redis {
    private $redis;

    private function preparePathInput($path) {
        return $path == DIRECTORY_SEPARATOR ? $path : rtrim($path, DIRECTORY_SEPARATOR);
    }

    public function __construct($host = "127.0.0.1", $port = 6379, $password = NULL, $db = NULL) {
        $this->redis = new \Redis();
        $this->redis->connect($host, $port);

        if ($password !== NULL) {
            $this->redis->auth($password);
        }

        if ($db !== NULL) {
            $this->redis->select($db);
        }
    }

    public function getOsPath($parts) {
        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    public function touch($path) {
        $path = $this->preparePathInput($path);
        $parent = dirname($path);
        $key = basename($path);
        if ($this->isDir($parent)) {
            $this->redis->hSet($parent, "::mtime::" . $key, time());
            $this->redis->hSetNx($parent, "::content::" . $key, "");
            return true;
        } else {
            return false;
        }
    }

    public function setContent($path, $content) {
        $path = $this->preparePathInput($path);
        if ($this->isDir($path)) {
            return false;
        } else {
            try {
                //TODO: Create parent dir if it doesn't exist. This can impact performance though. Maybe make it optional
                $dir = dirname($path);
                $file = basename($path);
                $this->redis->hMSet($dir, array(
                    "::content::" . $file => $content,
                    "::mtime::" . $file => time()
                ));
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
    }

    public function createDir($dir) {
        $dir = $this->preparePathInput($dir);
        $now = time();
        $childDir = NULL;
        $numDirsCreated = 0;
        try {
            while ($childDir !== "" && !$this->exists($dir)) {
                $this->redis->hSet($dir, "::self::ctime::", $now);
                $numDirsCreated++;
                if ($childDir) {
                    $this->touch($this->getOsPath(array($dir, $childDir)));
                }
                $childDir = basename($dir);
                $dir = dirname($dir);
            }
            if ($numDirsCreated > 0 && $childDir) {
                $this->touch($this->getOsPath(array($dir, $childDir)));
            }
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    public function deletePath($path) {
        $path = $this->preparePathInput($path);
        $dirKey = dirname($path);
        $fileName = basename($path);

        try {
            $deleted = $this->redis->hDel($dirKey, "::content::" . $fileName, "::mtime::" . $fileName);
            if ($deleted) {
                $this->touch($dirKey);
            }
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    public function deleteFile($path) {
        return !$this->isDir($path) && $this->deletePath($path);
    }

    public function deleteDir($dir) {
        $dir = $this->preparePathInput($dir);
        try {
            if (!$this->isDir($dir)) return true;
            $this->trunkDir($dir) && $this->redis->unlink($dir) && $this->deletePath($dir);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    public function trunkDir($dir) {
        $dir = $this->preparePathInput($dir);
        if (!$this->isDir($dir)) return false;
        $success = false;
        try {
            $this->redis->eval('
local cursor = "0";
repeat
    local t = redis.call("SCAN", cursor, "MATCH", ARGV[1]);
    cursor = t[1];
    local list = t[2];
    for i = 1, #list do
        redis.call("UNLINK", list[i]);
    end;
until cursor == "0";
            ', array($this->getOsPath(array($dir, "*"))), 0);
            $success = true;
        } catch (\Exception $e) {
            // TODO: Log an error
        }
        return $success;
    }

    public function isDirEmpty($dir) {
        $dir = $this->preparePathInput($dir);
        return (int)$this->redis->hLen($dir) <= 1;
    }

    private function isDir($dir) {
        $dir = $this->preparePathInput($dir);
        return !!$this->redis->hLen($dir); // if this is a non-empty sorted set then it is a dir
    }

    public function dirForeach($dir, $callback) {
        $dir = $this->preparePathInput($dir);
        if (!$this->isDir($dir)) return false;
        $it = NULL;
        $prevScanMode = $this->redis->getOption(\Redis::OPT_SCAN);
        $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        try {
            while($entries = $this->redis->hScan($dir, $it, "::mtime::*")) {
                foreach($entries as $entry => $mtime) {
                    $entry = substr($entry, 9);//remove the ::mtime:: prefix
                    $path = $dir != DIRECTORY_SEPARATOR ? $this->getOsPath(array($dir, $entry)) : $dir . $entry;
                    call_user_func($callback, $path);
                }
            }
        } catch (\Exception $e) {
            // TODO: Log an error
            return false;
        } finally {
            $this->redis->setOption(\Redis::OPT_SCAN, $prevScanMode);
        }
        return true;
    }

    public function mtime($path) {
        $path = $this->preparePathInput($path);
        $dir = dirname($path);
        $file = basename($path);
        return $this->redis->hGet($dir, "::mtime::" . $file);
    }

    public function exists($path) {
        $path = $this->preparePathInput($path);
        $dir = dirname($path);
        $file = basename($path);
        return $this->redis->hExists($dir, "::mtime::" . $file);
    }

    public function getContent($path) {
        $path = $this->preparePathInput($path);
        if ($this->isDir($path)) {
            return false;
        } else {
            $dir = dirname($path);
            $file = basename($path);
            return $this->redis->hGet($dir, "::content::" . $file);
        }
    }

    public function rename($oldKey, $newKey, $innerCall = false) {
        $oldKey = $this->preparePathInput($oldKey);
        $newKey = $this->preparePathInput($newKey);
        if ($this->exists($newKey)) return false;

        $success = false;

        try {
            $isDir = $this->isDir($oldKey);
            if (!$isDir) {
                $content = $this->getContent($oldKey);
                $this->deleteFile($oldKey);
                $this->setContent($newKey, $content);
            } else {
                $this->deletePath($oldKey);
                $this->createDir($newKey);
                $this->redis->rename($oldKey, $newKey);
                $this->redis->eval('
local cursor = "0";
repeat
    local t = redis.call("SCAN", cursor, "MATCH", ARGV[1]);
    cursor = t[1];
    local list = t[2];
    for i = 1, #list do
        local s = list[i];
        local changed = s:gsub(ARGV[2], ARGV[3], 1);
        redis.call("RENAME", s, changed);
    end;
until cursor == "0";
                ', array($this->getOsPath(array($oldKey, "*")), $oldKey . DIRECTORY_SEPARATOR, $newKey . DIRECTORY_SEPARATOR), 0);
            }

            $success = true;
        } catch (\Exception $e) {
            // TODO: Log an error
        }
        return $success;
    }
}
