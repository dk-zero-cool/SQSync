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

require "header.php";

use sqsync\misc\FileHeader;
use sqsync\misc\Log;
use im\util\CLIProgress;
use im\util\Vector;
use im\util\Map;
use im\util\Struct;
use im\io\FileStream;
use im\io\GZipStream;

LOG::write("SQSync version %s", SQ_VERSION);
LOG::write("Sync started at %s", date("Y-m-d H:i"));
LOG::write("Sync from '%s' to '%s'", $cfg->src, $cfg->dst);

$stub = Struct::factory("syncted", "src", "dst");
$stub->fill(new Map(), null, null);
$stub->setOnInvoke(function(string $path, bool $insert = FALSE): bool {
    $dirname = dirname($path);
    $basename = basename($path);

    if (!$insert) {
        return $this->syncted->isset($dirname)
                    && $this->syncted->get($dirname)->contains($basename);

    } else if (!$this->syncted->isset($dirname)) {
        $this->syncted->set($dirname, new Vector());
    }

    $this->syncted->get($dirname)->add($basename);

    return TRUE;
});

$itflags = FilesystemIterator::SKIP_DOTS|FilesystemIterator::CURRENT_AS_PATHNAME;
$iterators = [
    "leftSync" => [
        "path" => $cfg->dst,
        "flags" => RecursiveIteratorIterator::CHILD_FIRST
    ],

    "rightSync" => [
        "path" => $cfg->src,
        "flags" => RecursiveIteratorIterator::SELF_FIRST
    ]
];

$progress = new CLIProgress();

foreach ($iterators as $round => $setup) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($setup["path"], $itflags), $setup["flags"]);

    foreach($iterator as $filePath) {
        $localPath = trim(substr($filePath, strlen($setup["path"])), "/");

        /*
         * Check to see if this file was handled by the first round.
         */
        if ($stub($localPath)) { // Check skipped paths
            continue;

        } else if ($cfg($localPath)) { // Check ignore filters
            continue;
        }

        /*
         * Prepare file headers for the current files and round
         */
        Log::write("Checking '%s'", $localPath);

        try {
            $stub->src = new FileHeader($cfg->src . "/" . $localPath);
            $stub->dst = new FileHeader($cfg->dst . "/" . $localPath);

        } catch (Exception $exc) {
            Log::write("Failed to access '%s'.", $localPath, $exc);
        }

        if (($diff = $stub->src->compare($stub->dst, ($cfg->flags & FLAG_NOHASH) == 0)) == FileHeader::DIFF_MODE) { // Only permissions/ownership differ
            if (($cfg->flags & FLAG_NOMOD) == 0) {
                Log::write("Setting permissions on '%s'", $localPath);
                $stub($localPath, true); // Add to skipped paths

                if (($cfg->flags & FLAG_TEST) == 0 && !$stub->src->touch($stub->dst)) {
                    Log::write("Failed to change permissions", Log::LOG_E);
                }
            }

        } else if (($diff & FileHeader::DIFF_TYPE) != 0
                    && $stub->dst->type != FileHeader::T_VIRTUAL) { // Possibly missing src files

            if (($cfg->flags & FLAG_DELETE) != 0) {
                Log::write("Deleting '%s'.", $localPath);

                if (($cfg->flags & FLAG_TEST) == 0
                        && !(($stub->dst->type == FileHeader::T_DIR && rmdir($stub->dst->path))
                                || ($stub->dst->type != FileHeader::T_DIR && unlink($stub->dst->path)))) {

                    Log::write("Failed to remove '%s'.", $localPath, Log::LOG_E);
                    $stub($localPath, true); // Add to skipped paths
                }

            } else if ($stub->src->type != FileHeader::T_VIRTUAL) {
                Log::write("Cannot sync '%s'. It already exists.", $localPath, Log::LOG_E);
                $stub($localPath, true); // Add to skipped paths

            } else {
                Log::write("Not deleting '%s'.", $localPath, Log::LOG_W);
                $stub($localPath, true); // Add to skipped paths
            }

        } else if ($diff > 0) {
            Log::write("Syncing '%s'", $localPath);
            $stub($localPath, true); // Add to skipped paths

            if ($stub->src->type == FileHeader::T_LINK) {
                if (($cfg->flags & FLAG_TEST) == 0
                        && (!(($stub->dst->type != FileHeader::T_LINK) || unlink($stub->dst->path))
                                && symlink($stub->src->linkTarget, $stub->dst->path))) {

                    Log::write("Failed to update link target on '%s'.", $localPath, Log::LOG_E);
                }

            } else if ($stub->src->type == FileHeader::T_DIR) {
                if (($cfg->flags & FLAG_TEST) == 0
                        && !mkdir($stub->dst->path)) {

                    Log::write("Failed to create directory '%s'.", $localPath, Log::LOG_E);
                    $cfg->filters->add("/^\/".preg_quote($localPath)."\//"); // No need to sync content of this directory
                }

            } else if (($cfg->flags & FLAG_TEST) == 0) {
                $compress = $stub->src->size > 256
                                && ($cfg->flags & FLAG_GZIP) != 0;

                try {
                    $reader = $stub->src->openReader();
                    $writer = $stub->dst->openWriter($compress, $stub->src->hash);

                } catch (Exception $exc) {
                    Log::write("Failed to copy '%s'.", $localPath, $exc);
                }

                $bytes = 0;
                $exc = null;

                if (($cfg->flags & FLAG_QUIET) == 0) {
                    $progress->start($stub->src->size, "Copying:");
                }

                while (($line = $reader->read(16384)) != NULL) {
                    try {
                        $bytes += $writer->write($line);

                    } catch (Exception $exc) {
                        break;
                    }

                    if (($cfg->flags & FLAG_QUIET) == 0) {
                        $progress->update($bytes);
                    }
                }

                if (($cfg->flags & FLAG_QUIET) == 0) {
                    $progress->stop();
                }

                if ($bytes < $stub->src->size) {
                    Log::write("Failed to copy '%s'.", $localPath, Log::LOG_E);

                    if ($exc != null) {
                        Log::writeException($exc);
                    }
                }

                $writer->close();
                $reader->close();
            }

            if (($cfg->flags & FLAG_TEST) == 0 && !$stub->src->touch($stub->dst)) {
                Log::write("Failed to change permissions", Log::LOG_E);
            }
        }
    }
}
