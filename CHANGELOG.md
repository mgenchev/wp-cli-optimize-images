Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project follows Semantic Versioning.

[1.1.0]

Added

Project-level .optimize-images.json configuration.

Image presets:

web — max 1920 × 1920 px, quality 75.

default — max 2880 × 2880 px, quality 80.

retina — max 3840 × 3840 px, quality 85.

--preset=web|default|retina.

--format=webp raster image conversion.

--format=original to override project-level WebP conversion.

WebP conversion with transparency preservation for supported source formats.

Collision protection when multiple source files would produce the same WebP filename.

SVG optimization through SVGO.

audit command for image directory analysis.

Audit reporting for:

total files and total size;

format breakdown;

files larger than 1 MB;

images exceeding the active resize threshold;

files that would be converted;

largest files;

estimated processing time.

Configurable local image quality with --quality.

TinyPNG monthly usage and remaining free compression reporting.

version command.

Three-line live progress display with percentage, processed file count, elapsed time and ETA.

Changed

Improved local processing performance by batching Sharp/SVGO jobs.

Added conservative concurrent TinyPNG processing.

Improved repeat-run performance by using file size and modification time before falling back to SHA-256 validation.

Reduced unnecessary filesystem and image metadata reads.

Improved cache signatures so changes to processing settings automatically trigger reprocessing where required.

Improved ETA calculation to account for concurrent processing and in-flight jobs.

Improved CLI output with a tabular per-file results view and more readable summary.

Improved status output and terminology across commands.

Improved zero-work output when all files are already up to date.

Local optimizer installation is automatic when first required.

Fixed

Fixed raster images being repeatedly optimized because the wrong processing signature could be stored in cache.

Fixed progress output producing repeated literal \\r sequences in some terminals.

Fixed multi-line progress redraw and cleanup behavior.

Fixed output handling so failed processing does not leave partially written optimized files.

Reliability

Added atomic cache writes.

Added temporary output files followed by atomic replacement on successful processing.

Added cleanup of known temporary artifacts during shutdown/interruption.

Added non-zero exit status when image processing completes with failures.

Added stricter validation for quality and resize options.

[1.0.0]

Added

Initial stable release.

Recursive JPEG, PNG, WebP and AVIF optimization.

TinyPNG as the preferred optimization service.

Automatic Sharp/libvips local fallback.

Proportional image resizing with no upscaling.

Default 2880 × 2880 px web-oriented resize limit.

--max-width and --max-height.

--no-resize.

--output.

--extensions.

--dry-run.

--force.

sync command.

SHA-256 optimization cache.

Source/output directory structure preservation.

Global TinyPNG API configuration.

Original source files are never modified.