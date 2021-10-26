<?php declare(strict_types=1);
/*
 * This file is part of the SqueezeSync Project: https://github.com/dk-zero-cool/SqueezeSync
 *
 * Copyright (c) 2021 Daniel Bergløv, License: MIT
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software
 * and associated documentation files (the "Software"), to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO
 * THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR
 * THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace sqsync\misc;

use im\exc\StreamException;
use im\io\Stream;
use im\io\FileStream;
use im\io\GZipStream;
use im\util\res\PropertyAccess;

/**
 *
 */
class FileHeader {

    use PropertyAccess;

    /** Defiens a Unit that does not actually exist on disk */
    const /*int*/ T_VIRTUAL = -1;

    /** * */
    const /*int*/ T_LINK = 0;

    /** * */
    const /*int*/ T_DIR = 1;

    /** * */
    const /*int*/ T_FILE = 2;

    /** * */
    const /*int*/ DIFF_TYPE = 0b000001;

    /** * */
    const /*int*/ DIFF_TARGET = 0b000010;

    /** * */
    const /*int*/ DIFF_HASH = 0b000100;

    /** * */
    const /*int*/ DIFF_MODE = 0b001000;

    /** * */
    const /*int*/ DIFF_MTIME = 0b010000;

    /** * */
    const /*int*/ DIFF_SIZE = 0b100000;

    /** * */
    private int $mSize = -1;

    /** * */
    private ?string $mHash = null;

    /** * */
    private string $mFile;

    /**
     *
     */
    public function __construct(string $file) {
        $this->mFile = str_replace("\\", "/", $file);

        if (is_file($file) && !is_link($file)) {
            $reader = $this->openReader();

            if ($reader instanceof GZipStream) {
                $header = $reader->readHeader();

                if (!empty($header) && $header[0] == "\x06") {
                    $this->mHash = substr($header, 1);
                }

                $this->mSize = $reader->getRealLength();

            } else {
                $this->mSize = $reader->getLength();
            }

            $reader->close();
        }
    }

    /**
     *
     */
    public function openReader(): Stream {
        $reader = new FileStream($this->mFile, "rb");

        try {
            return new GZipStream($reader);

        } catch (StreamException $e) {}

        return $reader;
    }

    /**
     *
     */
    public function openWriter(bool $compress = false, string $hash = null): Stream {
        $writer = new FileStream($this->mFile, "wb");

        if ($compress) {
            $gzip = new GZipStream($writer);

            if ($hash != null) {
                $data = "\x06$hash";

                $gzip->allocHeader(strlen($data));
                $gzip->writeHeader($data);
            }

            return $gzip;
        }

        return $writer;
    }

    /**
     * Get the file size
     */
    protected function getSize(): int {
        if ($this->mSize != -1) {
            return $this->mSize;

        } else if (file_exists($this->mFile)) {
            // Directory or Link
            return filesize($this->mFile);
        }

        return 0;
    }

    /**
     *
     */
    protected function getHash(): ?string {
        if ($this->mHash !== null) {
            return $this->mHash;

        } else if (is_file($this->mFile) && !is_link($this->mFile)) {
            return md5_file($this->mFile, true);
        }

        return null;
    }

    /**
     *
     */
    protected function getPath(): string {
        return $this->mFile;
    }

    /**
     *
     */
    protected function getType(): int {
        if (is_link($this->mFile)) {
            return static::T_LINK;

        } else if (is_dir($this->mFile)) {
            return static::T_DIR;

        } else if (is_file($this->mFile)) {
            return static::T_FILE;

        } else {
            return static::T_VIRTUAL;
        }
    }

    /**
     *
     */
    protected function getLinkTarget(): ?string {
        if (is_link($this->mFile)) {
            return readlink($this->mFile);
        }

        return null;
    }

    /**
     *
     */
    protected function get_isReadable(): bool {
        if (file_exists($this->mFile) && !is_link($this->mFile)) {
            return is_readable($this->mFile);
        }

        return true;
    }

    /**
     *
     */
    protected function get_isWritable(): bool {
        if (!is_link($this->mFile) && file_exists($this->mFile) && !is_writable($this->mFile)) {
            return false;

        } else if (is_dir( ($parent = dirname($this->mFile)) )) {
            // We cannot delete files from parent dirs if that to, is not writable
            return is_writable($parent);
        }

        return true;
    }

    /**
     *
     */
    protected function get_isOwner(): bool {
        // This only works on Unix-like
        if (function_exists("posix_getuid") && file_exists($this->mFile) && !is_link($this->mFile)) {
            $uid = posix_getuid();

            if ($uid != 0 && $uid != fileowner($this->mFile)) {
                return false;
            }
        }

        return true;
    }

    /**
     *
     */
    public function compare(FileHeader $target, bool $cmp_hash = false): int {
        $flags = 0;

        if ($this->type != $target->type) {
            $flags |= static::DIFF_TYPE;
        }

        if ($this->type == static::T_LINK && ($flags & static::DIFF_TYPE) == 0 && $this->linkTarget != $target->linkTarget) {
            $flags |= static::DIFF_TARGET;

        } else if ($this->type == static::T_FILE && $target->type == static::T_FILE) {
            if ($this->size != $target->size) {
                $flags |= static::DIFF_SIZE;
            }

            if ($cmp_hash && strcmp($this->hash, $target->hash) !== 0) {
                $flags |= static::DIFF_HASH;
            }
        }

        $stat1 = $this->type == static::T_LINK ? @lstat($this->path) : ($this->type != static::T_VIRTUAL ? @stat($this->path) : null);
        $stat2 = $target->type == static::T_LINK ? @lstat($target->path) : ($this->type != static::T_VIRTUAL ? @stat($target->path) : null);

        if ($stat1 != null && $stat2 != null) {
            $uid = function_exists("posix_getuid") ? posix_getuid() : 0;

            /*
             * If this script is running as a regular user, any copied files and folders
             * will get the running user as owner. The script will not add another user as owner
             * while not running as root. This means of cause that the owner in these circomstances may differ
             * beween 'src' and 'dst'. So to avoid the script trying to fix it every time, which it will not do,
             * we do not report any issues if owner of a file is the running user while not root.
             */
            if ($stat1["mode"] != $stat2["mode"]
                    || (($uid == 0 && $stat1["uid"] != $stat2["uid"]) || $stat1["gid"] != $stat2["gid"])) {

                $flags |= static::DIFF_MODE;
            }

            if (!$cmp_hash && $this->type == static::T_FILE
                    && $target->type == static::T_FILE
                    && $stat1["mtime"] != $stat2["mtime"]) {

                $flags |= static::DIFF_MTIME;
            }

        } else if ($stat1 != null || $stat2 != null) {
            $flags |= static::DIFF_MODE;
        }

        return $flags;
    }

    /**
     *
     */
    public function touch(FileHeader $target): bool {
        if ($this->type == static::T_VIRTUAL
                || $this->type != $target->type) {

            return false;
        }

        $stat = $this->type == static::T_LINK ? lstat($this->path) : stat($this->path);
        $uid = function_exists("posix_getuid") ? posix_getuid() : 0;
        $result = $stat !== false;

        if ($result) {
            if ($target->type == static::T_LINK) {
                $result = $result ? lchown($target->path, $uid == 0 ? $stat["uid"] : $uid) : false;
                $result = $result ? lchgrp($target->path, $stat["gid"]) : false;

            } else {
                $result = $result ? chown($target->path, $uid == 0 ? $stat["uid"] : $uid) : false;
                $result = $result ? chgrp($target->path, $stat["gid"]) : false;
                $result = $result ? touch($target->path, $stat["mtime"], $stat["atime"]) : false;
                $result = $result ? chmod($target->path, ($uid == 0 ? 07777 : 0777) & $stat["mode"]) : false;
            }
        }

        return $result;
    }
}
