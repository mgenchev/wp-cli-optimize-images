WP-CLI Optimize Images

A global WP-CLI command for auditing, resizing and optimizing web image assets.

Raster images use TinyPNG when configured, with an automatic local fallback powered by Sharp/libvips. SVG files are optimized locally with SVGO.

Original files are never modified. Processed files are written to a separate output directory.

Features

JPEG, PNG, WebP, AVIF and SVG support

TinyPNG optimization with automatic local fallback

Sharp/libvips local raster optimization

SVGO SVG optimization

Automatic proportional resize for raster images

Default maximum dimensions of 2880 × 2880 px

No upscaling

Configurable local quality, default 80

Recursive directory processing

Custom output directory

Extension filtering

Dry-run mode

SHA-256 cache with fast size + mtime checks

Automatic cache invalidation when processing settings change

sync mode for removed/changed files

audit report with size, formats, resize candidates and estimated processing time

Batch local processing

Concurrent TinyPNG requests

Progress display with ETA

Atomic cache writes

Temporary output protection and cleanup

Non-zero exit code when processing completes with failures

Global status, version and TinyPNG configuration commands

Requirements

PHP 8.1+

WP-CLI

Node.js 20.9+

npm

PHP cURL extension when using TinyPNG

TinyPNG API key is optional

Node.js is used for Sharp/libvips and SVGO. The local runtime is installed automatically when first required.

Installation

Install globally through WP-CLI:

wp package install https://github.com/mgenchev/wp-cli-optimize-images.git

Verify the command:

wp optimize-images version
wp optimize-images status

No WordPress installation is required to process files.

Quick Start

Optimize all supported files in a directory:

wp optimize-images ./images

By default:

raster images larger than 2880 × 2880 px are resized proportionally

smaller images are never enlarged

local raster quality is 80

output is written to a sibling optimized-images directory

unchanged files are skipped on future runs

Example:

images/
├── hero.jpg
├── logo.svg
└── team/
    └── member.webp

optimized-images/
├── hero.jpg
├── logo.svg
└── team/
    └── member.webp

Commands

Optimize

wp optimize-images ./images

Audit

Analyze a directory without changing files:

wp optimize-images audit ./images

The report includes:

total file count and size

raster and SVG counts

format breakdown

files over 1 MB

raster files that exceed the current resize threshold

largest files

a rough expected processing-time range

The time estimate is intentionally approximate. TinyPNG API and network speed can materially affect the real processing time.

Sync

Keep the output directory synchronized with the source:

wp optimize-images sync ./images

Sync will:

optimize new files

reprocess changed files

skip unchanged files

remove output files whose source no longer exists

remove empty output directories

Preview first:

wp optimize-images sync ./images --dry-run

Configure TinyPNG

wp optimize-images configure

The API key is stored globally in:

~/.wp-cli/optimize-images.json

If TINIFY_API_KEY exists, it takes precedence over the stored configuration.

Status

wp optimize-images status

Example:

WP-CLI Optimize Images 1.0.0

  PHP                8.3.6
  cURL               enabled
  Node.js            22.x.x
  TinyPNG            configured
  Sharp              ready (0.35.x)
  SVGO               ready (4.x)
  Default resize     2880 × 2880 px
  Default quality    80%
  Formats            jpg, jpeg, png, webp, avif, svg

Version

wp optimize-images version

Options

Quality

Local raster quality defaults to 80:

wp optimize-images ./images --quality=80

Accepted range:

1–100

Example:

wp optimize-images ./images --quality=70

--quality controls the local Sharp path. TinyPNG determines its own compression automatically. SVG optimization is unaffected.

Very low local quality values may produce visible artifacts.

Resize

Default bounding box:

2880 × 2880 px

Examples:

6000 × 4000 → 2880 × 1920
4000 × 6000 → 1920 × 2880
2500 × 1600 → unchanged

Custom maximum width:

wp optimize-images ./images --max-width=1920

Custom maximum height:

wp optimize-images ./images --max-height=1600

Custom bounding box:

wp optimize-images ./images --max-width=1920 --max-height=1920

Disable resize:

wp optimize-images ./images --no-resize

Resize values must be positive integers and cannot exceed 20,000 px.

SVG files are never resized.

Custom Output

wp optimize-images ./images --output=./dist/images

Absolute paths are supported as well.

The output directory cannot be inside the source directory.

Extension Filtering

wp optimize-images ./images --extensions=jpg,png,svg

Only SVG:

wp optimize-images ./images --extensions=svg

Dry Run

wp optimize-images ./images --dry-run

Dry-run mode:

does not modify files

does not remove files

does not call TinyPNG

does not consume TinyPNG credits

does not execute Sharp or SVGO optimization

Force

Ignore the cache and process all selected files again:

wp optimize-images ./images --force

Processing

Raster Files

When TinyPNG is configured:

source
  ↓
local resize if required
  ↓
TinyPNG
  ↓
output

If TinyPNG is unavailable or reaches an account/API limit, processing automatically continues locally with Sharp/libvips.

Without TinyPNG, raster files are processed entirely locally.

SVG Files

SVG files are always optimized locally with SVGO.

The configuration is intentionally conservative for production assets. It preserves important structures such as viewBox, IDs and accessible description content while removing unnecessary data where possible.

If an optimized local file would be larger than the source and no resize was required, the original file is used as the output instead.

Cache

The optimization cache is stored in the output directory:

optimized-images/.optimize-images-cache.json

It stores source hashes and processing settings.

For fast repeat runs, unchanged size + mtime values allow the command to skip a full SHA-256 read. If those values change, SHA-256 is used to verify the file content.

Changing processing settings such as quality or resize dimensions automatically invalidates the relevant cached raster output.

Cache writes are atomic: the new cache is written to a temporary file and moved into place only after the write succeeds.

Output Safety

Original source files are never modified.

Optimized output is prepared in temporary files and moved into place only after processing succeeds. Existing output is preserved if replacement fails.

Known temporary files and temporary directories are cleaned up during normal completion and on supported interruption/shutdown paths.

Progress and Results

During processing, the command shows a compact progress display:

Optimizing images · 17/20
█████████████████░░░  83%
1:04 elapsed · ETA ~0:10 /

The ETA is an approximation based on current-run processing samples, active work and concurrency.

Completed files are shown in a results table with:

file name

resize dimensions when applicable

size before

size after

percentage saved

Exit Codes

Successful runs exit with code 0.

Invalid configuration, unavailable required dependencies and other fatal errors return a non-zero exit code.

If a batch completes with one or more failed files, the summary is still shown and the command exits non-zero so it can be used reliably in scripts and CI workflows.

Updating

wp package update mgenchev/wp-cli-optimize-images

Uninstalling

wp package uninstall mgenchev/wp-cli-optimize-images

Optional global files can be removed separately:

~/.wp-cli/optimize-images.json
~/.wp-cli/optimize-images-local

License

MIT