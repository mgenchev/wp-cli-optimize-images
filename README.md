# WP-CLI Optimize Images

A global WP-CLI command for optimizing images using the TinyPNG API.

Original images are left untouched. Optimized versions are written to a separate `optimized-images` directory.

## Features

* Optimize JPEG, PNG, WebP and AVIF images
* Process directories recursively
* Preserve the original directory structure
* Keep original images untouched
* Automatically skip unchanged images
* SHA-256 based optimization cache
* Force re-optimization when required
* Global TinyPNG API configuration
* Works independently of a specific WordPress installation

## Requirements

* PHP 8.1+
* PHP cURL extension
* WP-CLI
* TinyPNG API key

Get a TinyPNG API key from the TinyPNG Developer API.

## Installation

Install globally through WP-CLI:

```bash
wp package install https://github.com/mgenchev/wp-cli-optimize-images.git
```

Verify that the command is available:

```bash
wp help optimize-images
```

## Configuration

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

## Usage

Optimize a directory:

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

If an original image changes, it is automatically optimized again.

## Force Optimization

To ignore the cache and optimize every image again:

```bash
wp optimize-images /path/to/images --force
```

## Supported Formats

* JPEG / JPG
* PNG
* WebP
* AVIF

## Example

```bash
wp optimize-images "D:\Projects\website\images"
```

Example output:

```text
Source: D:\Projects\website\images
Output: D:\Projects\website\optimized-images

✓ hero.jpg  1.23 MB → 122.4 KB (-90%)
✓ logo.png  245.2 KB → 71.8 KB (-71%)

Success: Optimized: 2 | Skipped: 0 | Failed: 0 | Saved: 1.28 MB
```

## Updating

Update the installed package with WP-CLI:

```bash
wp package update mgenchev/wp-cli-optimize-images
```

## Uninstalling

```bash
wp package uninstall mgenchev/wp-cli-optimize-images
```

The global TinyPNG configuration can be removed separately by deleting:

```text
~/.wp-cli/optimize-images.json
```

## License

MIT
