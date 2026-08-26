# WP-CLI Optimize Images

A global WP-CLI command for optimizing and resizing images for the web.

The package uses **TinyPNG** as the preferred optimization engine and automatically falls back to a free local optimizer powered by **Sharp/libvips** when TinyPNG is unavailable.

Original images are never modified. Optimized versions are written to a separate output directory.

## Features

* Optimize JPEG, PNG, WebP and AVIF images
* TinyPNG optimization by default
* Automatic free local fallback with Sharp/libvips
* Automatic local optimizer setup when first required
* Automatic proportional image resizing for web use
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
* Cache invalidation when resize settings change
* Force re-optimization when required
* Sync source and optimized directories
* Remove optimized images whose source files no longer exist
* Global TinyPNG API configuration
* Status command for checking the current setup
* Works independently of a specific WordPress installation

The optimization settings are designed for web images with the goal of significantly reducing file size while keeping image quality visually indistinguishable for normal use.

Actual compression depends on the source images. Images that are already optimized may see smaller reductions.

## Requirements

* PHP 8.1+
* WP-CLI
* Node.js 20.9+ and npm for resizing and the free local optimizer
* PHP cURL extension when using TinyPNG
* TinyPNG API key is optional

If TinyPNG is not configured or becomes unavailable, the package automatically uses the free local optimizer.

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

TinyPNG is optional, but it is used as the preferred optimization engine when configured.

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

If no TinyPNG API key is configured, the package automatically uses the free local optimizer.

## Image Resizing

Images are resized proportionally by default before final optimization when they exceed:

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

This default is intended to provide a practical balance for modern web layouts and high-density displays.

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

For example:

```text
4000 × 3000 → 1920 × 1440
3000 × 4000 → 1440 × 1920
```

### Disable Resizing

To optimize images without changing their pixel dimensions:

```bash
wp optimize-images ./images --no-resize
```

`--no-resize` cannot be combined with `--max-width` or `--max-height`.

## Optimization Engines

The package automatically chooses the appropriate optimization engine.

When TinyPNG is configured:

```text
Resize locally if required
    ↓
TinyPNG
    ↓
Success → use TinyPNG output

API unavailable
Compression limit reached
Authentication/account issue
    ↓
Local Sharp/libvips fallback
```

When TinyPNG is not configured:

```text
Sharp/libvips
```

The local optimizer is installed automatically when it is first required.

Supported local optimization includes:

* JPEG optimization
* PNG palette and compression optimization
* WebP optimization
* AVIF optimization

If local optimization produces a larger file and the image was not resized, the original file is kept instead.

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
Supported extensions: jpg, jpeg, png, webp, avif
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
    ├── logo.png
    └── team/
        └── member.jpg
```

the command creates:

```text
project/
├── images/
│   ├── hero.jpg
│   ├── logo.png
│   └── team/
│       └── member.jpg
│
└── optimized-images/
    ├── hero.jpg
    ├── logo.png
    └── team/
        └── member.jpg
```

The original images are never modified.

## Custom Output Directory

By default, optimized images are written to a sibling directory named:

```text
optimized-images
```

You can specify a different output directory:

```bash
wp optimize-images ./images --output=./dist/images
```

Absolute paths are also supported:

```bash
wp optimize-images ./images --output="D:\Output\optimized-images"
```

## Filter by File Extension

By default, all supported image formats are processed.

To process only specific extensions, provide a comma-separated list:

```bash
wp optimize-images ./images --extensions=jpg,png
```

Only WebP:

```bash
wp optimize-images ./images --extensions=webp
```

Options can be combined:

```bash
wp optimize-images ./images --extensions=jpg,png --max-width=1920 --output=./dist/images
```

## Dry Run

Preview what the command would do without changing files or sending images to TinyPNG:

```bash
wp optimize-images ./images --dry-run
```

Example:

```text
Source: D:/Projects/website/images
Output: D:/Projects/website/optimized-images
Extensions: jpg,jpeg,png,webp,avif
Resize: max 2880 × 2880 px
Mode: dry-run

+ hero.jpg [6000×4000 → 2880×1920] (would optimize)
↷ logo.png (unchanged)
+ team/member.jpg (would optimize)

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

## Optimization Cache

The command stores SHA-256 hashes and processing settings of successfully optimized source images in:

```text
optimized-images/.optimize-images-cache.json
```

When the command is run again, unchanged images are skipped:

```text
↷ hero.jpg (unchanged)
↷ logo.png (unchanged)
```

If an original image changes, it is automatically processed again.

The cache also includes the current processing settings. For example, changing:

```bash
--max-width=2880
```

to:

```bash
--max-width=1920
```

causes the affected images to be processed again automatically.

## Force Optimization

To ignore the cache and process every selected image again:

```bash
wp optimize-images /path/to/images --force
```

You can combine `--force` with other options:

```bash
wp optimize-images ./images --force --max-width=1920 --extensions=jpg,png
```

## Sync

The `sync` command keeps the output directory synchronized with the source directory:

```bash
wp optimize-images sync ./images
```

Sync performs the following actions:

* new source image → optimize
* changed source image → re-optimize
* changed resize settings → re-process
* unchanged source image → skip
* deleted source image → remove corresponding optimized image

Example:

Before:

```text
images/
├── hero.jpg
├── logo.png
└── old-banner.jpg

optimized-images/
├── hero.jpg
├── logo.png
└── old-banner.jpg
```

After changing the source directory:

```text
images/
├── hero.jpg
├── logo.png
└── new-banner.jpg
```

Run:

```bash
wp optimize-images sync ./images
```

Example output:

```text
− old-banner.jpg (removed)
↷ hero.jpg (unchanged)
↷ logo.png (unchanged)
✓ new-banner.jpg [5000×3200 → 2880×1843]  4.20 MB → 512 KB (-88%)
```

## Sync Dry Run

Preview a sync operation without changing anything:

```bash
wp optimize-images sync ./images --dry-run
```

Example:

```text
↷ hero.jpg (unchanged)
+ new-banner.jpg [5000×3200 → 2880×1843] (would optimize)
- old-banner.jpg (would remove)

Dry run

  Found:             2
  Would optimize:    1
  Would resize:      1
  Unchanged:         1
  Would remove:      1
  Source size:       6.82 MB

Success: Dry run complete. No files were changed.
```

## Final Summary

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

When `sync` removes files, the summary also includes:

```text
Removed:     3
```

TinyPNG usage is displayed when the API provides the current monthly compression count.

## Supported Formats

* JPEG / JPG
* PNG
* WebP
* AVIF

SVG optimization is currently not included.

## Command Overview

```bash
# Configure TinyPNG
wp optimize-images configure

# Check configuration
wp optimize-images status

# Optimize with default 2880 × 2880 max dimensions
wp optimize-images ./images

# Custom maximum width
wp optimize-images ./images --max-width=1920

# Custom maximum dimensions
wp optimize-images ./images --max-width=1920 --max-height=1920

# Disable resizing
wp optimize-images ./images --no-resize

# Custom output
wp optimize-images ./images --output=./dist/images

# Specific extensions
wp optimize-images ./images --extensions=jpg,png

# Preview without making changes
wp optimize-images ./images --dry-run

# Ignore cache
wp optimize-images ./images --force

# Synchronize output with source
wp optimize-images sync ./images

# Preview synchronization
wp optimize-images sync ./images --dry-run
```

## Performance

Image processing currently happens sequentially.

Large batches can take some time because resizing and local optimization use Sharp through Node.js, while TinyPNG optimization also involves uploading and downloading each image.

Future performance improvements may include:

* batch Sharp processing
* concurrent TinyPNG requests

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

The automatically installed local optimizer runtime can also be removed by deleting:

```text
~/.wp-cli/optimize-images-local
```

## License

MIT
