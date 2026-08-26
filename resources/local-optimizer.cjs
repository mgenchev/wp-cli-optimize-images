const sharp = require('sharp');

const [
	,
	,
	mode,
	input,
	output,
	extension,
	maxWidthValue,
	maxHeightValue,
] = process.argv;

if (!mode || !input || !output || !extension) {
	console.error(
		'Missing mode, input, output or extension.'
	);

	process.exit(1);
}

const maxWidth = parseInt(maxWidthValue || '0', 10);
const maxHeight = parseInt(maxHeightValue || '0', 10);

/**
 * Apply proportional web-safe resizing.
 */
function resizeImage(image) {
	if (!maxWidth && !maxHeight) {
		return image;
	}

	return image.resize({
		width: maxWidth || null,
		height: maxHeight || null,
		fit: 'inside',
		withoutEnlargement: true,
	});
}

/**
 * Encode a high-quality intermediate image
 * before sending it to TinyPNG.
 */
function encodeResizeOutput(image, ext) {
	switch (ext) {
		case 'jpg':
		case 'jpeg':
			return image.jpeg({
				quality: 95,
				chromaSubsampling: '4:4:4',
			});

		case 'png':
			return image.png({
				compressionLevel: 9,
			});

		case 'webp':
			return image.webp({
				quality: 95,
				effort: 5,
				smartSubsample: true,
			});

		case 'avif':
			return image.avif({
				quality: 90,
				effort: 6,
			});

		default:
			throw new Error(
				`Unsupported extension: ${ext}`
			);
	}
}

/**
 * Apply final local optimization.
 */
function encodeOptimizedOutput(image, ext) {
	switch (ext) {
		case 'jpg':
		case 'jpeg':
			return image.jpeg({
				quality: 82,
				mozjpeg: true,
				chromaSubsampling: '4:2:0',
			});

		case 'png':
			return image.png({
				palette: true,
				quality: 90,
				compressionLevel: 9,
				effort: 10,
				colours: 256,
				dither: 1,
			});

		case 'webp':
			return image.webp({
				quality: 82,
				effort: 6,
				smartSubsample: true,
			});

		case 'avif':
			return image.avif({
				quality: 60,
				effort: 9,
			});

		default:
			throw new Error(
				`Unsupported extension: ${ext}`
			);
	}
}

async function processImage() {
	const ext = extension.toLowerCase();

	let image = sharp(input)
		.autoOrient();

	image = resizeImage(image);

	if (mode === 'resize') {
		image = encodeResizeOutput(
			image,
			ext
		);
	} else if (mode === 'optimize') {
		image = encodeOptimizedOutput(
			image,
			ext
		);
	} else {
		throw new Error(
			`Unsupported processing mode: ${mode}`
		);
	}

	await image.toFile(output);
}

processImage().catch((error) => {
	console.error(
		error && error.message
			? error.message
			: String(error)
	);

	process.exit(1);
});