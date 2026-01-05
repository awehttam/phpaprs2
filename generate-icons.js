#!/usr/bin/env node

/**
 * Icon Generator for PWA
 *
 * This script generates PNG icons from the SVG source.
 * Requires: npm install sharp
 *
 * Usage: node generate-icons.js
 */

const fs = require('fs');
const path = require('path');

const sizes = [72, 96, 128, 144, 152, 192, 384, 512];
const svgPath = path.join(__dirname, 'public', 'icons', 'icon.svg');
const outputDir = path.join(__dirname, 'public', 'icons');

async function generateIcons() {
    try {
        // Try to load sharp
        const sharp = require('sharp');

        console.log('Generating PWA icons...');

        const svgBuffer = fs.readFileSync(svgPath);

        for (const size of sizes) {
            const outputPath = path.join(outputDir, `icon-${size}x${size}.png`);

            await sharp(svgBuffer)
                .resize(size, size)
                .png()
                .toFile(outputPath);

            console.log(`✓ Generated ${size}x${size} icon`);
        }

        console.log('\nAll icons generated successfully!');
        console.log('Icons location: public/icons/');

    } catch (error) {
        if (error.code === 'MODULE_NOT_FOUND') {
            console.error('\n❌ Error: sharp module not found');
            console.log('\nPlease install sharp first:');
            console.log('  npm install sharp');
            console.log('\nOr use an online tool to convert the SVG to PNG icons:');
            console.log('  - https://realfavicongenerator.net/');
            console.log('  - https://www.pwabuilder.com/');
            console.log(`\nSource SVG: ${svgPath}`);
            process.exit(1);
        } else {
            console.error('Error generating icons:', error);
            process.exit(1);
        }
    }
}

generateIcons();
