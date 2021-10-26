#!/usr/bin/env php
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

if (!is_dir("releases")) {
    print "Creating release directory\n";
    mkdir("releases");

} else if (is_file("releases/sqsync")) {
    print "Removing old release\n";
    unlink("releases/sqsync");
}

print "Creating phar archive file\n";
$phar = new Phar("releases/sqsync.phar", 0, "sqsync.phar");
$phar->startBuffering(); // Allows us to modify the stub content

/*
 * buildFromDirectory() does not keep the src/vendor directory,
 * So we do it like this and use addFile() instead.
 */
print "Packing files\n";
foreach (["src", "vendor/imphp/base/src", "vendor/imphp/util/src"] as $dir) {
    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, FilesystemIterator::FOLLOW_SYMLINKS|FilesystemIterator::SKIP_DOTS|FilesystemIterator::CURRENT_AS_PATHNAME),
                            RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $file) {
        if (is_file($file) && preg_match("/\.php$/", $file)) {
            print " - Adding $file\n";
            $phar->addFile($file, $file);
        }
    }
}

// Make the phar executable
print "Configuring default stub\n";
$stub = "#!/usr/bin/env php \n";
$stub .= $phar->createDefaultStub("src/stub.php");
$phar->setStub($stub);
$phar->stopBuffering();

print "Setting permissions\n";
chmod("releases/sqsync.phar", 0777);

/*
 * The Phar class must have '.phar' as an extension
 * for some reason. But it's not required to actually run
 * the file after.
 */
rename("releases/sqsync.phar", "releases/sqsync");

print "Done!\n";
