# SQSync

This is a one-way file synchronization tool with optional compression.

### Usage

```sh
sqsync -cy /path/to/src /path/to/dest
```

The example above will synchronize everything from `src` to `dest`. Anything copied to `dest` will be compressed.

Should you ever want to copy everything back, simply reverse the directories and skip the "-c" flag (compress).

```sh
sqsync -y /path/to/dest /path/to/src
```

### Filters

You can add filters to skip certain files or folders from being synchronized.

__filter.txt__
```
folder1/**/file.*
folder2/*/.*

rx:/^\/folder\/.*/
```

You can use simple filters with `*` or prepend `rx:` to a regexp that will be checked via `preg_match`.

Now simply add this file to the `--filter` option.

```sh
sqsync -cy --filer filter.txt /path/to/src /path/to/dest
```
