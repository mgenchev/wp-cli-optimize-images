# WP-CLI Optimize Images

A global WP-CLI command for optimizing images with **TinyPNG** and an automatic free local fallback powered by **Sharp/libvips**.

TinyPNG is used as the preferred optimization engine when an API key is configured. If no API key is available, the TinyPNG limit is reached, or the API is unavailable, the package automatically falls back to local image optimization.

Original images are never modified. Optimized versions are written to a separate output directory.

## Features

* Optimize JPEG, PNG, WebP and AVIF images
* TinyPNG optimization by default
* Automatic free local fallback with Sharp/libvips
* Automatic local optimizer setup when first required
* Process directories recursively
* Preserve the original directory structure
* Keep original images untouched
* Custom output directory support
* Filter images by file extension
* Dry-run mode with no API requests or file changes
* Automatically skip unchanged images
* SHA-256 based optimization cache
* Force re-optimization when required
* Sync source and optimized directories
* Remove optimized images whose source files no longer exist
* Global TinyPNG API configuration
* Status command for checking the current setup
* Works independently of a specific WordPress installation

The optimization settings are designed for web images with the goal of significantly reducing file size while keeping image quality visually indistinguishable for normal use.

Actual compression depends on the source images. Already optimized images may see smaller reductions.

## Requirements

* PHP 8.1+
* WP-CLI
* Node.js 20.9+ and npm for the free local optimizer
* PHP cURL extension when using TinyPNG
* TinyPNG API key is optional

If TinyPNG is not configured, the package automatically uses the free local optimizer.

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

## Optimization Engines

The package automatically chooses the appropriate optimization engine.

When TinyPNG is configured:

```text
TinyPNG
    ↓
Success → use TinyPNG output

API unavailable
Invalid or unavailable account
Compression limit reached
    ↓
Local Sharp/libvips fallback
```

When TinyPNG is not configured:

```text
Local Sharp/libvips optimizer
```

The local optimizer is installed automatically when it is first required.

Supported local optimization includes:

* JPEG optimization
* PNG palette and compression optimization
* WebP optimization
* AVIF optimization

If local optimization would result in a file larger than the original, the original file is kept instead.

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
Local fallback: ready (Sharp 0.35.x)
Supported extensions: jpg, jpeg, png, webp, avif

Strategy: TinyPNG -> local fallback

Success: Ready.
```

If TinyPNG is not configured but the local optimizer is available:

```text
Strategy: local optimizer

Success: Ready with local optimizer.
```

## Usage

### Optimize a directory

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

For example:

```text
project/
├── images/
└── dist/
    └── images/
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

You can combine this with other options:

```bash
wp optimize-images ./images --extensions=jpg,png --output=./dist/images
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
Engine: TinyPNG -> Local fallback
Mode: dry-run

+ hero.jpg (would optimize)
↷ logo.png (unchanged)
+ team/member.jpg (would optimize)

Found: 3
Would optimize: 2
Unchanged: 1
Source size: 4.21 MB

Success: Dry run complete. No files were changed.
```

Dry-run mode:

* does not modify files
* does not remove files
* does not call the TinyPNG API
* does not consume TinyPNG compression credits

## Optimization Cache

The command stores SHA-256 hashes of successfully optimized source images in:

```text
optimized-images/.optimize-images-cache.json
```

When the command is run again, unchanged images are skipped:

```text
↷ hero.jpg (unchanged)
↷ logo.png (unchanged)

Success: Optimized: 0 | Skipped: 2 | Failed: 0 | Saved: 0 B
```

If an original image changes, its hash changes and the file is automatically optimized again.

This prevents unnecessary TinyPNG API calls and repeated local optimization.

## Force Optimization

To ignore the cache and optimize every selected image again:

```bash
wp optimize-images /path/to/images --force
```

You can combine `--force` with other options:

```bash
wp optimize-images ./images --force --extensions=jpg,png
```

## Sync

The `sync` command keeps the output directory synchronized with the source directory:

```bash
wp optimize-images sync ./images
```

Sync performs the following actions:

* new source image → optimize
* changed source image → re-optimize
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
✓ new-banner.jpg [TinyPNG]  1.40 MB → 184 KB (-87%)

Success: Optimized: 1 | Skipped: 2 | Failed: 0 | Removed: 1 | Saved: 1.22 MB (87%)
```

## Sync Dry Run

You can preview a sync operation before changing anything:

```bash
wp optimize-images sync ./images --dry-run
```

Example:

```text
↷ hero.jpg (unchanged)
+ new-banner.jpg (would optimize)
- old-banner.jpg (would remove)

Found: 2
Would optimize: 1
Unchanged: 1
Would remove: 1

Success: Dry run complete. No files were changed.
```

## Sync with Custom Output

```bash
wp optimize-images sync ./images --output=./dist/images
```

You can also limit the sync to specific extensions:

```bash
wp optimize-images sync ./images --extensions=jpg,png
```

Or combine everything:

```bash
wp optimize-images sync ./images --output=./dist/images --extensions=jpg,png --dry-run
```

## Supported Formats

* JPEG / JPG
* PNG
* WebP
* AVIF

SVG optimization is currently not included.

## Example

```bash
wp optimize-images "D:\Projects\website\images"
```

Example output when TinyPNG is available:

```text
Source: D:/Projects/website/images
Output: D:/Projects/website/optimized-images
Extensions: jpg,jpeg,png,webp,avif
Engine: TinyPNG -> Local fallback

✓ hero.jpg [TinyPNG]  1.23 MB → 122.4 KB (-90%)
✓ logo.png [TinyPNG]  245.2 KB → 71.8 KB (-71%)

Success: Optimized: 2 | Skipped: 0 | Failed: 0 | Saved: 1.28 MB (87%)
Engines: TinyPNG 2 | Local 0
```

If TinyPNG becomes unavailable during the operation:

```text
✓ hero.jpg [TinyPNG]  1.23 MB → 122.4 KB (-90%)

Warning: TinyPNG is unavailable. Switching to the free local optimizer for the remaining images.

✓ banner.jpg [Local]  1.45 MB → 312 KB (-78%)
✓ photo.webp [Local]  820 KB → 246 KB (-70%)

Success: Optimized: 3 | Skipped: 0 | Failed: 0 | Saved: 2.82 MB (81%)
Engines: TinyPNG 1 | Local 2
```

## Command Overview

```bash
# Configure TinyPNG
wp optimize-images configure

# Check configuration
wp optimize-images status

# Optimize a directory
wp optimize-images ./images

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
