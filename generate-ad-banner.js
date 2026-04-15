const sharp = require('sharp');
const fs = require('fs');
const path = require('path');

const WIDTH = 1200;
const HEIGHT = 628;
const OUTPUT_PATH = path.join(__dirname, 'motorlink-ad-banner.png');

// ─── Neutral palette ──────────────────────────────────────────────────────────
// Background: very light warm-gray (almost white) — completely neutral
// Left panel: clean white card
// Right panel: light gray (#f4f5f7)
// Accent: MotorLink forest green #2d6a4f  (used sparingly for brand identity only)
// All text: dark charcoal #1a1a1a / mid-gray #6c757d  (no colored headings)

const svg = `
<svg width="${WIDTH}" height="${HEIGHT}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <!-- Neutral warm-gray background -->
    <linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#edeef0"/>
      <stop offset="100%" style="stop-color:#e4e6ea"/>
    </linearGradient>

    <!-- White card -->
    <linearGradient id="cardGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#ffffff"/>
      <stop offset="100%" style="stop-color:#fafbfc"/>
    </linearGradient>

    <!-- Right-panel light gray -->
    <linearGradient id="rightGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#f4f5f7"/>
      <stop offset="100%" style="stop-color:#edeef0"/>
    </linearGradient>

    <!-- Brand green for logo & CTA only -->
    <linearGradient id="greenGrad" x1="0%" y1="0%" x2="135%" y2="135%">
      <stop offset="0%" style="stop-color:#2d6a4f"/>
      <stop offset="100%" style="stop-color:#40916c"/>
    </linearGradient>

    <!-- Divider line between left/right panels -->
    <linearGradient id="divGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#dee2e6;stop-opacity:0"/>
      <stop offset="50%" style="stop-color:#dee2e6;stop-opacity:1"/>
      <stop offset="100%" style="stop-color:#dee2e6;stop-opacity:0"/>
    </linearGradient>

    <!-- Subtle card shadow -->
    <filter id="cardShadow" x="-4%" y="-4%" width="108%" height="115%">
      <feDropShadow dx="0" dy="6" stdDeviation="14" flood-color="#000000" flood-opacity="0.10"/>
    </filter>

    <!-- Stat-box shadow (lighter) -->
    <filter id="statShadow" x="-5%" y="-5%" width="115%" height="130%">
      <feDropShadow dx="0" dy="2" stdDeviation="5" flood-color="#000000" flood-opacity="0.07"/>
    </filter>

    <!-- CTA button shadow -->
    <filter id="btnShadow" x="-10%" y="-20%" width="130%" height="160%">
      <feDropShadow dx="0" dy="4" stdDeviation="10" flood-color="#2d6a4f" flood-opacity="0.30"/>
    </filter>

    <!-- Subtle dot pattern on background -->
    <pattern id="dots" width="28" height="28" patternUnits="userSpaceOnUse">
      <circle cx="14" cy="14" r="1.2" fill="#c8cdd4" opacity="0.45"/>
    </pattern>
  </defs>

  <!-- ── Background ── -->
  <rect width="${WIDTH}" height="${HEIGHT}" fill="url(#bgGrad)"/>
  <rect width="${WIDTH}" height="${HEIGHT}" fill="url(#dots)"/>

  <!-- ══════════════════════════════════════════════════════════════════════
       MAIN CARD  x=56 y=44  w=1088 h=540
       Left white panel: x=56..702  Right gray panel: x=702..1144
  ══════════════════════════════════════════════════════════════════════ -->

  <!-- Card base (white, full) -->
  <rect x="56" y="44" width="1088" height="540" rx="24" fill="url(#cardGrad)" filter="url(#cardShadow)"/>

  <!-- Right-panel gray overlay (clip right half only) -->
  <clipPath id="rightClip">
    <rect x="702" y="44" width="442" height="540" rx="0"/>
  </clipPath>
  <!-- Right clip with rounded right corners -->
  <clipPath id="cardClip">
    <rect x="56" y="44" width="1088" height="540" rx="24"/>
  </clipPath>
  <rect x="702" y="44" width="442" height="540" fill="url(#rightGrad)" clip-path="url(#cardClip)"/>

  <!-- Divider line between panels -->
  <rect x="701" y="44" width="1" height="540" fill="url(#divGrad)" clip-path="url(#cardClip)"/>

  <!-- Top brand accent bar (full width, thin) -->
  <rect x="56" y="44" width="1088" height="4" rx="2" fill="url(#greenGrad)" clip-path="url(#cardClip)"/>

  <!-- ──────────────────────────────────────────────────────────────────
       LEFT PANEL  (x 56–702, usable from x 88)
  ────────────────────────────────────────────────────────────────────── -->

  <!-- LOGO MARK -->
  <rect x="88" y="84" width="58" height="58" rx="16" fill="url(#greenGrad)"/>
  <!-- Car icon (simple geometric car silhouette in white) -->
  <!-- Car body -->
  <rect x="96" y="102" width="42" height="22" rx="5" fill="white" opacity="0.95"/>
  <!-- Windshield cutout illusion — roof -->
  <rect x="100" y="95" width="28" height="14" rx="4" fill="white" opacity="0.8"/>
  <!-- Wheels -->
  <circle cx="102" cy="126" r="6" fill="#2d6a4f" stroke="white" stroke-width="2"/>
  <circle cx="130" cy="126" r="6" fill="#2d6a4f" stroke="white" stroke-width="2"/>

  <!-- BRAND NAME -->
  <text x="158" y="112" font-family="Segoe UI, Arial, sans-serif" font-size="30" font-weight="900" fill="#1a1a1a" letter-spacing="-0.5">MotorLink</text>
  <text x="159" y="131" font-family="Segoe UI, Arial, sans-serif" font-size="12" font-weight="700" fill="#6c757d" letter-spacing="1.8">MALAWI'S CAR MARKETPLACE</text>

  <!-- HORIZONTAL RULE under brand -->
  <rect x="88" y="154" width="200" height="2" rx="1" fill="#2d6a4f" opacity="0.25"/>

  <!-- HEADLINE  (neutral charcoal — no green in headline) -->
  <text x="88" y="200" font-family="Segoe UI, Arial, sans-serif" font-size="38" font-weight="900" fill="#1a1a1a" letter-spacing="-1.2">Buy, Sell &amp; Rent Vehicles</text>
  <text x="88" y="244" font-family="Segoe UI, Arial, sans-serif" font-size="38" font-weight="900" fill="#3a3d42" letter-spacing="-1.2">Across All of Malawi</text>

  <!-- SUBTITLE  -->
  <text x="88" y="282" font-family="Segoe UI, Arial, sans-serif" font-size="15" fill="#545b62" letter-spacing="0">Connect with verified dealers, garages &amp; car hire companies</text>
  <text x="88" y="302" font-family="Segoe UI, Arial, sans-serif" font-size="15" fill="#545b62">in every district — powered by AI-driven search and matching.</text>

  <!-- FEATURE PILLS  (neutral dark fill, no colored text) -->
  <!-- Pill 1: Buy -->
  <rect x="88" y="326" width="90" height="32" rx="16" fill="#1a1a1a"/>
  <text x="133" y="347" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">Buy a Car</text>

  <!-- Pill 2: Sell -->
  <rect x="186" y="326" width="90" height="32" rx="16" fill="#343a40"/>
  <text x="231" y="347" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">Sell a Car</text>

  <!-- Pill 3: Rent -->
  <rect x="284" y="326" width="100" height="32" rx="16" fill="#495057"/>
  <text x="334" y="347" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">Car Hire</text>

  <!-- Pill 4: Service -->
  <rect x="393" y="326" width="100" height="32" rx="16" fill="#6c757d"/>
  <text x="443" y="347" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">Garages</text>

  <!-- Pill 5: AI -->
  <rect x="501" y="326" width="116" height="32" rx="16" fill="#868e96"/>
  <text x="559" y="347" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">AI-Powered</text>

  <!-- CTA BUTTON  (brand green — the ONE green element in left panel) -->
  <rect x="88" y="380" width="216" height="50" rx="25" fill="url(#greenGrad)" filter="url(#btnShadow)"/>
  <text x="196" y="411" font-family="Segoe UI, Arial, sans-serif" font-size="16" font-weight="800" fill="white" text-anchor="middle">Explore MotorLink  →</text>

  <!-- URL below CTA -->
  <text x="88" y="452" font-family="Segoe UI, Arial, sans-serif" font-size="13" fill="#6c757d">promanaged-it.com/motorlink</text>

  <!-- ──────────────────────────────────────────────────────────────────
       RIGHT PANEL  (x 702–1144, center = 923)
       Layout: 4 stat boxes top, "POWERED BY" panel bottom
  ────────────────────────────────────────────────────────────────────── -->

  <!-- ── Section label ── -->
  <text x="923" y="84" font-family="Segoe UI, Arial, sans-serif" font-size="11" font-weight="700" fill="#9ca3af" text-anchor="middle" letter-spacing="2">PLATFORM AT A GLANCE</text>

  <!-- ── 4 stat boxes in 2×2 grid ──
       Each box: 186 wide × 90 tall  gap: 14
       Col 1 x=722  Col 2 x=922   Row 1 y=98  Row 2 y=202 -->

  <!-- Stat 1: Active Listings -->
  <rect x="722" y="98" width="186" height="90" rx="14" fill="white" filter="url(#statShadow)" stroke="#e9ecef" stroke-width="1"/>
  <!-- Left accent bar -->
  <rect x="722" y="98" width="4" height="90" rx="2" fill="url(#greenGrad)" clip-path="url(#cardClip)"/>
  <text x="918" y="145" font-family="Segoe UI, Arial, sans-serif" font-size="34" font-weight="900" fill="#1a1a1a" text-anchor="end" letter-spacing="-1">5,000+</text>
  <text x="918" y="170" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="600" fill="#6c757d" text-anchor="end">Active Car Listings</text>

  <!-- Stat 2: Verified Dealers -->
  <rect x="922" y="98" width="186" height="90" rx="14" fill="white" filter="url(#statShadow)" stroke="#e9ecef" stroke-width="1"/>
  <rect x="922" y="98" width="4" height="90" rx="2" fill="#495057" clip-path="url(#cardClip)"/>
  <text x="1118" y="145" font-family="Segoe UI, Arial, sans-serif" font-size="34" font-weight="900" fill="#1a1a1a" text-anchor="end" letter-spacing="-1">200+</text>
  <text x="1118" y="170" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="600" fill="#6c757d" text-anchor="end">Verified Dealers</text>

  <!-- Stat 3: Districts -->
  <rect x="722" y="202" width="186" height="90" rx="14" fill="white" filter="url(#statShadow)" stroke="#e9ecef" stroke-width="1"/>
  <rect x="722" y="202" width="4" height="90" rx="2" fill="#343a40" clip-path="url(#cardClip)"/>
  <text x="918" y="248" font-family="Segoe UI, Arial, sans-serif" font-size="34" font-weight="900" fill="#1a1a1a" text-anchor="end" letter-spacing="-1">28</text>
  <text x="918" y="273" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="600" fill="#6c757d" text-anchor="end">Districts Covered</text>

  <!-- Stat 4: Free to start -->
  <rect x="922" y="202" width="186" height="90" rx="14" fill="white" filter="url(#statShadow)" stroke="#e9ecef" stroke-width="1"/>
  <rect x="922" y="202" width="4" height="90" rx="2" fill="#868e96" clip-path="url(#cardClip)"/>
  <text x="1118" y="248" font-family="Segoe UI, Arial, sans-serif" font-size="34" font-weight="900" fill="#1a1a1a" text-anchor="end" letter-spacing="-1">FREE</text>
  <text x="1118" y="273" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="600" fill="#6c757d" text-anchor="end">To Get Started</text>

  <!-- ── POWERED BY panel ──
       x=722 y=310 w=386 h=240  (fills right panel bottom) -->
  <rect x="722" y="310" width="386" height="240" rx="18" fill="#1f2329" clip-path="url(#cardClip)"/>

  <!-- Subtle grid lines inside panel -->
  <rect x="722" y="310" width="386" height="1" fill="white" opacity="0.06"/>

  <!-- Label -->
  <text x="922" y="346" font-family="Segoe UI, Arial, sans-serif" font-size="11" font-weight="700" fill="#6c757d" text-anchor="middle" letter-spacing="2.5">PLATFORM BUILT &amp; MANAGED BY</text>

  <!-- Horizontal rule -->
  <rect x="760" y="358" width="324" height="1" fill="white" opacity="0.10"/>

  <!-- ProManaged IT logo icon -->
  <rect x="782" y="374" width="44" height="44" rx="12" fill="#2d6a4f"/>
  <!-- Gear icon (simple circle + spokes) -->
  <circle cx="804" cy="396" r="10" fill="none" stroke="white" stroke-width="2.5"/>
  <circle cx="804" cy="396" r="4" fill="white"/>
  <!-- Gear teeth (8 spokes) -->
  <line x1="804" y1="382" x2="804" y2="386" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
  <line x1="804" y1="406" x2="804" y2="410" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
  <line x1="790" y1="396" x2="794" y2="396" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
  <line x1="814" y1="396" x2="818" y2="396" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
  <line x1="794" y1="386" x2="797" y2="389" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
  <line x1="811" y1="403" x2="814" y2="406" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
  <line x1="794" y1="406" x2="797" y2="403" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
  <line x1="811" y1="389" x2="814" y2="386" stroke="white" stroke-width="2.5" stroke-linecap="round"/>

  <!-- Company name — fits within panel, right of icon -->
  <text x="838" y="389" font-family="Segoe UI, Arial, sans-serif" font-size="20" font-weight="900" fill="white" letter-spacing="-0.3">ProManaged IT</text>
  <text x="838" y="410" font-family="Segoe UI, Arial, sans-serif" font-size="12" fill="#9ca3af" letter-spacing="0.5">Digital &amp; IT Solutions</text>

  <!-- Description -->
  <text x="782" y="442" font-family="Segoe UI, Arial, sans-serif" font-size="13.5" fill="#d1d5db">Professional IT solutions, web development</text>
  <text x="782" y="462" font-family="Segoe UI, Arial, sans-serif" font-size="13.5" fill="#d1d5db">and managed hosting for Malawi businesses.</text>

  <!-- Link -->
  <text x="782" y="490" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="#40916c" letter-spacing="0.2">promanaged-it.com  →</text>

  <!-- ── FOOTER BAR ── (inside card, bottom strip) -->
  <rect x="56" y="544" width="1088" height="40" rx="0" fill="#f1f3f5" clip-path="url(#cardClip)"/>
  <rect x="56" y="544" width="1088" height="1" fill="#dee2e6"/>
  <text x="84" y="569" font-family="Segoe UI, Arial, sans-serif" font-size="12.5" fill="#6c757d">MotorLink Malawi — Connecting Malawi's automotive community since 2025.  Built by ProManaged IT.</text>
  <text x="1124" y="569" font-family="Segoe UI, Arial, sans-serif" font-size="12.5" font-weight="700" fill="#2d6a4f" text-anchor="end">promanaged-it.com/motorlink</text>
</svg>
`;

async function generateBanner() {
  try {
    console.log('Generating MotorLink ad banner...');

    // Convert SVG to PNG using sharp
    await sharp(Buffer.from(svg))
      .resize(WIDTH, HEIGHT, {
        fit: 'fill',
      })
      .png({ quality: 100 })
      .toFile(OUTPUT_PATH);

    console.log(`Banner saved to: ${OUTPUT_PATH}`);
    console.log(`Dimensions: ${WIDTH}x${HEIGHT}px`);

    // Verify the file was created
    const stats = fs.statSync(OUTPUT_PATH);
    console.log(`File size: ${(stats.size / 1024).toFixed(2)} KB`);
  } catch (error) {
    console.error('Error generating banner:', error);
    process.exit(1);
  }
}

generateBanner();
