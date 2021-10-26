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

const SQ_VERSION = "1.0.0";

const FLAG_HELP = 0x01;
const FLAG_TEST = 0x02;
const FLAG_QUIET = 0x04;
const FLAG_DELETE = 0x08;
const FLAG_YES = 0x10;
const FLAG_GZIP = 0x20;
const FLAG_NOHASH = 0x40;
const FLAG_NOMOD = 0x80;

require __DIR__."/../vendor/imphp/base/src/ImClassLoader.php";

use im\ImClassLoader;
use im\util\ArgV;
use im\util\Struct;
use im\util\Vector;
use im\io\FileStream;
use sqsync\misc\Log;

$loader = ImClassLoader::load();
$loader->addBasePath( __DIR__ . "/../vendor/imphp/util/src" );
$loader->addBasePath( __DIR__ );
$loader->addClassPrefix("sqsync\\", "");

$cfg = Struct::factory("flags", "src", "dst", "filters");
$cfg->fill(0, null, null, new Vector());
$cfg->setOnInvoke(function(string $path): bool {
    foreach ($this->filters as $filter) {
        if (preg_match($filter, $path)) {
            return true;
        }
    }

    return false;
});

$argv = new ArgV(null, ["help", "version", "skip-mod", "skip-hash"]);

foreach ($argv->getFlags() as $flag) {
    switch ($flag) {
        case "h":
        case "help":
            $cfg->flags |= FLAG_HELP; break;

        case "v":
        case "version":
            print SQ_VERSION."\n"; exit(0);

        case "t":
            $cfg->flags |= FLAG_TEST; break;

        case "q":
            $cfg->flags |= FLAG_QUIET; break;

        case "d":
            $cfg->flags |= FLAG_DELETE; break;

        case "y":
            $cfg->flags |= FLAG_YES; break;

        case "c":
            $cfg->flags |= FLAG_GZIP; break;

        case "skip-hash":
            $cfg->flags |= FLAG_NOHASH; break;

        case "skip-mod":
            $cfg->flags |= FLAG_NOMOD; break;

        default:
            Log::write("Unknown argument '-%s'", $flag, Log::LOG_K);
    }
}

foreach ($argv->getOptions() as $name => $value) {
    switch ($name) {
        case "log":
            $logfile = realpath($value);

            if ($logfile === FALSE && !(file_exists($logfile) || file_exists(dirname($logfile)))) {
                Log::write("The defined log path is not valid", Log::LOG_K);

            } else if ((file_exists($logfile) && !is_writable($logfile)) || (!file_exists($logfile) && !is_writable(dirname($logfile)))) {
                Log::write("The log path '%s' must be writable", $logfile, Log::LOG_K);

            } else {
                Log::setLogPath($logfile);
            }

            break;

        case "filter":
            if (substr($value, 0, 3) != "rx:") {
                if (is_file($value)) {
                    $patterns = [
                        "/^\\\[*]\\\[*]($|\/)/",
                        "/\/\\\[*]\\\[*]($|(?=\/))/",
                        "/\\\[*]\\\[*]/",
                        "/\\\[*]/"
                    ];

                    $replace = [
                        "(.+($|/))?",
                        "((^|/).+)?",
                        "(.+)?",
                        "([^/]+)?"
                    ];

                    $stream = new FileStream($value, 'r');

                    while (($line = $stream->readLine()) != null) {
                        $line = trim($line);

                        if (!empty($line)) {
                            if (substr($line, 0, 3) == "rx:") {
                                $cfg->filters->add( ($line = trim(substr($line, 3))) );

                                if (@preg_match($line, "") === FALSE) {
                                    Log::write("The filter regexp '%s' is not valid", $line, Log::LOG_K);
                                }

                            } else {
                                $cfg->filters->add( "~".preg_replace($patterns, $replace, preg_quote(rtrim(str_replace("\\", "//", $line), "/")))."(/|$)~" );
                            }
                        }
                    }

                    $stream->close();

                } else {
                    Log::write("The filter file '%s' does not exist", $value, Log::LOG_K);
                }

            } else if (@preg_match(($line = trim(substr($value, 3))), "") === FALSE) {
                Log::write("The filter regexp '%s' is not valid", $line, Log::LOG_K);

            } else {
                $cfg->filters->add($line);
            }

            break;

        default:
            Log::write("Unknown argument '--%s'", $name, Log::LOG_K);
    }
}

foreach ($argv->getOperands() as $pos => $oprand) {
    switch ($pos) {
        case 0:
            $cfg->src = ($oprand = realpath($oprand)); break;

        case 1:
            $cfg->dst = ($oprand = realpath($oprand)); break;

        default:
            Log::write("Unknown argument '%s'", $oprand, Log::LOG_K);
    }

    if (empty($oprand) || !is_dir($oprand)) {
        Log::write("%s must be a directory", ($pos == 0 ? "Source" : "Destination"), Log::LOG_K);

    } else if (!is_readable($oprand)) {
        Log::write("%s must be readable", ($pos == 0 ? "Source" : "Destination"), Log::LOG_K);

    } else if ($pos == 1 && !is_writable($oprand)) {
        Log::write("Destination must be readable", Log::LOG_K);
    }
}

if (($cfg->flags & FLAG_HELP) != 0 || $cfg->src === NULL || $cfg->dst === NULL) {
    print "SQSync version ".SQ_VERSION."\n\n";
    print "Syntax: php " . $argv->getScriptName() . " -dy --log <path> /src /dst\n\n";
    print "Sync files from src to dst\n\n";
    print "\t-d: Delete files that does not exist in src\n";
    print "\t-y: Don't ask to continue\n";
    print "\t-q: Quiet, only print to log file, except errors\n";
    print "\t-c: Compress the files that are going into dst\n";
    print "\t-t: Test run, just print without performing any actual actions\n\n";
    print "\t--skip-hash: Do not compare file hash (faster)\n";
    print "\t--skip-mod: Do not sync permissions\n";
    print "\t--filter <rx:regexp|file>: Filter file paths\n";
    print "\t--log <path>: Path to the log file/dir\n";
    print "\t--help, -h: Print this screen\n\n";
    print "\t--version, -v: Print the current SQSync version\n\n";

    exit(0);

} else if (($cfg->flags & FLAG_YES) == 0) {
    print "\nFrom: ".$cfg->src."\nTo: ".$cfg->dst."\n\n";
    print "Continue? [Y/N]: ";

    $line = fgets(STDIN);

    if (strtolower(trim($line)) == "n") {
        exit(0);
    }
}
