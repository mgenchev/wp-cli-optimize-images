# WP-CLI Optimize Images

A global WP-CLI command for optimizing and resizing images for the web.

The package uses **TinyPNG** as the preferred optimization engine for raster images and automatically falls back to a free local optimizer powered by **Sharp/libvips** when TinyPNG is unavailable.

SVG files are optimized locally with **SVGO**.

Original images are never modified. Optimized versions are written to a separate output directory.

## Features

* Optimize JPEG, PNG, WebP, AVIF and SVG images
* TinyPNG optimization for raster images
* Automatic free local fallback with Sharp/libvips
* SVG optimization with SVGO
* Automatic local optimizer setup when first required
* Automatic proportional image resizing for raster images
* Default maximum dimensions of 2880 × 2880 px
* Never upscale smaller images
* Custom maximum width and height
* Disable resizing when required
* Process directories recursively
* Preserve the original directory structure
* Keep original images untouched
* Custom output directory support
* Filter images by file extension
* Dry-run mode with no API requests or file changes
* Automatically skip unchanged images
* SHA-256 based optimization cache
* Cache invalidation when processing settings change
* Force re-optimization when required
* Sync source and optimized directories
* Remove optimized images whose source files no longer exist
* Global TinyPNG API configuration
* TinyPNG monthly usage reporting
* Batch local image processing for improved performance
* Concurrent TinyPNG requests for faster large batches
* Status command for checking the current setup
* Works independently of a specific WordPress installation

The optimization settings are designed for web assets with the goal of significantly reducing file size while keeping image quality visually indistinguishable for normal use.

Actual compression depends on the source files. Images that are already optimized may see smaller reductions.

## Requirements

* PHP 8.1+
* WP-CLI
* Node.js 20.9+
* npm
* PHP cURL extension when using TinyPNG
* TinyPNG API key is optional

Node.js is used for:

* image resizing
* Sharp/libvips local raster optimization
* SVGO SVG optimization

If TinyPNG is not configured or becomes unavailable, raster images automatically use the free local optimizer.

SVG files are always optimized locally.

## Installation

Install globally through WP-CLI:

```bash
wp package install https://github.com/mgenchev/wp-cli-optimize-images.git
```

Verify that the command is available:

```bash
wp help optimize-images
```

No WordPress installation is required to use the command.

## TinyPNG Configuration

TinyPNG is optional but is used as the preferred optimization service for raster images when configured.

Configure your TinyPNG API key:

```bash
wp optimize-images configure
```

You will be prompted to enter your API key:

```text
TinyPNG API configuration

Enter TinyPNG API key:
```

The key is stored globally in:

```text
~/.wp-cli/optimize-images.json
```

You only need to configure it once.

If the `TINIFY_API_KEY` environment variable exists, it takes precedence over the stored configuration.

If no TinyPNG API key is configured, raster images automatically use the free local optimizer.

SVG files never use TinyPNG.

## Image Resizing

Raster images are resized proportionally by default before final optimization when they exceed:

```text
2880 × 2880 px
```

Aspect ratio is always preserved and smaller images are never enlarged.

Examples:

```text
6000 × 4000 → 2880 × 1920
4000 × 6000 → 1920 × 2880
2500 × 1600 → unchanged
800 × 600   → unchanged
```

SVG files are vector-based and are therefore not resized.

### Custom Maximum Width

```bash
wp optimize-images ./images --max-width=1920
```

The default maximum height remains 2880 px.

### Custom Maximum Height

```bash
wp optimize-images ./images --max-height=1600
```

The default maximum width remains 2880 px.

### Custom Bounding Box

```bash
wp optimize-images ./images --max-width=1920 --max-height=1920
```

The image is resized proportionally so that it fits inside the specified bounds.

Examples:

```text
4000 × 3000 → 1920 × 1440
3000 × 4000 → 1440 × 1920
```

### Disable Resizing

To optimize raster images without changing their pixel dimensions:

```bash
wp optimize-images ./images --no-resize
```

`--no-resize` cannot be combined with `--max-width` or `--max-height`.

SVG optimization is unaffected by resize options.

## Raster Image Processing

When TinyPNG is configured, raster images are processed as follows:

```text
Source image
    ↓
Resize locally if required
    ↓
TinyPNG compression
    ↓
Optimized output
```

If TinyPNG becomes unavailable, reaches the account limit, or encounters an account/API error, processing automatically continues using Sharp/libvips locally.

If TinyPNG is not configured, raster images are processed entirely locally.

Supported raster formats:

* JPEG / JPG
* PNG
* WebP
* AVIF

## SVG Optimization

SVG files are optimized locally using **SVGO**.

They are never uploaded to TinyPNG and do not consume TinyPNG compression credits.

The SVG optimizer uses conservative settings intended for production web assets.

It:

* removes unnecessary metadata
* removes comments
* simplifies redundant markup
* optimizes path data
* optimizes colors and transforms
* preserves `viewBox`
* preserves IDs to avoid breaking CSS, JavaScript or fragment references
* preserves `<desc>` content for accessibility
* never resizes SVG files

If the optimized SVG would be larger than the original file, the original file is kept instead.

Example:

```text
logo.svg  24.18 KB → 11.72 KB (-52%)
```

SVG files participate normally in:

* caching
* `--force`
* `--dry-run`
* `sync`
* extension filtering
* final size statistics

## Local Runtime

The local processing runtime is installed automatically when it is first required.

It contains:

```text
Sharp / libvips
SVGO
```

The runtime is stored globally under:

```text
~/.wp-cli/optimize-images-local
```

No manual `npm install` is required.

## Performance

The command is optimized for efficiently processing image directories.

### Batch Local Processing

Local image processing runs in batches rather than starting a separate Node.js process for every file.

Instead of:

```text
PHP
 ↓
Node → image 1
Node → image 2
Node → image 3
Node → image 4
```

the package uses:

```text
PHP
 ↓
Single Node process
 ↓
image 1
image 2
image 3
image 4
```

Multiple local jobs can be processed concurrently inside the batch.

This applies to both Sharp raster processing and SVG optimization.

### Concurrent TinyPNG Requests

TinyPNG requests are processed concurrently in small groups instead of waiting for each image to finish before starting the next one.

The package currently processes up to:

```text
3 TinyPNG requests concurrently
```

The concurrency level is intentionally conservative to improve performance without aggressively hitting API rate limits.

## Status

Check the current package configuration:

```bash
wp optimize-images status
```

Example:

```text
WP-CLI Optimize Images

PHP: 8.3.x
cURL: enabled
TinyPNG API key: configured
Node.js: 22.x.x
Local optimizer: ready
Default resize: 2880 × 2880 px
Supported extensions: jpg, jpeg, png, webp, avif, svg
Config: C:/Users/User/.wp-cli/optimize-images.json

Success: Ready.
```

## Usage

### Optimize a Directory

```bash
wp optimize-images /path/to/images
```

On Windows:

```bash
wp optimize-images "D:\Projects\website\images"
```

Given:

```text
project/
└── images/
    ├── hero.jpg
    ├── logo.svg
    ├── icon.png
    └── team/
        └── member.webp
```

the command creates:

```text
project/
├── images/
│   ├── hero.jpg
│   ├── logo.svg
│   ├── icon.png
│   └── team/
│       └── member.webp
│
└── optimized-images/
    ├── hero.jpg
    ├── logo.svg
    ├── icon.png
    └── team/
        └── member.webp
```

The original files are never modified.

## Custom Output Directory

By default, optimized files are written to a sibling directory named:

```text
optimized-images
```

Specify a different output directory:

```bash
wp optimize-images ./images --output=./dist/images
```

Absolute paths are also supported:

```bash
wp optimize-images ./images --output="D:\Output\optimized-images"
```

The output directory cannot be located inside the source directory.

## Filter by File Extension

By default, all supported formats are processed.

Process only JPEG and PNG:

```bash
wp optimize-images ./images --extensions=jpg,png
```

Only SVG:

```bash
wp optimize-images ./images --extensions=svg
```

SVG and PNG:

```bash
wp optimize-images ./images --extensions=svg,png
```

Options can be combined:

```bash
wp optimize-images ./images \
    --extensions=jpg,png \
    --max-width=1920 \
    --output=./dist/images
```

## Dry Run

Preview what the command would do without changing files or sending raster images to TinyPNG:

```bash
wp optimize-images ./images --dry-run
```

Example:

```text
Source: D:/Projects/website/images
Output: D:/Projects/website/optimized-images
Extensions: jpg,jpeg,png,webp,avif,svg
Resize: max 2880 × 2880 px
Mode: dry-run

+ hero.jpg [6000×4000 → 2880×1920] (would optimize)
+ logo.svg (would optimize)
↷ icon.png (unchanged)

Dry run

  Found:             3
  Would optimize:    2
  Would resize:      1
  Unchanged:         1
  Source size:       8.42 MB

Success: Dry run complete. No files were changed.
```

Dry-run mode:

* does not modify files
* does not remove files
* does not call the TinyPNG API
* does not consume TinyPNG compression credits
* does not execute Sharp or SVGO optimization

## Optimization Cache

The command stores SHA-256 hashes and processing settings for successfully optimized files in:

```text
optimized-images/.optimize-images-cache.json
```

When the command runs again, unchanged files are skipped:

```text
↷ hero.jpg (unchanged)
↷ logo.svg (unchanged)
```

If a source file changes, it is automatically processed again.

The cache also tracks processing settings for raster images.

For example, changing:

```bash
--max-width=2880
```

to:

```bash
--max-width=1920
```

causes the affected raster images to be processed again automatically.

SVG files do not depend on resize settings.

Performance-only package updates do not invalidate previously processed files unless optimization behavior changes.

## Force Optimization

Ignore the cache and process every selected file again:

```bash
wp optimize-images /path/to/images --force
```

Only re-optimize SVG files:

```bash
wp optimize-images ./images --extensions=svg --force
```

Combine with other options:

```bash
wp optimize-images ./images \
    --force \
    --max-width=1920 \
    --extensions=jpg,png
```

## Sync

The `sync` command keeps the output directory synchronized with the source directory:

```bash
wp optimize-images sync ./images
```

Sync performs the following actions:

* new source file → optimize
* changed source file → re-optimize
* changed raster processing settings → re-process
* unchanged source file → skip
* deleted source file → remove corresponding optimized file
* empty output directories → clean up

This includes SVG files.

Example:

```text
images/
├── hero.jpg
├── logo.svg
└── new-icon.svg
```

Run:

```bash
wp optimize-images sync ./images
```

Example output:

```text
− old-icon.svg (removed)
↷ hero.jpg (unchanged)
↷ logo.svg (unchanged)
✓ new-icon.svg  8.72 KB → 4.11 KB (-53%)
```

## Sync Dry Run

Preview synchronization without changing anything:

```bash
wp optimize-images sync ./images --dry-run
```

Example:

```text
↷ hero.jpg (unchanged)
+ new-icon.svg (would optimize)
- old-icon.svg (would remove)

Dry run

  Found:             2
  Would optimize:    1
  Would resize:      0
  Unchanged:         1
  Would remove:      1
  Source size:       6.82 MB

Success: Dry run complete. No files were changed.
```

## Summary

After processing, the command displays a summary:

```text
Optimization complete

Files
  Found:       14
  Optimized:   11
  Resized:     4
  Skipped:     3
  Failed:      0

Size
  Before:      38.72 MB
  After:       8.41 MB
  Saved:       30.31 MB (78%)

TinyPNG
  Used:        148 / 500
  Remaining:   352 free compressions

Success: Images optimized successfully.
```

SVG files are included in:

```text
Found
Optimized
Skipped
Failed
Before
After
Saved
```

but never increase the TinyPNG compression count.

When `sync` removes files, the summary also includes:

```text
Removed:     3
```

TinyPNG usage is displayed when the API provides the current monthly compression count.

## Supported Formats

| Format     | Optimization | Resize | TinyPNG |
| ---------- | ------------ | ------ | ------- |
| JPEG / JPG | Yes          | Yes    | Yes     |
| PNG        | Yes          | Yes    | Yes     |
| WebP       | Yes          | Yes    | Yes     |
| AVIF       | Yes          | Yes    | Yes     |
| SVG        | Yes          | No     | No      |

SVG optimization is handled locally with SVGO.

## Command Overview

```bash
# Configure TinyPNG
wp optimize-images configure

# Check configuration
wp optimize-images status

# Optimize all supported files
wp optimize-images ./images

# Optimize only SVG files
wp optimize-images ./images --extensions=svg

# Custom maximum width
wp optimize-images ./images --max-width=1920

# Custom maximum height
wp optimize-images ./images --max-height=1600

# Custom maximum dimensions
wp optimize-images ./images --max-width=1920 --max-height=1920

# Disable raster resizing
wp optimize-images ./images --no-resize

# Custom output
wp optimize-images ./images --output=./dist/images

# Specific extensions
wp optimize-images ./images --extensions=jpg,png,svg

# Preview without making changes
wp optimize-images ./images --dry-run

# Ignore cache
wp optimize-images ./images --force

# Synchronize output with source
wp optimize-images sync ./images

# Preview synchronization
wp optimize-images sync ./images --dry-run
```

## Updating

Update the installed package with WP-CLI:

```bash
wp package update mgenchev/wp-cli-optimize-images
```

## Uninstalling

Uninstall the package:

```bash
wp package uninstall mgenchev/wp-cli-optimize-images
```

The global TinyPNG configuration can be removed separately by deleting:

```text
~/.wp-cli/optimize-images.json
```

The automatically installed local runtime can also be removed by deleting:

```text
~/.wp-cli/optimize-images-local
```

## License

MIT
