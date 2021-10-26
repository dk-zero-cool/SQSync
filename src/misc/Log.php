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

use Exception;
use im\io\Stream;
use im\io\FileStream;
use im\io\RawStream;

/**
 *
 */
class Log {

    const LOG_V = "\x02\x8D\x03";
    const LOG_W = "\x02\x8F\x03";
    const LOG_E = "\x02\x8E\x03";
    const LOG_K = "\x02\x8E\x18";

    /** * */
    private static ?Stream $StdErr = NULL;

    /** * */
    private static ?Stream $StdOut = NULL;

    /** * */
    private static ?Stream $LogFile = NULL;

    /** * */
    private static int $Warnings = 0;

    /** * */
    private static int $Errors = 0;

    /** * */
    private static bool $Quiet = FALSE;

    /**
     *
     */
    public static function setQuiet(bool $flag): void {
        static::$Quiet = $flag;
    }

    /**
     *
     */
    public static function numErrors(): int {
        return static::$Errors;
    }

    /**
     *
     */
    public static function numWarnings(): int {
        return static::$Warnings;
    }

    /**
     *
     */
    public static function setLogPath(string $file): bool {
        if (!file_exists($file) || is_file($file) || is_dir($file)) {
            if (is_dir($file)) {
                $file .= "/sqsync.log";
            }

            if (static::$LogFile !== NULL) {
                static::$LogFile->close();
            }

            static::$LogFile = new FileStream($file, "w");

            return TRUE;
        }

        return FALSE;
    }

    /**
     *
     */
    private static function getStream(): ?Stream {
        return static::$LogFile;
    }

    /**
     *
     */
    private static function getStdio(bool $error = FALSE): Stream {
        if ($error) {
            if (static::$StdErr == NULL) {
                static::$StdErr = new RawStream(STDERR);
            }

            return static::$StdErr;

        } else {
            if (static::$StdOut == NULL) {
                static::$StdOut = new RawStream(STDOUT);
            }

            return static::$StdOut;
        }
    }

    /**
     *
     */
    public static function write(string $msg, /*mixed*/ ...$args): void {
        $level = static::LOG_V;
        $msg .= "\n";
        $exc = null;

        if (count($args) > 0) {
            $last = $args[count($args)-1];

            if (is_object($last) && $last instanceof Exception) {
                $exc = $last;

            } else if (in_array($last, [static::LOG_V, static::LOG_W, static::LOG_E, static::LOG_K])) {
                $level = $last;

                array_pop($args);

                if ($level == static::LOG_W) {
                    static::$Warnings++;

                    $msg = "\tW: $msg";

                } else if ($level == static::LOG_E || $level == static::LOG_K) {
                    static::$Errors++;

                    $msg = "\tE: $msg";
                }
            }

            $msg = sprintf($msg, ...$args);
        }

        if ($level != static::LOG_V || !static::$Quiet) {
            $stdio = static::getStdio( $level != static::LOG_V );
            $stdio->write($msg);
        }

        $stream = static::getStream();
        if ($stream !== NULL) {
            $stream->write($msg);
        }

        if ($exc != null) {
            static::writeException($exc);

        } else if ($level == static::LOG_K) {
            exit(1);
        }
    }

    /**
     *
     */
    public static function writeException(Exception $e): void {
        static::write("%s\n\t%s\n\t%d", $e->getMessage(), $e->getFile(), $e->getLine(), static::LOG_K);
    }
}
