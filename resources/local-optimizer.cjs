const sharp = require('sharp');

const [, , input, output, extension] = process.argv;

if (!input || !output || !extension) {
	console.error('Missing input, output or extension.');
	process.exit(1);
}

async function optimize() {
	let image = sharp(input).autoOrient();
	const ext = extension.toLowerCase();

	switch (ext) {
		case 'jpg':
		case 'jpeg':
			image = image.jpeg({
				quality: 82,
				mozjpeg: true,
				chromaSubsampling: '4:2:0',
			});
			break;

		case 'png':
			image = image.png({
				palette: true,
				quality: 90,
				compressionLevel: 9,
				effort: 10,
				colours: 256,
				dither: 1,
			});
			break;

		case 'webp':
			image = image.webp({
				quality: 82,
				effort: 6,
				smartSubsample: true,
			});
			break;

		case 'avif':
			image = image.avif({
				quality: 60,
				effort: 9,
			});
			break;

		default:
			throw new Error(`Unsupported extension: ${ext}`);
	}

	await image.toFile(output);
}

optimize().catch((error) => {
	console.error(error && error.message ? error.message : String(error));
	process.exit(1);
});