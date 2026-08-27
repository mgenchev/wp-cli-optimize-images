const fs = require('fs/promises');
const path = require('path');
const sharp = require('sharp');
const { optimize: optimizeSvg } = require('svgo');

const [, , mode, manifestPath, resultPath] = process.argv;

if (mode !== 'batch' || !manifestPath || !resultPath) {
	console.error('Expected: node local-optimizer.cjs batch <manifest> <result>');
	process.exit(1);
}

function resizeImage(image, maxWidth, maxHeight) {
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
			throw new Error(`Unsupported extension: ${ext}`);
	}
}

function encodeOptimizedOutput(image, ext, quality) {
	switch (ext) {
		case 'jpg':
		case 'jpeg':
			return image.jpeg({
				quality,
				mozjpeg: true,
				chromaSubsampling: '4:2:0',
			});

		case 'png':
			return image.png({
				palette: true,
				quality,
				compressionLevel: 9,
				effort: 10,
				colours: 256,
				dither: 1,
			});

		case 'webp':
			return image.webp({
				quality,
				effort: 6,
				smartSubsample: true,
			});

		case 'avif':
			return image.avif({
				quality,
				effort: 9,
			});

		default:
			throw new Error(`Unsupported extension: ${ext}`);
	}
}

async function processSvg(job) {
	const source = await fs.readFile(job.input, 'utf8');

	const result = optimizeSvg(source, {
		path: job.input,
		plugins: [
			{
				name: 'preset-default',
				params: {
					overrides: {
						cleanupIds: false,
						removeDesc: false,
					},
				},
			},
		],
	});

	await fs.writeFile(job.output, result.data, 'utf8');
}

async function processRaster(job) {
	const ext = String(job.extension || '').toLowerCase();
	const maxWidth = Number(job.max_width || 0);
	const maxHeight = Number(job.max_height || 0);
	const quality = Math.min(
		100,
		Math.max(1, Number(job.quality || 80))
	);

	let image = sharp(job.input).autoOrient();
	image = resizeImage(image, maxWidth, maxHeight);

	if (job.mode === 'resize') {
		image = encodeResizeOutput(image, ext);
	} else if (job.mode === 'optimize') {
		image = encodeOptimizedOutput(image, ext, quality);
	} else {
		throw new Error(`Unsupported processing mode: ${job.mode}`);
	}

	await image.toFile(job.output);
}

async function processJob(job) {
	try {
		await fs.mkdir(path.dirname(job.output), {
			recursive: true,
		});

		if (job.mode === 'svg-optimize') {
			await processSvg(job);
		} else {
			await processRaster(job);
		}

		return {
			id: String(job.id),
			success: true,
		};
	} catch (error) {
		return {
			id: String(job.id),
			success: false,
			error: error && error.message ? error.message : String(error),
		};
	}
}

async function processWithConcurrency(jobs, concurrency) {
	const results = new Array(jobs.length);
	let nextIndex = 0;

	async function worker() {
		while (true) {
			const index = nextIndex++;

			if (index >= jobs.length) {
				return;
			}

			process.stdout.write(
				`WP_OPTIMIZE_START:${String(jobs[index].id)}\n`
			);

			results[index] = await processJob(jobs[index]);

			process.stdout.write(
				`WP_OPTIMIZE_PROGRESS:${String(jobs[index].id)}\n`
			);
		}
	}

	const workerCount = Math.min(
		Math.max(1, concurrency),
		jobs.length || 1
	);

	await Promise.all(
		Array.from({ length: workerCount }, () => worker())
	);

	return results;
}

async function main() {
	const rawManifest = await fs.readFile(manifestPath, 'utf8');
	const manifest = JSON.parse(rawManifest);
	const jobs = Array.isArray(manifest.jobs) ? manifest.jobs : [];
	const concurrency = Number(manifest.concurrency || 4);
	const results = await processWithConcurrency(jobs, concurrency);

	await fs.writeFile(
		resultPath,
		JSON.stringify(results),
		'utf8'
	);
}

main().catch((error) => {
	console.error(error && error.message ? error.message : String(error));
	process.exit(1);
});
