# Sponsor design preview

The five approved sponsor tiles are published through `data/sponsors.json` and were verified on the live website on 28 October 2026. This folder preserves the artwork sources, design iterations, and export notes.

| New sponsor | Tier | Existing background reference |
| --- | --- | --- |
| Dental Blueprint | Gold | Mandali Company / La Colina |
| DentStar Company | Silver | Lacalut |
| Venus Dental Lab | Silver | Lacalut |
| Elite Dental | Silver | Lacalut |
| Dentick Company | Bronze | Gengigel |

The original logo inputs are the user's Desktop files: `Gold.jpeg`, `silver.jpeg`, `silver.PNG`, `serliver.png`, and `dentick  bronze.jpeg`.

Backgrounds were reconstructed with the built-in image generation tool using existing tier tiles as edit targets. Prompt: remove only the existing sponsor mark, reconstruct the background behind it, and preserve the tier's original color, gradient, texture, lighting, curved lower-right sweep, and 9:5 proportions. No added text or decoration.

DentStar uses the supplied teal and pink logo with its white background removed using Pencil. The other final logos render the original source artwork through `reverse-logo.glsl` in Pencil: original dark ink becomes white, white paper and interior negative spaces become transparent, and Dental Blueprint retains its cyan accent. Cropping removes excessive paper margins. This preserves the source letterforms and shapes. Generated white logo variants were not used.

Final individual artwork is exported as lossless 2160 × 1200 PNGs under `high-resolution`. The original source resolution still limits the finest logo detail; exports avoid further JPEG compression. The review overview is `all-sponsors-preview.png`.

Dentick was subsequently refined from the original logo using the built-in image editing tool to create smooth, solid white artwork directly on the Bronze background, without the speckled edges of the first version. Edit prompt: preserve the original tooth, swoosh, sparkle, concentric circles, `DENTICK`, `COMPANY`, and `EST. 2009`; remove JPEG speckling, jagged edges and pinholes; use crisp white linework with all negative spaces revealing the supplied Bronze background. The native 1681 × 936 composite is `dentick-bronze-clean.png`, and its lossless website asset is `assets/sponsors/dentick-bronze-hq.webp`.

Website images use lossless WebP encoding of the approved PNG artwork, preserving decoded pixels without introducing JPEG compression. The standalone Dentick download is also available at the standard 2160 × 1200 tile export size.

DentStar was updated at the user's request: both company name and tagline are white; the teal and pink tooth/star symbol and Silver background retain their original colors. This is rendered in Pencil through `dentstar-white-text.glsl`. The current website file is `assets/sponsors/dentstar-silver-white.webp` (2160 × 1200).

Responsive refinement: the sponsor wall now fits complete tile images with `object-fit: contain`, centres their image frames independently of the shared partner styles, and uses two Gold columns and three Silver/Bronze columns on phones. Elite's visible white artwork is centred within one pixel of the 2160 × 1200 canvas centre; Dental Blueprint's artwork is reduced by 12%. Current assets are `dental-blueprint-gold-v2.webp` and `elite-dental-silver-v2.webp`. Browser checks cover widths 320, 335, 390, 560, 561, 768, 1024, 1440 and 1920 in English, Arabic and Kurdish, in light and dark themes.
