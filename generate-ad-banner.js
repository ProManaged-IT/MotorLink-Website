const sharp = require('sharp');
const fs = require('fs');
const path = require('path');

const BASE_WIDTH = 1200;
const BASE_HEIGHT = 628;
const UHD_WIDTH = 2400;
const UHD_HEIGHT = 1256;
const MOBILE_WIDTH = 1080;
const MOBILE_HEIGHT = 1350;

const ADS_DIR = path.join(__dirname, 'ads');
const OUTPUT_STANDARD_PATH = path.join(ADS_DIR, 'motorlink-ad-banner.png');
const OUTPUT_UHD_PATH = path.join(ADS_DIR, 'motorlink-ad-banner-uhd.png');
const OUTPUT_MOBILE_PATH = path.join(ADS_DIR, 'motorlink-ad-banner-mobile.png');
const SOCIAL_OUTPUT_DIR = path.join(ADS_DIR, 'social');

const SOCIAL_PRESETS = [
  {
    filename: 'facebook-feed-link-ad-1200x628.jpg',
    width: 1200,
    height: 628,
    source: 'desktop',
    fit: 'fill',
    quality: 86,
    channels: 'Facebook Feed, Facebook Link Ads'
  },
  {
    filename: 'instagram-feed-portrait-1080x1350.jpg',
    width: 1080,
    height: 1350,
    source: 'mobile',
    fit: 'fill',
    quality: 86,
    channels: 'Instagram Feed (Portrait), Facebook Feed (Portrait)'
  },
  {
    filename: 'instagram-feed-square-1080x1080.jpg',
    width: 1080,
    height: 1080,
    source: 'desktop',
    fit: 'contain',
    quality: 84,
    channels: 'Instagram Feed (Square), Facebook Feed (Square)'
  },
  {
    filename: 'stories-reels-1080x1920.jpg',
    width: 1080,
    height: 1920,
    source: 'mobile',
    fit: 'contain',
    quality: 84,
    channels: 'Instagram Stories, Facebook Stories, Reels, TikTok Ads'
  },
  {
    filename: 'whatsapp-status-1080x1920.jpg',
    width: 1080,
    height: 1920,
    source: 'mobile',
    fit: 'contain',
    quality: 82,
    channels: 'WhatsApp Status'
  },
  {
    filename: 'x-twitter-post-1600x900.jpg',
    width: 1600,
    height: 900,
    source: 'desktop',
    fit: 'fill',
    quality: 85,
    channels: 'X (Twitter) Organic/Promoted Post'
  },
  {
    filename: 'linkedin-sponsored-content-1200x627.jpg',
    width: 1200,
    height: 627,
    source: 'desktop',
    fit: 'fill',
    quality: 86,
    channels: 'LinkedIn Sponsored Content'
  }
];

function getDesktopSvg() {
  return `
<svg width="${BASE_WIDTH}" height="${BASE_HEIGHT}" viewBox="0 0 ${BASE_WIDTH} ${BASE_HEIGHT}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#eef0f3"/>
      <stop offset="100%" style="stop-color:#e4e7eb"/>
    </linearGradient>

    <linearGradient id="cardGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#ffffff"/>
      <stop offset="100%" style="stop-color:#fbfcfd"/>
    </linearGradient>

    <linearGradient id="rightGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#f5f6f8"/>
      <stop offset="100%" style="stop-color:#eff1f4"/>
    </linearGradient>

    <linearGradient id="greenGrad" x1="0%" y1="0%" x2="130%" y2="130%">
      <stop offset="0%" style="stop-color:#2d6a4f"/>
      <stop offset="100%" style="stop-color:#40916c"/>
    </linearGradient>

    <linearGradient id="dividerGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#dde2e8;stop-opacity:0"/>
      <stop offset="50%" style="stop-color:#dde2e8;stop-opacity:1"/>
      <stop offset="100%" style="stop-color:#dde2e8;stop-opacity:0"/>
    </linearGradient>

    <filter id="cardShadow" x="-4%" y="-5%" width="108%" height="115%">
      <feDropShadow dx="0" dy="8" stdDeviation="14" flood-color="#000000" flood-opacity="0.10"/>
    </filter>

    <filter id="boxShadow" x="-8%" y="-8%" width="120%" height="130%">
      <feDropShadow dx="0" dy="2" stdDeviation="5" flood-color="#000000" flood-opacity="0.07"/>
    </filter>

    <filter id="ctaShadow" x="-10%" y="-20%" width="130%" height="160%">
      <feDropShadow dx="0" dy="4" stdDeviation="9" flood-color="#2d6a4f" flood-opacity="0.28"/>
    </filter>

    <pattern id="dots" width="30" height="30" patternUnits="userSpaceOnUse">
      <circle cx="15" cy="15" r="1.1" fill="#cad1d8" opacity="0.38"/>
    </pattern>

    <clipPath id="cardClip">
      <rect x="48" y="32" width="1104" height="564" rx="28"/>
    </clipPath>
  </defs>

  <rect width="${BASE_WIDTH}" height="${BASE_HEIGHT}" fill="url(#bgGrad)"/>
  <rect width="${BASE_WIDTH}" height="${BASE_HEIGHT}" fill="url(#dots)"/>

  <rect x="48" y="32" width="1104" height="564" rx="28" fill="url(#cardGrad)" filter="url(#cardShadow)"/>
  <rect x="600" y="32" width="552" height="564" fill="url(#rightGrad)" clip-path="url(#cardClip)"/>
  <rect x="48" y="32" width="1104" height="4" fill="url(#greenGrad)" clip-path="url(#cardClip)"/>
  <rect x="599" y="32" width="1" height="564" fill="url(#dividerGrad)" clip-path="url(#cardClip)"/>

  <!-- Left panel center -->
  <rect x="296" y="76" width="56" height="56" rx="16" fill="url(#greenGrad)"/>
  <rect x="304" y="95" width="40" height="20" rx="5" fill="white" opacity="0.95"/>
  <rect x="308" y="88" width="28" height="13" rx="4" fill="white" opacity="0.8"/>
  <circle cx="311" cy="117" r="5.5" fill="#2d6a4f" stroke="white" stroke-width="2"/>
  <circle cx="337" cy="117" r="5.5" fill="#2d6a4f" stroke="white" stroke-width="2"/>

  <text x="324" y="160" font-family="Segoe UI, Arial, sans-serif" font-size="42" font-weight="900" fill="#1a1a1a" text-anchor="middle" letter-spacing="-0.6">MotorLink</text>
  <text x="324" y="178" font-family="Segoe UI, Arial, sans-serif" font-size="12" font-weight="700" fill="#6c757d" text-anchor="middle" letter-spacing="2">MALAWI'S CAR MARKETPLACE</text>
  <rect x="199" y="188" width="250" height="2" rx="1" fill="#2d6a4f" opacity="0.22"/>

  <text x="324" y="236" font-family="Segoe UI, Arial, sans-serif" font-size="46" font-weight="900" fill="#1a1a1a" text-anchor="middle" letter-spacing="-0.8">Buy, Sell &amp; Rent Vehicles</text>
  <text x="324" y="284" font-family="Segoe UI, Arial, sans-serif" font-size="46" font-weight="900" fill="#3c4148" text-anchor="middle" letter-spacing="-0.8">Across All of Malawi</text>

  <text x="324" y="322" font-family="Segoe UI, Arial, sans-serif" font-size="16" fill="#555f69" text-anchor="middle">Connect with verified dealers, garages &amp; car hire companies</text>
  <text x="324" y="344" font-family="Segoe UI, Arial, sans-serif" font-size="16" fill="#555f69" text-anchor="middle">powered by AI-driven search, filters, and recommendations.</text>

  <!-- Pill row -->
  <rect x="77" y="370" width="86" height="32" rx="16" fill="#1a1a1a"/>
  <text x="120" y="391" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">Buy</text>

  <rect x="173" y="370" width="86" height="32" rx="16" fill="#343a40"/>
  <text x="216" y="391" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">Sell</text>

  <rect x="269" y="370" width="94" height="32" rx="16" fill="#495057"/>
  <text x="316" y="391" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">Car Hire</text>

  <rect x="373" y="370" width="92" height="32" rx="16" fill="#6c757d"/>
  <text x="419" y="391" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">Garages</text>

  <rect x="475" y="370" width="96" height="32" rx="16" fill="#868e96"/>
  <text x="523" y="391" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="700" fill="white" text-anchor="middle">AI Smart</text>

  <rect x="204" y="420" width="240" height="52" rx="26" fill="url(#greenGrad)" filter="url(#ctaShadow)"/>
  <text x="324" y="453" font-family="Segoe UI, Arial, sans-serif" font-size="18" font-weight="800" fill="white" text-anchor="middle">Explore MotorLink  →</text>

  <text x="324" y="492" font-family="Segoe UI, Arial, sans-serif" font-size="13.5" fill="#6c757d" text-anchor="middle">motorlink.mw</text>

  <!-- Right panel centered -->
  <text x="876" y="86" font-family="Segoe UI, Arial, sans-serif" font-size="11" font-weight="700" fill="#99a1ab" text-anchor="middle" letter-spacing="2.3">PLATFORM AT A GLANCE</text>

  <rect x="630" y="102" width="236" height="96" rx="14" fill="white" stroke="#e9edf2" filter="url(#boxShadow)"/>
  <rect x="630" y="102" width="4" height="96" rx="2" fill="url(#greenGrad)"/>
  <text x="748" y="150" font-family="Segoe UI, Arial, sans-serif" font-size="46" font-weight="900" fill="#1a1a1a" text-anchor="middle" letter-spacing="-1">24/7</text>
  <text x="748" y="175" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="600" fill="#66707a" text-anchor="middle">Always Available</text>

  <rect x="886" y="102" width="236" height="96" rx="14" fill="white" stroke="#e9edf2" filter="url(#boxShadow)"/>
  <rect x="886" y="102" width="4" height="96" rx="2" fill="#495057"/>
  <text x="1004" y="150" font-family="Segoe UI, Arial, sans-serif" font-size="46" font-weight="900" fill="#1a1a1a" text-anchor="middle" letter-spacing="-1">200+</text>
  <text x="1004" y="175" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="600" fill="#66707a" text-anchor="middle">Verified Dealers</text>

  <rect x="630" y="214" width="236" height="96" rx="14" fill="white" stroke="#e9edf2" filter="url(#boxShadow)"/>
  <rect x="630" y="214" width="4" height="96" rx="2" fill="#343a40"/>
  <text x="748" y="262" font-family="Segoe UI, Arial, sans-serif" font-size="46" font-weight="900" fill="#1a1a1a" text-anchor="middle" letter-spacing="-1">28</text>
  <text x="748" y="287" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="600" fill="#66707a" text-anchor="middle">Districts Covered</text>

  <rect x="886" y="214" width="236" height="96" rx="14" fill="white" stroke="#e9edf2" filter="url(#boxShadow)"/>
  <rect x="886" y="214" width="4" height="96" rx="2" fill="#868e96"/>
  <text x="1004" y="262" font-family="Segoe UI, Arial, sans-serif" font-size="46" font-weight="900" fill="#1a1a1a" text-anchor="middle" letter-spacing="-1">FREE</text>
  <text x="1004" y="287" font-family="Segoe UI, Arial, sans-serif" font-size="13" font-weight="600" fill="#66707a" text-anchor="middle">To Get Started</text>

  <rect x="630" y="330" width="492" height="214" rx="20" fill="#1f2329"/>
  <text x="876" y="362" font-family="Segoe UI, Arial, sans-serif" font-size="11" font-weight="700" fill="#7b838d" text-anchor="middle" letter-spacing="2.3">PLATFORM BUILT &amp; MANAGED BY</text>
  <rect x="670" y="372" width="412" height="1" fill="white" opacity="0.1"/>

  <rect x="852" y="382" width="48" height="48" rx="13" fill="url(#greenGrad)"/>
  <text x="876" y="416" font-family="Segoe UI, Arial, sans-serif" font-size="26" font-weight="900" fill="white" text-anchor="middle">P</text>

  <text x="876" y="454" font-family="Segoe UI, Arial, sans-serif" font-size="20" font-weight="700" fill="#a3abb4" text-anchor="middle" letter-spacing="-0.3">ProManaged IT</text>
  <text x="876" y="474" font-family="Segoe UI, Arial, sans-serif" font-size="12" fill="#7b838d" text-anchor="middle">IT Solutions, Malawi</text>

  <rect x="48" y="548" width="1104" height="48" fill="#f2f4f6" clip-path="url(#cardClip)"/>
  <rect x="48" y="548" width="1104" height="1" fill="#dce2e8"/>
  <text x="84" y="578" font-family="Segoe UI, Arial, sans-serif" font-size="12.5" fill="#6c757d">MotorLink Malawi — Connecting Malawi's automotive community.</text>
  <text x="1120" y="578" font-family="Segoe UI, Arial, sans-serif" font-size="12.5" font-weight="700" fill="#2d6a4f" text-anchor="end">motorlink.mw</text>
</svg>
`;
}

function getMobileSvg() {
  return `
<svg width="${MOBILE_WIDTH}" height="${MOBILE_HEIGHT}" viewBox="0 0 ${MOBILE_WIDTH} ${MOBILE_HEIGHT}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="mobileBg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#eef0f3"/>
      <stop offset="100%" style="stop-color:#e3e7eb"/>
    </linearGradient>

    <linearGradient id="mobileCard" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#ffffff"/>
      <stop offset="100%" style="stop-color:#f8fafc"/>
    </linearGradient>

    <linearGradient id="mobileGreen" x1="0%" y1="0%" x2="130%" y2="130%">
      <stop offset="0%" style="stop-color:#2d6a4f"/>
      <stop offset="100%" style="stop-color:#40916c"/>
    </linearGradient>

    <filter id="mobileCardShadow" x="-4%" y="-4%" width="108%" height="112%">
      <feDropShadow dx="0" dy="10" stdDeviation="16" flood-color="#000000" flood-opacity="0.11"/>
    </filter>

    <filter id="mobileBoxShadow" x="-7%" y="-7%" width="114%" height="125%">
      <feDropShadow dx="0" dy="2" stdDeviation="5" flood-color="#000000" flood-opacity="0.07"/>
    </filter>
  </defs>

  <rect width="${MOBILE_WIDTH}" height="${MOBILE_HEIGHT}" fill="url(#mobileBg)"/>

  <rect x="68" y="56" width="944" height="1238" rx="40" fill="url(#mobileCard)" filter="url(#mobileCardShadow)"/>
  <rect x="68" y="56" width="944" height="6" fill="url(#mobileGreen)"/>

  <rect x="490" y="102" width="100" height="100" rx="28" fill="url(#mobileGreen)"/>
  <rect x="505" y="135" width="70" height="35" rx="9" fill="white" opacity="0.96"/>
  <rect x="512" y="124" width="56" height="22" rx="7" fill="white" opacity="0.82"/>
  <circle cx="517" cy="174" r="9" fill="#2d6a4f" stroke="white" stroke-width="3"/>
  <circle cx="563" cy="174" r="9" fill="#2d6a4f" stroke="white" stroke-width="3"/>

  <text x="540" y="258" font-family="Segoe UI, Arial, sans-serif" font-size="58" font-weight="900" fill="#1a1a1a" text-anchor="middle" letter-spacing="-0.6">MotorLink</text>
  <text x="540" y="286" font-family="Segoe UI, Arial, sans-serif" font-size="18" font-weight="700" fill="#6c757d" text-anchor="middle" letter-spacing="2.1">MALAWI'S CAR MARKETPLACE</text>

  <text x="540" y="350" font-family="Segoe UI, Arial, sans-serif" font-size="58" font-weight="900" fill="#1a1a1a" text-anchor="middle" letter-spacing="-0.9">Buy, Sell &amp; Rent</text>
  <text x="540" y="404" font-family="Segoe UI, Arial, sans-serif" font-size="58" font-weight="900" fill="#3a4047" text-anchor="middle" letter-spacing="-0.9">Vehicles in Malawi</text>

  <text x="540" y="452" font-family="Segoe UI, Arial, sans-serif" font-size="25" fill="#56616b" text-anchor="middle">Verified dealers, garages and car hire businesses</text>
  <text x="540" y="484" font-family="Segoe UI, Arial, sans-serif" font-size="25" fill="#56616b" text-anchor="middle">with AI-powered discovery and matching.</text>

  <rect x="168" y="524" width="208" height="54" rx="27" fill="#1a1a1a"/>
  <text x="272" y="559" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="white" text-anchor="middle">Buy</text>

  <rect x="436" y="524" width="208" height="54" rx="27" fill="#343a40"/>
  <text x="540" y="559" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="white" text-anchor="middle">Sell</text>

  <rect x="704" y="524" width="208" height="54" rx="27" fill="#495057"/>
  <text x="808" y="559" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="white" text-anchor="middle">Car Hire</text>

  <rect x="258" y="590" width="250" height="54" rx="27" fill="#6c757d"/>
  <text x="383" y="625" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="white" text-anchor="middle">Garages</text>

  <rect x="572" y="590" width="250" height="54" rx="27" fill="#868e96"/>
  <text x="697" y="625" font-family="Segoe UI, Arial, sans-serif" font-size="22" font-weight="700" fill="white" text-anchor="middle">AI Smart</text>

  <rect x="290" y="672" width="500" height="86" rx="43" fill="url(#mobileGreen)"/>
  <text x="540" y="725" font-family="Segoe UI, Arial, sans-serif" font-size="32" font-weight="800" fill="white" text-anchor="middle">Explore MotorLink  →</text>

  <text x="540" y="788" font-family="Segoe UI, Arial, sans-serif" font-size="22" fill="#6c757d" text-anchor="middle">motorlink.mw</text>

  <text x="540" y="834" font-family="Segoe UI, Arial, sans-serif" font-size="18" font-weight="700" fill="#99a1ab" text-anchor="middle" letter-spacing="2.6">PLATFORM AT A GLANCE</text>

  <rect x="110" y="850" width="430" height="102" rx="18" fill="white" stroke="#e8edf2" filter="url(#mobileBoxShadow)"/>
  <text x="325" y="904" font-family="Segoe UI, Arial, sans-serif" font-size="50" font-weight="900" fill="#1a1a1a" text-anchor="middle">24/7</text>
  <text x="325" y="932" font-family="Segoe UI, Arial, sans-serif" font-size="20" font-weight="600" fill="#6a747e" text-anchor="middle">Always Online</text>

  <rect x="570" y="850" width="430" height="102" rx="18" fill="white" stroke="#e8edf2" filter="url(#mobileBoxShadow)"/>
  <text x="785" y="904" font-family="Segoe UI, Arial, sans-serif" font-size="50" font-weight="900" fill="#1a1a1a" text-anchor="middle">200+</text>
  <text x="785" y="932" font-family="Segoe UI, Arial, sans-serif" font-size="20" font-weight="600" fill="#6a747e" text-anchor="middle">Dealers</text>

  <rect x="110" y="966" width="430" height="102" rx="18" fill="white" stroke="#e8edf2" filter="url(#mobileBoxShadow)"/>
  <text x="325" y="1020" font-family="Segoe UI, Arial, sans-serif" font-size="50" font-weight="900" fill="#1a1a1a" text-anchor="middle">28</text>
  <text x="325" y="1048" font-family="Segoe UI, Arial, sans-serif" font-size="20" font-weight="600" fill="#6a747e" text-anchor="middle">Districts</text>

  <rect x="570" y="966" width="430" height="102" rx="18" fill="white" stroke="#e8edf2" filter="url(#mobileBoxShadow)"/>
  <text x="785" y="1020" font-family="Segoe UI, Arial, sans-serif" font-size="50" font-weight="900" fill="#1a1a1a" text-anchor="middle">FREE</text>
  <text x="785" y="1048" font-family="Segoe UI, Arial, sans-serif" font-size="20" font-weight="600" fill="#6a747e" text-anchor="middle">To Start</text>

  <rect x="110" y="1092" width="860" height="176" rx="24" fill="#1f2329"/>
  <text x="540" y="1130" font-family="Segoe UI, Arial, sans-serif" font-size="19" font-weight="700" fill="#7f8790" text-anchor="middle" letter-spacing="2.6">BUILT &amp; MANAGED BY</text>
  <text x="540" y="1186" font-family="Segoe UI, Arial, sans-serif" font-size="24" font-weight="700" fill="#a3abb4" text-anchor="middle">ProManaged IT</text>
  <text x="540" y="1218" font-family="Segoe UI, Arial, sans-serif" font-size="20" fill="#7f8790" text-anchor="middle">IT Solutions, Malawi</text>
</svg>
`;
}

async function writePng(svgMarkup, outputPath, width, height) {
  await sharp(Buffer.from(svgMarkup), { density: 320 })
    .resize(width, height, { fit: 'fill', kernel: sharp.kernel.lanczos3 })
    .png({ compressionLevel: 9, adaptiveFiltering: true, palette: false })
    .toFile(outputPath);
}

async function writeJpeg(svgMarkup, outputPath, width, height, fitMode, quality) {
  await sharp(Buffer.from(svgMarkup), { density: 320 })
    .resize(width, height, {
      fit: fitMode,
      position: 'center',
      background: '#eef0f3',
      kernel: sharp.kernel.lanczos3
    })
    .flatten({ background: '#eef0f3' })
    .jpeg({
      quality,
      mozjpeg: true,
      progressive: true,
      chromaSubsampling: '4:4:4'
    })
    .toFile(outputPath);
}

function ensureDirectory(dirPath) {
  if (!fs.existsSync(dirPath)) {
    fs.mkdirSync(dirPath, { recursive: true });
  }
}

async function generateSocialPack(desktopSvg, mobileSvg) {
  ensureDirectory(SOCIAL_OUTPUT_DIR);

  const lines = [
    'MotorLink Social Ad Pack',
    'Generated automatically from generate-ad-banner.js',
    '',
    'Use the files below directly in ad managers:',
    ''
  ];

  for (const preset of SOCIAL_PRESETS) {
    const sourceSvg = preset.source === 'mobile' ? mobileSvg : desktopSvg;
    const outputPath = path.join(SOCIAL_OUTPUT_DIR, preset.filename);

    await writeJpeg(
      sourceSvg,
      outputPath,
      preset.width,
      preset.height,
      preset.fit,
      preset.quality
    );

    const fileStats = fs.statSync(outputPath);
    const fileSizeKb = (fileStats.size / 1024).toFixed(2);

    lines.push(
      `${preset.filename} | ${preset.width}x${preset.height} | ${fileSizeKb} KB | ${preset.channels}`
    );
  }

  lines.push('');
  lines.push('Quick tip:');
  lines.push('Use the portrait 1080x1350 file for highest feed impact and 1080x1920 for stories/reels/status.');

  const guidePath = path.join(SOCIAL_OUTPUT_DIR, 'README.txt');
  fs.writeFileSync(guidePath, `${lines.join('\n')}\n`, 'utf8');

  return {
    directory: SOCIAL_OUTPUT_DIR,
    guidePath,
    files: SOCIAL_PRESETS.map(p => path.join(SOCIAL_OUTPUT_DIR, p.filename))
  };
}

function describeFile(filePath) {
  const stats = fs.statSync(filePath);
  return `${path.basename(filePath)} - ${(stats.size / 1024).toFixed(2)} KB`;
}

async function generateBanner() {
  try {
    console.log('Generating centered MotorLink ad banners (UHD + standard + mobile)...');

    ensureDirectory(ADS_DIR);

    const desktopSvg = getDesktopSvg();
    const mobileSvg = getMobileSvg();

    // UHD master export for high-density use cases.
    await writePng(desktopSvg, OUTPUT_UHD_PATH, UHD_WIDTH, UHD_HEIGHT);

    // Social standard desktop banner.
    await writePng(desktopSvg, OUTPUT_STANDARD_PATH, BASE_WIDTH, BASE_HEIGHT);

    // Mobile-first portrait banner.
    await writePng(mobileSvg, OUTPUT_MOBILE_PATH, MOBILE_WIDTH, MOBILE_HEIGHT);

    const socialPack = await generateSocialPack(desktopSvg, mobileSvg);

    console.log('Banner outputs created successfully:');
    console.log(`- ${describeFile(OUTPUT_STANDARD_PATH)} (${BASE_WIDTH}x${BASE_HEIGHT})`);
    console.log(`- ${describeFile(OUTPUT_UHD_PATH)} (${UHD_WIDTH}x${UHD_HEIGHT})`);
    console.log(`- ${describeFile(OUTPUT_MOBILE_PATH)} (${MOBILE_WIDTH}x${MOBILE_HEIGHT})`);
    console.log(`- Social ad pack folder: ${socialPack.directory}`);
    console.log(`- Social usage guide: ${socialPack.guidePath}`);
  } catch (error) {
    console.error('Error generating banners:', error);
    process.exit(1);
  }
}

generateBanner();
