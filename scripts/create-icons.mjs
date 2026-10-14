import { mkdirSync, writeFileSync } from 'node:fs';
import { deflateSync } from 'node:zlib';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const output = fileURLToPath(new URL('../assets/icons/', import.meta.url));
mkdirSync(output, { recursive: true });
const crcTable = new Uint32Array(256);
for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; crcTable[n] = c >>> 0; }
function crc32(buffer) { let crc = 0xffffffff; for (const byte of buffer) crc = crcTable[(crc ^ byte) & 0xff] ^ (crc >>> 8); return (crc ^ 0xffffffff) >>> 0; }
function chunk(name, data) { const type = Buffer.from(name); const length = Buffer.alloc(4); length.writeUInt32BE(data.length); const checksum = Buffer.alloc(4); checksum.writeUInt32BE(crc32(Buffer.concat([type, data]))); return Buffer.concat([length, type, data, checksum]); }
function inRoundRect(x, y, left, top, right, bottom, radius) { const cx = Math.max(left + radius, Math.min(right - radius, x)); const cy = Math.max(top + radius, Math.min(bottom - radius, y)); return (x - cx) ** 2 + (y - cy) ** 2 <= radius ** 2; }
function circle(x, y, cx, cy, r) { return (x - cx) ** 2 + (y - cy) ** 2 <= r ** 2; }
function personShape(x, y, s) { const head = circle(x, y, s * .5, s * .34, s * .095); const shoulders = inRoundRect(x, y, s * .27, s * .47, s * .73, s * .73, s * .16); return head || shoulders; }
function png(size, maskable = false) {
  const bytesPerRow = size * 4 + 1; const raw = Buffer.alloc(bytesPerRow * size); const scale = size / 512;
  for (let y = 0; y < size; y++) {
    raw[y * bytesPerRow] = 0;
    for (let x = 0; x < size; x++) {
      const px = (x + .5) / scale; const py = (y + .5) / scale; let rgba = [15, 118, 110, 255];
      if (!maskable && !inRoundRect(px, py, 0, 0, 512, 512, 112)) rgba = [0, 0, 0, 0];
      const pad = maskable ? 96 : 62; const base = (512 - pad * 2); const shapeX = ((px - pad) / base) * 512; const shapeY = ((py - pad) / base) * 512;
      const panel = inRoundRect(shapeX, shapeY, 60, 63, 452, 449, 92);
      const first = personShape(shapeX, shapeY, 512);
      const mini1 = personShape(shapeX - 104, shapeY + 92, 245);
      const mini2 = personShape(shapeX + 104, shapeY + 92, 245);
      if ((panel || first || mini1 || mini2) && rgba[3]) rgba = [255, 255, 255, 255];
      const i = y * bytesPerRow + 1 + x * 4; raw[i] = rgba[0]; raw[i + 1] = rgba[1]; raw[i + 2] = rgba[2]; raw[i + 3] = rgba[3];
    }
  }
  const header = Buffer.alloc(13); header.writeUInt32BE(size, 0); header.writeUInt32BE(size, 4); header[8] = 8; header[9] = 6;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}
for (const [file, size, maskable] of [['icon-192.png', 192, false], ['icon-512.png', 512, false], ['icon-maskable-512.png', 512, true], ['apple-touch-icon.png', 180, false]]) writeFileSync(join(output, file), png(size, maskable));
