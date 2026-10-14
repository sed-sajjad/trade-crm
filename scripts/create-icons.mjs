import { mkdirSync, writeFileSync } from 'node:fs';
import { deflateSync } from 'node:zlib';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const output = fileURLToPath(new URL('../assets/icons/', import.meta.url));
mkdirSync(output, { recursive: true });
// One geometric mark for every platform: three separate contact silhouettes.
// The mark stays inside the maskable safe circle (radius 204.8 at 512px).
const background = [[21, 143, 129], [6, 78, 76]];
const shapes = [
  { type: 'circle', cx: 144, cy: 217, r: 28, color: [153, 230, 213] },
  { type: 'circle', cx: 368, cy: 217, r: 28, color: [153, 230, 213] },
  { type: 'body', x: 102, y: 271, width: 84, height: 77, top: 34, bottom: 20, color: [153, 230, 213] },
  { type: 'body', x: 326, y: 271, width: 84, height: 77, top: 34, bottom: 20, color: [153, 230, 213] },
  { type: 'circle', cx: 256, cy: 185, r: 40, color: [255, 255, 255] },
  { type: 'body', x: 196, y: 246, width: 120, height: 126, top: 54, bottom: 24, color: [255, 255, 255] },
];

function bodyPath({ x, y, width: w, height: h, top: t, bottom: b }) {
  return `M${x + t} ${y}H${x + w - t}Q${x + w} ${y} ${x + w} ${y + t}V${y + h - b}Q${x + w} ${y + h} ${x + w - b} ${y + h}H${x + b}Q${x} ${y + h} ${x} ${y + h - b}V${y + t}Q${x} ${y} ${x + t} ${y}Z`;
}
const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><defs><linearGradient id="background" x1="0" y1="0" x2="512" y2="512" gradientUnits="userSpaceOnUse"><stop stop-color="rgb(${background[0]})"/><stop offset="1" stop-color="rgb(${background[1]})"/></linearGradient></defs><rect width="512" height="512" fill="url(#background)"/>${shapes.map(shape => shape.type === 'circle'
  ? `<circle cx="${shape.cx}" cy="${shape.cy}" r="${shape.r}" fill="rgb(${shape.color})"/>`
  : `<path d="${bodyPath(shape)}" fill="rgb(${shape.color})"/>`).join('')}</svg>\n`;
writeFileSync(join(output, 'icon.svg'), svg);

const crcTable = new Uint32Array(256);
for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; crcTable[n] = c >>> 0; }
function crc32(buffer) { let crc = 0xffffffff; for (const byte of buffer) crc = crcTable[(crc ^ byte) & 0xff] ^ (crc >>> 8); return (crc ^ 0xffffffff) >>> 0; }
function chunk(name, data) { const type = Buffer.from(name); const length = Buffer.alloc(4); length.writeUInt32BE(data.length); const checksum = Buffer.alloc(4); checksum.writeUInt32BE(crc32(Buffer.concat([type, data]))); return Buffer.concat([length, type, data, checksum]); }
function contains(shape, x, y) {
  if (shape.type === 'circle') return (x - shape.cx) ** 2 + (y - shape.cy) ** 2 <= shape.r ** 2;
  const px = x - shape.x;
  const py = y - shape.y;
  const { width: w, height: h, top: t, bottom: b } = shape;
  if (px < 0 || px > w || py < 0 || py > h) return false;
  // Match the SVG quadratic corners: inset = radius * (1 - sqrt(v))².
  if (py < t) {
    const inset = t * (1 - Math.sqrt(py / t)) ** 2;
    return px >= inset && px <= w - inset;
  }
  if (py > h - b) {
    const inset = b * (1 - Math.sqrt((h - py) / b)) ** 2;
    return px >= inset && px <= w - inset;
  }
  return true;
}
function pixel(x, y) {
  for (let i = shapes.length - 1; i >= 0; i--) {
    if (contains(shapes[i], x, y)) return shapes[i].color;
  }
  const blend = (x + y) / 1024;
  return background[0].map((channel, i) => channel + (background[1][i] - channel) * blend);
}
function png(size) {
  // Opaque RGB, full bleed. iOS applies its own corner mask.
  // Supersampling keeps small Home Screen icons smooth without dependencies.
  const samples = 4;
  const bytesPerRow = size * 3 + 1;
  const raw = Buffer.alloc(bytesPerRow * size);
  const scale = 512 / size;
  for (let y = 0; y < size; y++) {
    raw[y * bytesPerRow] = 0;
    for (let x = 0; x < size; x++) {
      const rgb = [0, 0, 0];
      for (let sy = 0; sy < samples; sy++) {
        for (let sx = 0; sx < samples; sx++) {
          const color = pixel((x + (sx + .5) / samples) * scale, (y + (sy + .5) / samples) * scale);
          for (let c = 0; c < 3; c++) rgb[c] += color[c];
        }
      }
      const offset = y * bytesPerRow + 1 + x * 3;
      for (let c = 0; c < 3; c++) raw[offset + c] = Math.round(rgb[c] / samples ** 2);
    }
  }
  const header = Buffer.alloc(13); header.writeUInt32BE(size, 0); header.writeUInt32BE(size, 4); header[8] = 8; header[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}
for (const [file, size] of [['icon-192.png', 192], ['icon-512.png', 512], ['icon-maskable-512.png', 512], ['apple-touch-icon.png', 180]]) writeFileSync(join(output, file), png(size));
