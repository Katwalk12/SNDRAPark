/**
 * Regenerate the app icons from the brand source art.
 *
 *   node .claude/skills/run-sndrapark/gen-icons.mjs [source] [outDir]
 *
 * Defaults: assets/images/brand-logo.png -> assets/images/{favicon,brand-mark}.png
 *
 * Auto-trims to the artwork's ink bounds, then centers it on a padded square,
 * so it works whether the source is a full-bleed mark or a small glyph sitting
 * in a large white field. Uses Chrome's canvas via Playwright because PHP's GD
 * extension is not enabled in this XAMPP install.
 */
import { chromium } from './node_modules/playwright/index.mjs';
import { writeFileSync } from 'node:fs';

const SRC = process.argv[2] || 'assets/images/brand-logos.png';
const OUT = (process.argv[3] || 'assets/images').replace(/\/+$/, '');
const BASE = process.env.SP_BASE || 'http://localhost/sndraPark';

const b = await chromium.launch({ channel: 'chrome' });
const p = await b.newPage();
await p.goto(`${BASE}/frontend/pages/index.html`, { waitUntil: 'domcontentloaded' });

const out = await p.evaluate(async ([srcUrl]) => {
  const img = new Image();
  img.src = srcUrl;
  await img.decode();

  const probe = document.createElement('canvas');
  probe.width = img.width; probe.height = img.height;
  const pc = probe.getContext('2d');
  pc.drawImage(img, 0, 0);
  const d = pc.getImageData(0, 0, img.width, img.height).data;

  // "Ink" = not transparent and not near-white, so a white matte is trimmed.
  const ink = (x, y) => {
    const i = (y * img.width + x) * 4;
    if (d[i + 3] < 20) return false;
    return !(d[i] > 235 && d[i + 1] > 235 && d[i + 2] > 235);
  };

  let x0 = img.width, y0 = img.height, x1 = -1, y1 = -1;
  for (let y = 0; y < img.height; y++)
    for (let x = 0; x < img.width; x++)
      if (ink(x, y)) {
        if (x < x0) x0 = x; if (x > x1) x1 = x;
        if (y < y0) y0 = y; if (y > y1) y1 = y;
      }
  if (x1 < 0) throw new Error('source image is blank');

  const w = x1 - x0 + 1, h = y1 - y0 + 1;

  const render = (size, fill) => {
    const c = document.createElement('canvas');
    c.width = c.height = size;
    const x = c.getContext('2d');
    x.imageSmoothingQuality = 'high';
    // Keep the source's opaque ground: the sparkle is knocked out of the mark,
    // so going transparent here would punch a hole through it.
    x.fillStyle = '#ffffff';
    x.fillRect(0, 0, size, size);
    const scale = (size * fill) / Math.max(w, h);
    const dw = w * scale, dh = h * scale;
    x.drawImage(img, x0, y0, w, h, (size - dw) / 2, (size - dh) / 2, dw, dh);
    return c.toDataURL('image/png');
  };

  return { src: [img.width, img.height], trimmed: [w, h], favicon: render(128, 0.96), mark: render(256, 0.84) };
}, [`${new URL(SRC, 'http://x/').pathname.replace(/^\//, '/sndraPark/')}`.replace('/sndraPark//', '/sndraPark/')]);

const save = (u, f) => writeFileSync(f, Buffer.from(u.split(',')[1], 'base64'));
save(out.favicon, `${OUT}/favicon.png`);
save(out.mark, `${OUT}/brand-mark.png`);
console.log(`source ${out.src.join('x')} -> trimmed ${out.trimmed.join('x')}`);
console.log(`wrote ${OUT}/favicon.png (128) and ${OUT}/brand-mark.png (256)`);
await b.close();
