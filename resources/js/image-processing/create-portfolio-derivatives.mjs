import sharp from 'sharp';
import fs from 'node:fs/promises';
import path from 'node:path';

/**
 * Renders web derivatives for a photo.
 *
 * Usage:
 *   node create-portfolio-derivatives.mjs <inputPath> <outputDirectory> <configJson>
 *
 * configJson shape:
 *   {
 *     "max_source_dimension": 8000,
 *     "variants": {
 *       "llm-input": { "format": "jpeg", "width": 2048, "height": 2048, "fit": "inside", "quality": 92 },
 *       "thumb":     { "format": "webp", "width": 500,  "height": 500,  "fit": "cover",  "quality": 78 }
 *     }
 *   }
 *
 * Every variant is EXIF auto-rotated, converted to sRGB, and stripped of
 * metadata. Prints a JSON payload describing the source and each rendered
 * file on stdout.
 */

const [inputPath, outputDirectory, configJson] = process.argv.slice(2);

if (!inputPath || !outputDirectory || !configJson) {
    console.error('Usage: node create-portfolio-derivatives.mjs <inputPath> <outputDirectory> <configJson>');
    process.exit(1);
}

const config = JSON.parse(configJson);
const variants = config.variants ?? {};
const maxSourceDimension = config.max_source_dimension ?? 8000;

await fs.mkdir(outputDirectory, { recursive: true });

const base = sharp(inputPath, {
    failOn: 'error',
    limitInputPixels: maxSourceDimension * maxSourceDimension,
})
    .rotate()
    .toColorspace('srgb');

const metadata = await base.metadata();

if (Math.max(metadata.width ?? 0, metadata.height ?? 0) > maxSourceDimension) {
    console.error(`Source image exceeds the maximum allowed dimension of ${maxSourceDimension}px.`);
    process.exit(2);
}

const extensionFor = (format) => (format === 'jpeg' ? 'jpg' : format);

const results = {};

for (const [name, variant] of Object.entries(variants)) {
    const pipeline = base.clone().resize({
        width: variant.width,
        height: variant.height,
        fit: variant.fit ?? 'inside',
        position: variant.fit === 'cover' ? 'attention' : undefined,
        withoutEnlargement: true,
    });

    const encoded = variant.format === 'jpeg'
        ? pipeline.jpeg({ quality: variant.quality, mozjpeg: true, progressive: true })
        : pipeline.webp({ quality: variant.quality, effort: 5 });

    const file = `${name}.${extensionFor(variant.format)}`;
    const info = await encoded.toFile(path.join(outputDirectory, file));

    results[name] = {
        file,
        width: info.width,
        height: info.height,
        size_bytes: info.size,
    };
}

const sourceStat = await fs.stat(inputPath);

console.log(JSON.stringify({
    source: {
        width: metadata.width ?? null,
        height: metadata.height ?? null,
        format: metadata.format ?? null,
        size_bytes: sourceStat.size,
    },
    variants: results,
}));
